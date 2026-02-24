#!/usr/bin/env bash
set -euo pipefail

# ---------- Helpers ----------
log() { printf "\n\033[1;32m[%s]\033[0m %s\n" "$(date +%H:%M:%S)" "$*"; }
warn() { printf "\n\033[1;33m[WARN]\033[0m %s\n" "$*"; }
err() { printf "\n\033[1;31m[ERR]\033[0m %s\n" "$*" >&2; }
die() { err "$*"; exit 1; }

require_root() {
  if [[ "${EUID:-$(id -u)}" -ne 0 ]]; then
    die "Please run as root (sudo bash $0)."
  fi
}

detect_os() {
  . /etc/os-release
  OS_ID="$ID"            # ubuntu/debian
  OS_VER="$VERSION_ID"   # e.g. 22.04, 24.04, 12, 13
  OS_CODENAME="${VERSION_CODENAME:-}"
  log "Detected: $PRETTY_NAME"
}

# Return best-guess A/AAAA for bind (optional)
detect_ips() {
  IPV4=$(hostname -I | awk '{print $1}' || true)
  IPV6=$(ip -6 addr show scope global 2>/dev/null | awk '/inet6/{print $2}' | cut -d/ -f1 | head -n1 || true)
}

prompt() {
  local var="$1"; local msg="$2"; local def="${3-}"; local secret="${4-}"
  local val
  while true; do
    if [[ -n "$def" ]]; then
      if [[ "$secret" == "secret" ]]; then
        read -r -s -p "$msg [$def]: " val; echo
      else
        read -r -p "$msg [$def]: " val
      fi
      val="${val:-$def}"
    else
      if [[ "$secret" == "secret" ]]; then
        read -r -s -p "$msg: " val; echo
      else
        read -r -p "$msg: " val
      fi
    fi
    [[ -n "$val" ]] && break || warn "Value cannot be empty."
  done
  eval "$var=\"\$val\""
}

install_php_repo() {
  if [[ "$OS_ID" == "ubuntu" ]]; then
    apt update
    apt install -y curl software-properties-common ca-certificates gnupg
    add-apt-repository -y ppa:ondrej/php
    add-apt-repository -y ppa:ondrej/nginx
  elif [[ "$OS_ID" == "debian" ]]; then
    apt update
    apt install -y ca-certificates curl gnupg lsb-release

    # PHP (SURY)
    curl -fsSL https://packages.sury.org/php/apt.gpg \
      | gpg --dearmor -o /usr/share/keyrings/sury-php.gpg
    echo "deb [signed-by=/usr/share/keyrings/sury-php.gpg] https://packages.sury.org/php/ $(lsb_release -sc) main" \
      > /etc/apt/sources.list.d/sury-php.list

    # Nginx mainline (official)
    curl -fsSL https://nginx.org/keys/nginx_signing.key \
      | gpg --dearmor -o /usr/share/keyrings/nginx.gpg
    echo "deb [signed-by=/usr/share/keyrings/nginx.gpg] http://nginx.org/packages/mainline/debian $(lsb_release -sc) nginx" \
      > /etc/apt/sources.list.d/nginx.list
  else
    echo "Unsupported OS: ${OS_ID:-unknown} ${VER:-unknown}"
    exit 1
  fi
}

# Function to ensure a setting is present, uncommented, and correctly set
set_php_ini_value() {
    local ini_file=$1
    local key=$2
    local value=$3

    # Escape slashes for sed compatibility
    local escaped_value
    escaped_value=$(printf '%s\n' "$value" | sed 's/[\/&]/\\&/g')

    if grep -Eq "^\s*[;#]?\s*${key}\s*=" "$ini_file"; then
        # Update the existing line, uncomment it and set correct value
        sed -i -E "s|^\s*[;#]?\s*(${key})\s*=.*|\1 = ${escaped_value}|" "$ini_file"
    else
        # Add new line if key doesn't exist
        echo "${key} = ${value}" >> "$ini_file"
    fi
}

