#!/bin/bash
set -Eeuo pipefail
trap 'echo "Error on line $LINENO"; exit 1' ERR
umask 022

# Ensure the script is run as root
if [[ $EUID -ne 0 ]]; then
    echo "Error: This upgrade script must be run as root or with sudo." >&2
    exit 1
fi

# Prompt the user for confirmation
echo "This will upgrade Loom"
echo "Make sure you have a backup of the database and the Loom directory."
read -p "Are you sure you want to proceed? (y/n): " confirm

# Check user input
if [[ "$confirm" != "y" ]]; then
    echo "Upgrade aborted."
    exit 0
fi

# Prompt for Loom path (default: /var/www/loom)
read -p "Enter path to Loom [default: /var/www/loom]: " loom_path
loom_path=${loom_path:-/var/www/loom}
echo "Using Loom path: $loom_path"

# Create backup directory
backup_dir="/opt/backup"
mkdir -p "$backup_dir"

# Backup directories
echo "Creating backups..."
backup_ts=$(date +%F_%H%M%S)
tar -czf "$backup_dir/loom_backup_${backup_ts}.tar.gz" \
    -C "$(dirname "$loom_path")" "$(basename "$loom_path")"

# Database credentials from .env in Loom path
env_file="$loom_path/.env"
db_driver=$(grep -E '^DB_DRIVER=' "$env_file" | cut -d '=' -f2-)
db_host=$(grep -E '^DB_HOST=' "$env_file" | cut -d '=' -f2-)
db_name=$(grep -E '^DB_DATABASE=' "$env_file" | cut -d '=' -f2-)
db_user=$(grep -E '^DB_USERNAME=' "$env_file" | cut -d '=' -f2-)
db_pass=$(grep -E '^DB_PASSWORD=' "$env_file" | cut -d '=' -f2-)

# List of databases to back up
databases=("$db_name")

# Backup specific databases
for db_name in "${databases[@]}"; do
    echo "Backing up database $db_name (driver: $db_driver)..."

    case "$db_driver" in
        mysql|mariadb|"")
            sql_backup_file="$backup_dir/db_${db_name}_backup_$(date +%F).sql"
            mariadb-dump -u"$db_user" -p"$db_pass" -h"$db_host" "$db_name" > "$sql_backup_file"

            echo "Compressing database backup $db_name..."
            tar -czf "${sql_backup_file}.tar.gz" -C "$backup_dir" "$(basename "$sql_backup_file")"
            rm "$sql_backup_file"
            ;;

        pgsql)
            sql_backup_file="$backup_dir/db_${db_name}_backup_$(date +%F).sql"
            PGPASSWORD="$db_pass" pg_dump -h "$db_host" -U "$db_user" -d "$db_name" > "$sql_backup_file"

            echo "Compressing database backup $db_name..."
            tar -czf "${sql_backup_file}.tar.gz" -C "$backup_dir" "$(basename "$sql_backup_file")"
            rm "$sql_backup_file"
            ;;

        sqlite)
            # db_name is a path
            sqlite_src="$db_name"
            sqlite_copy="$backup_dir/db_sqlite_backup_$(date +%F).sqlite"

            cp -a "$sqlite_src" "$sqlite_copy"

            echo "Compressing sqlite backup..."
            tar -czf "${sqlite_copy}.tar.gz" -C "$backup_dir" "$(basename "$sqlite_copy")"
            rm "$sqlite_copy"
            ;;

        *)
            echo "ERROR: Unsupported DB_DRIVER='$db_driver' (supported: mysql, mariadb, pgsql, sqlite)"
            exit 1
            ;;
    esac
done