# ---------- Pre-flight ----------
require_root
detect_os
detect_ips

log "Updating apt index and base tools…"
export DEBIAN_FRONTEND=noninteractive
apt-get update -y
apt-get install -y curl wget ca-certificates gnupg lsb-release software-properties-common ufw git unzip bzip2 net-tools whois

# ---------- Ask user inputs ----------
echo
log "Basic configuration"

DEFAULT_HOST="loom.local"
prompt HOSTNAME "Enter the domain where the system will live (e.g., example.com or cp.example.com)" "$DEFAULT_HOST"
prompt TLS_EMAIL "Enter email for Caddy TLS/Cert notifications" "admin@$HOSTNAME"
prompt INSTALL_PATH "Install path for Loom" "/var/www/loom"

# DB choice
echo
echo "Choose database backend:"
select DB_BACKEND in "MariaDB" "PostgreSQL" "SQLite"; do
  case "$DB_BACKEND" in
    MariaDB|PostgreSQL|SQLite) break ;;
    *) echo "Invalid selection."; ;;
  esac
done

# DB credentials (used unless SQLite)
if [[ "$DB_BACKEND" != "SQLite" ]]; then
  prompt DB_NAME "Choose a database name: " "loom"
  prompt DB_USER "Choose a database username: " "loom"
  prompt DB_PASS "Choose a password for this user: " "" "secret"
fi

# Admin user for Loom
echo
log "Admin user for Loom"
prompt ADMIN_USER "Choose an admin email" "admin@example.com"
prompt ADMIN_PASS "Choose an admin password" "" "secret"

# Optional custom bind IPs for Caddy
USE_BIND="n"
if [[ -n "${IPV4:-}" || -n "${IPV6:-}" ]]; then
  echo
  echo "Detected IPs: IPv4=${IPV4:-none}, IPv6=${IPV6:-none}"
  read -r -p "Bind Caddy to these IPs? (y/N): " USE_BIND
  USE_BIND="${USE_BIND:-n}"
fi
if [[ "$USE_BIND" =~ ^[Yy]$ ]]; then
  CADDY_BIND_LINE="    bind ${IPV4:-} ${IPV6:-}"
else
  CADDY_BIND_LINE=""
fi

# ---------- PHP 8.3 repos ----------
log "Configuring PHP 8.3 repository…"
# Install necessary packages
install_php_repo
apt update

log "Installing PHP"
apt install -y composer php8.3 php8.3-cli php8.3-common php8.3-fpm php8.3-bcmath php8.3-bz2 php8.3-curl php8.3-ds php8.3-gd php8.3-gmp php8.3-igbinary php8.3-imap php8.3-intl php8.3-mbstring php8.3-opcache php8.3-readline php8.3-redis php8.3-soap php8.3-swoole php8.3-uuid php8.3-xml php8.3-zip ufw git unzip bzip2 net-tools whois

# Update php.ini (FPM)
set_php_ini_value "/etc/php/8.3/fpm/php.ini" "session.cookie_secure" "1"
set_php_ini_value "/etc/php/8.3/fpm/php.ini" "session.cookie_httponly" "1"
set_php_ini_value "/etc/php/8.3/fpm/php.ini" "session.cookie_samesite" "\"Strict\""
set_php_ini_value "/etc/php/8.3/fpm/php.ini" "memory_limit" "$PHP_MEMORY_LIMIT"

set_php_ini_value "/etc/php/8.3/mods-available/opcache.ini" "opcache.enable" "1"
set_php_ini_value "/etc/php/8.3/mods-available/opcache.ini" "opcache.enable_cli" "1"
set_php_ini_value "/etc/php/8.3/mods-available/opcache.ini" "opcache.jit_buffer_size" "100M"
set_php_ini_value "/etc/php/8.3/mods-available/opcache.ini" "opcache.jit" "1255"
set_php_ini_value "/etc/php/8.3/mods-available/opcache.ini" "opcache.memory_consumption" "128"
set_php_ini_value "/etc/php/8.3/mods-available/opcache.ini" "opcache.interned_strings_buffer" "16"
set_php_ini_value "/etc/php/8.3/mods-available/opcache.ini" "opcache.max_accelerated_files" "10000"
set_php_ini_value "/etc/php/8.3/mods-available/opcache.ini" "opcache.validate_timestamps" "0"

systemctl restart php8.3-fpm

# ---------- Caddy repo & install ----------
log "Installing Caddy…"
apt install -y debian-keyring debian-archive-keyring apt-transport-https curl
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' | gpg --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' | tee /etc/apt/sources.list.d/caddy-stable.list
chmod o+r /usr/share/keyrings/caddy-stable-archive-keyring.gpg
chmod o+r /etc/apt/sources.list.d/caddy-stable.list
apt-get update -y
apt-get install -y caddy
# ---------- Adminer (randomized path) ----------
log "Installing Adminer…"
mkdir -p /usr/share/adminer
wget -q "https://www.adminer.org/latest.php" -O /usr/share/adminer/latest.php
ADMINER_SLUG="adminer-$(cut -d- -f1 </proc/sys/kernel/random/uuid).php"
ln -sf /usr/share/adminer/latest.php "/usr/share/adminer/${ADMINER_SLUG}"

# ---------- Database setup ----------
case "$DB_BACKEND" in
  MariaDB)
    log "Configuring MariaDB repository…"
mkdir -p /etc/apt/keyrings
curl -fsSL 'https://mariadb.org/mariadb_release_signing_key.pgp' -o /etc/apt/keyrings/mariadb-keyring.pgp

MARIADB_URI=""
MARIADB_SUITE=""

if [[ "${OS_ID}" == "ubuntu" ]]; then
  MARIADB_URI="https://mirror.nextlayer.at/mariadb/repo/11.rolling/ubuntu"
  if [[ "${VER}" == "22.04" ]]; then
    MARIADB_SUITE="jammy"
  elif [[ "${VER}" == "24.04" ]]; then
    MARIADB_SUITE="noble"
  else
    echo "Unsupported Ubuntu version for MariaDB repo: ${VER}"
    exit 1
  fi
elif [[ "${OS_ID}" == "debian" ]]; then
  MARIADB_URI="https://mirror.nextlayer.at/mariadb/repo/11.rolling/debian"
  if [[ "${VER}" == "12" ]]; then
    MARIADB_SUITE="bookworm"
  elif [[ "${VER}" == "13" ]]; then
    MARIADB_SUITE="trixie"
  else
    echo "Unsupported Debian version for MariaDB repo: ${VER}"
    exit 1
  fi
else
  echo "Unsupported OS for MariaDB repo: ${OS_ID:-unknown} ${VER:-unknown}"
  exit 1
fi

cat > /etc/apt/sources.list.d/mariadb.sources <<EOF
X-Repolib-Name: MariaDB
Types: deb
URIs: ${MARIADB_URI}
Suites: ${MARIADB_SUITE}
Components: main
Signed-By: /etc/apt/keyrings/mariadb-keyring.pgp
EOF

apt update -y
apt install -y mariadb-server mariadb-client php8.3-mysql

# Secure MariaDB installation
mysql_secure_installation

# MariaDB configuration
mariadb --user=root <<SQL
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
SQL
    ;;

  PostgreSQL)
    log "Installing PostgreSQL…"
    apt-get install -y postgresql php8.3-pgsql
    systemctl enable --now postgresql

    log "Creating database and role…"
    sudo -u postgres psql -v ON_ERROR_STOP=1 \
      -v dbuser="$DB_USER" -v dbpass="$DB_PASS" -v dbname="$DB_NAME" <<'SQL'
-- Create role if missing
SELECT format('CREATE ROLE %I LOGIN PASSWORD %L', :'dbuser', :'dbpass')
WHERE NOT EXISTS (
  SELECT 1 FROM pg_catalog.pg_roles WHERE rolname = :'dbuser'
)
\gexec