# Idempotent transactions gateway migration
echo "Updating transactions schema..."
case "$db_driver" in
    mysql|mariadb|"")
        db_cmd=(mariadb -u"$db_user" -p"$db_pass" -h"$db_host" "$db_name")

        for definition in \
            "gateway VARCHAR(32) DEFAULT NULL AFTER currency" \
            "gateway_reference VARCHAR(128) DEFAULT NULL AFTER gateway"
        do
            column=${definition%% *}
            exists=$("${db_cmd[@]}" -Nse \
                "SELECT EXISTS(
                    SELECT 1 FROM information_schema.columns
                    WHERE table_schema = DATABASE()
                      AND table_name = 'transactions'
                      AND column_name = '$column'
                )")
            [[ "$exists" == "1" ]] ||
                "${db_cmd[@]}" -e "ALTER TABLE transactions ADD COLUMN $definition"
        done

        exists=$("${db_cmd[@]}" -Nse \
            "SELECT EXISTS(
                SELECT 1 FROM information_schema.statistics
                WHERE table_schema = DATABASE()
                  AND table_name = 'transactions'
                  AND index_name = 'transactions_gateway_reference_unique'
            )")
        [[ "$exists" == "1" ]] ||
            "${db_cmd[@]}" -e \
                "ALTER TABLE transactions ADD UNIQUE KEY transactions_gateway_reference_unique (gateway, gateway_reference)"
        ;;

    pgsql)
        PGPASSWORD="$db_pass" psql -X -v ON_ERROR_STOP=1 \
            -h "$db_host" -U "$db_user" -d "$db_name" -c '
                ALTER TABLE transactions
                    ADD COLUMN IF NOT EXISTS gateway VARCHAR(32) DEFAULT NULL;
                ALTER TABLE transactions
                    ADD COLUMN IF NOT EXISTS gateway_reference VARCHAR(128) DEFAULT NULL;
                CREATE UNIQUE INDEX IF NOT EXISTS transactions_gateway_reference_unique
                    ON transactions (gateway, gateway_reference);
            '
        ;;

    sqlite)
        DB_FILE="$db_name" php -r '
            $db = new PDO("sqlite:" . getenv("DB_FILE"), null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
            $columns = array_column(
                $db->query("PRAGMA table_info(transactions)")->fetchAll(PDO::FETCH_ASSOC),
                "name"
            );
            if (!in_array("gateway", $columns, true)) {
                $db->exec("ALTER TABLE transactions ADD COLUMN gateway VARCHAR(32) DEFAULT NULL");
            }
            if (!in_array("gateway_reference", $columns, true)) {
                $db->exec("ALTER TABLE transactions ADD COLUMN gateway_reference VARCHAR(128) DEFAULT NULL");
            }
            $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS transactions_gateway_reference_unique ON transactions (gateway, gateway_reference)");
        '
        ;;
esac

# Stop services
echo "Stopping services..."
systemctl stop caddy

# Clear cache
echo "Clearing cache..."
php "$loom_path/bin/clear-cache.php"
if systemctl list-units --type=service | grep -qE '^php[0-9.]*-fpm\.service'; then
  svc=$(systemctl list-units --type=service | awk '/php[0-9.]*-fpm\.service/ {print $1; exit}')
  systemctl restart "$svc"
elif command -v service >/dev/null && service php-fpm status >/dev/null 2>&1; then
  service php-fpm restart
fi

# Clone the new version of the repository
echo "Cloning new version from the repository..."
git clone https://github.com/getnamingo/loom /tmp/loom

# Copy files from the new version to the appropriate directories
echo "Copying files..."

# Function to copy files and maintain directory structure
copy_files() {
    src_dir=$1
    dest_dir=$2

    if [[ -d "$src_dir" ]]; then
        echo "Copying from $src_dir to $dest_dir..."
        cp -R "$src_dir/." "$dest_dir/"
    else
        echo "Source directory $src_dir does not exist. Skipping..."
    fi
}

# Copy specific directories
copy_files "/tmp/loom" "$loom_path"

# Run composer update in copied directories (excluding docs)
echo "Running composer update..."

composer_update() {
    dir=$1
    if [[ -d "$dir" ]]; then
        echo "Updating composer in $dir..."
        cd "$dir" || exit
        COMPOSER_ALLOW_SUPERUSER=1 composer update --no-interaction --quiet
    else
        echo "Directory $dir does not exist. Skipping composer update..."
    fi
}

# Update composer in relevant directories
composer_update "$loom_path"

wget "http://www.adminer.org/latest.php" -O /usr/share/adminer/latest.php

if ! grep -q "^IANA_ID=" "$env_file"; then
  cat >> "$env_file" <<EOF

# ICANN MoSAPI Configuration
IANA_ID=YOUR_IANA_ID
MOSAPI_USERNAME=YOUR_RR_USERNAME
MOSAPI_PASSWORD=YOUR_RR_PASSWORD
EOF
fi

# Start services
echo "Starting services..."
systemctl start caddy

# Check if services started successfully
if [[ $? -eq 0 ]]; then
    echo "Services started successfully. Deleting /tmp/loom..."
    rm -rf /tmp/loom
else
    echo "There was an issue starting the services. /tmp/loom will not be deleted."
fi

echo "Upgrade completed successfully."