-- Create database if missing
SELECT format('CREATE DATABASE %I OWNER %I', :'dbname', :'dbuser')
WHERE NOT EXISTS (
  SELECT 1 FROM pg_database WHERE datname = :'dbname'
)
\gexec

-- Grant privileges (idempotent)
GRANT ALL PRIVILEGES ON DATABASE :"dbname" TO :"dbuser";
SQL
    ;;

  SQLite)
    log "Using SQLite (no server install)."
    apt-get install -y sqlite3 php8.3-sqlite3
    ;;
esac

# ---------- Create Loom project ----------
log "Creating Loom project in $INSTALL_PATH …"
mkdir -p "$INSTALL_PATH"
if [[ -z "$(ls -A "$INSTALL_PATH")" ]]; then
  git clone https://github.com/getnamingo/loom.git "$INSTALL_PATH"
else
  warn "$INSTALL_PATH is not empty. Skipping git clone."
fi

# ---------- .env configuration ----------
log "Configuring .env …"
cd "$INSTALL_PATH"
if [[ ! -f ".env" ]]; then
  cp env-sample .env
fi
sed -i "s|^APP_URL=.*|APP_URL=https://${HOSTNAME//\//\\/}|" .env

# DB DSN/env
case "$DB_BACKEND" in
  MariaDB)
    sed -i "s/^DB_DRIVER=.*/DB_DRIVER=mysql/" .env
    sed -i "s/^DB_HOST=.*/DB_HOST=127.0.0.1/" .env
    sed -i "s/^DB_PORT=.*/DB_PORT=3306/" .env
    sed -i "s/^DB_DATABASE=.*/DB_DATABASE=${DB_NAME}/" .env
    ESCAPED_DB_USER=$(printf '%s\n' "$DB_USER" | sed -e 's/[&/\]/\\&/g')
    ESCAPED_DB_PASS=$(printf '%s\n' "$DB_PASS" | sed -e 's/[&/\]/\\&/g')
    sed -i "s/^DB_USERNAME=.*/DB_USERNAME=\"$ESCAPED_DB_USER\"/" .env
    sed -i "s/^DB_PASSWORD=.*/DB_PASSWORD=\"$ESCAPED_DB_PASS\"/" .env
    ;;
  PostgreSQL)
    sed -i "s/^DB_DRIVER=.*/DB_DRIVER=pgsql/" .env
    sed -i "s/^DB_HOST=.*/DB_HOST=127.0.0.1/" .env
    sed -i "s/^DB_PORT=.*/DB_PORT=5432/" .env
    sed -i "s/^DB_DATABASE=.*/DB_DATABASE=${DB_NAME}/" .env
    ESCAPED_DB_USER=$(printf '%s\n' "$DB_USER" | sed -e 's/[&/\]/\\&/g')
    ESCAPED_DB_PASS=$(printf '%s\n' "$DB_PASS" | sed -e 's/[&/\]/\\&/g')
    sed -i "s/^DB_USERNAME=.*/DB_USERNAME=\"$ESCAPED_DB_USER\"/" .env
    sed -i "s/^DB_PASSWORD=.*/DB_PASSWORD=\"$ESCAPED_DB_PASS\"/" .env
    ;;
  SQLite)
    sed -i "s/^DB_DRIVER=.*/DB_DRIVER=sqlite/" .env
    sed -i "s|^DB_DATABASE=.*|DB_DATABASE=${INSTALL_PATH}/storage/loom.sqlite|" .env
    install -d -m 0775 -o www-data -g www-data "${INSTALL_PATH}/storage"
    install -m 0664 -o www-data -g www-data /dev/null "${INSTALL_PATH}/storage/loom.sqlite"
    ;;
esac

# ---------- Permissions ----------
log "Setting permissions…"
mkdir -p logs cache /var/log/loom
chown -R www-data:www-data logs cache /var/log/loom
chmod -R 775 logs cache
touch /var/log/loom/caddy.log
chown caddy:caddy /var/log/loom/caddy.log
chmod 664 /var/log/loom/caddy.log

COMPOSER_ALLOW_SUPERUSER=1 composer update --no-interaction --quiet

# ---------- Install DB schema ----------
log "Running Loom DB installer…"
php bin/install-db.php

# ---------- Create admin user (best effort) ----------
log "Creating admin user (attempting non-interactive)…"

if php -v >/dev/null 2>&1; then
  set +e

  # Replace sample variables directly in the original script
  sed -i \
    -e "s|\(\$email\s*=\s*\).*|\1'${ADMIN_USER}';|" \
    -e "s|\(\$newPW\s*=\s*\).*|\1'${ADMIN_PASS}';|" \
    bin/create-admin-user.php

  php bin/create-admin-user.php >/tmp/loom-admin.log 2>&1
  CREATE_EXIT=$?
  set -e

  if [[ "$CREATE_EXIT" -ne 0 ]]; then
    warn "Automatic admin creation may have failed. Check /tmp/loom-admin.log"
    warn "If needed, run: php bin/create-admin-user.php  (and enter credentials manually)"
  fi
else
  warn "PHP CLI not found when creating admin (unexpected)."
fi

# ---------- Caddyfile ----------
log "Writing Caddyfile for $HOSTNAME …"
cat > /etc/caddy/Caddyfile <<EOF
$HOSTNAME {
$CADDY_BIND_LINE
    root * $INSTALL_PATH/public
    php_fastcgi unix//run/php/php8.3-fpm.sock
    encode zstd gzip
    file_server
    tls $TLS_EMAIL
    header -Server
    log {
        output file /var/log/loom/caddy.log
    }
    # Adminer (randomized path)
    route /${ADMINER_SLUG}* {
        root * /usr/share/adminer
        php_fastcgi unix//run/php/php8.3-fpm.sock
    }
    header * {
        Referrer-Policy "same-origin"
        Strict-Transport-Security max-age=31536000;
        X-Content-Type-Options nosniff
        X-Frame-Options DENY
        X-XSS-Protection "1; mode=block"
        Content-Security-Policy: default-src 'none'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; img-src https: data:; font-src 'self' data:; style-src 'self' 'unsafe-inline' https://rsms.me; script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/; form-action 'self'; worker-src 'none'; frame-src 'none';
        Permissions-Policy: accelerometer=(), autoplay=(), camera=(), encrypted-media=(), fullscreen=(self), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), midi=(), payment=(), picture-in-picture=(self), usb=();
    }
}
EOF

systemctl enable caddy
systemctl restart caddy

# ---------- Firewall ----------
log "Configuring UFW…"
ufw allow OpenSSH >/dev/null 2>&1 || true
ufw allow 80,443/tcp >/dev/null 2>&1 || true
yes | ufw enable >/dev/null 2>&1 || true
ufw status || true

# ---------- Summary ----------
cat <<SUM

============================================================
✅ Installation complete!

• App path:          $INSTALL_PATH
• Hostname:          https://$HOSTNAME
• PHP-FPM:           php8.3-fpm (running)
• Web server:        Caddy (running)
• Adminer URL:       https://$HOSTNAME/${ADMINER_SLUG}

• Database backend:  $DB_BACKEND
$( [[ "$DB_BACKEND" != "SQLite" ]] && echo "• DB Name/User:     $DB_NAME / $DB_USER" )
$( [[ "$DB_BACKEND" == "MariaDB" ]] && echo "• MySQL Tuning:     Run MySQLTuner later: perl mysqltuner.pl" )

• Admin user:        $ADMIN_USER  (created best-effort)
  If admin creation failed, run inside $INSTALL_PATH:
     php bin/create-admin-user.php

Pro tip: Add your domain's A/AAAA records to point at this server
and wait for DNS to propagate before first TLS issuance.

Logs:
  - Caddy:           /var/log/loom/caddy.log
  - Loom (app):      $INSTALL_PATH/logs

Enjoy! 🚀
============================================================
SUM