# Installation Guide

## 1. Install the required packages:

Follow the instructions for your operating system.

### Ubuntu 22.04 / 24.04

```bash
apt update
apt install -y curl software-properties-common ufw

add-apt-repository -y ppa:ondrej/php
add-apt-repository -y ppa:ondrej/nginx
apt update

apt install -y bzip2 composer git net-tools php8.3 php8.3-bcmath php8.3-bz2 php8.3-cli php8.3-common php8.3-curl php8.3-ds php8.3-fpm php8.3-gd php8.3-gmp php8.3-igbinary php8.3-imap php8.3-intl php8.3-mbstring php8.3-opcache php8.3-readline php8.3-redis php8.3-soap php8.3-swoole php8.3-uuid php8.3-xml php8.3-zip unzip ufw wget whois
```

### Debian 12 / 13

```bash
apt update
apt install -y ca-certificates curl gnupg lsb-release ufw

# PHP (SURY repo)
curl -fsSL https://packages.sury.org/php/apt.gpg \
 | gpg --dearmor -o /usr/share/keyrings/sury-php.gpg

echo "deb [signed-by=/usr/share/keyrings/sury-php.gpg] https://packages.sury.org/php/ $(lsb_release -sc) main" \
 > /etc/apt/sources.list.d/sury-php.list

# Nginx (official repo)
curl -fsSL https://nginx.org/keys/nginx_signing.key \
 | gpg --dearmor -o /usr/share/keyrings/nginx.gpg

echo "deb [signed-by=/usr/share/keyrings/nginx.gpg] http://nginx.org/packages/mainline/debian $(lsb_release -sc) nginx" \
 > /etc/apt/sources.list.d/nginx.list

apt update

apt install -y bzip2 composer git net-tools php8.3 php8.3-bcmath php8.3-bz2 php8.3-cli php8.3-common php8.3-curl php8.3-ds php8.3-fpm php8.3-gd php8.3-gmp php8.3-igbinary php8.3-imap php8.3-intl php8.3-mbstring php8.3-opcache php8.3-readline php8.3-redis php8.3-soap php8.3-swoole php8.3-uuid php8.3-xml php8.3-zip unzip ufw wget whois
```

### Configure PHP Settings:

1. Open the PHP-FPM configuration file:

```bash
nano /etc/php/8.3/fpm/php.ini
```

Add or uncomment the following session security settings:

```ini
session.cookie_secure = 1
session.cookie_httponly = 1
session.cookie_samesite = "Strict"
```

2. Open the OPCache configuration file:

```bash
nano /etc/php/8.3/mods-available/opcache.ini
```

Verify or add the following OPCache and JIT settings:

```ini
opcache.enable=1
opcache.enable_cli=1
opcache.jit=1255
opcache.jit_buffer_size=100M
```

3. Restart PHP-FPM to apply the changes:

```bash
systemctl restart php8.3-fpm
```

## 2. Install and Configure Caddy and Adminer:

1. Execute the following commands:

```bash
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' -o caddy-stable.gpg.key
gpg --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg caddy-stable.gpg.key
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' | tee /etc/apt/sources.list.d/caddy-stable.list
apt update
apt install -y caddy
```

2. Edit `/etc/caddy/Caddyfile` and place the following content:

```bash
loom.com {
    bind YOUR_IPV4_ADDRESS YOUR_IPV6_ADDRESS
    root * /var/www/loom/public
    php_fastcgi unix//run/php/php8.3-fpm.sock
    encode zstd gzip
    file_server
    tls your-email@example.com
    header -Server
    log {
        output file /var/log/loom/caddy.log
    }
    # Adminer Configuration
    route /adminer.php* {
        root * /usr/share/adminer
        php_fastcgi unix//run/php/php8.3-fpm.sock
    }
    header * {
        Referrer-Policy "same-origin"
        Strict-Transport-Security max-age=31536000;
        X-Content-Type-Options nosniff
        X-Frame-Options DENY
        X-XSS-Protection "1; mode=block"
        Content-Security-Policy: default-src 'none'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; img-src https:; font-src 'self'; style-src 'self' 'unsafe-inline' https://rsms.me; script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/; form-action 'self'; worker-src 'none'; frame-src 'none';
        Feature-Policy "accelerometer 'none'; autoplay 'none'; camera 'none'; encrypted-media 'none'; fullscreen 'self'; geolocation 'none'; gyroscope 'none'; magnetometer 'none'; microphone 'none'; midi 'none'; payment 'none'; picture-in-picture 'self'; usb 'none';"
        Permissions-Policy: accelerometer=(), autoplay=(), camera=(), encrypted-media=(), fullscreen=(self), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), midi=(), payment=(), picture-in-picture=(self), usb=();
    }
}
```

Activate and reload Caddy:

```bash
mkdir -p /var/log/loom
chown caddy:caddy /var/log/loom
systemctl enable caddy
systemctl restart caddy
```

3. Install Adminer

```bash
mkdir /usr/share/adminer
wget "http://www.adminer.org/latest.php" -O /usr/share/adminer/latest.php
ln -s /usr/share/adminer/latest.php /usr/share/adminer/adminer.php
```

## 3. Install MariaDB:

```bash
curl -o /etc/apt/keyrings/mariadb-keyring.pgp 'https://mariadb.org/mariadb_release_signing_key.pgp'
```

Create `/etc/apt/sources.list.d/mariadb.sources` according to your system.

### Ubuntu 22.04 (Jammy)

```ini
X-Repolib-Name: MariaDB
Types: deb
URIs: https://mirror.nextlayer.at/mariadb/repo/11.rolling/ubuntu
Suites: jammy
Components: main
Signed-By: /etc/apt/keyrings/mariadb-keyring.pgp
```

### Ubuntu 24.04 (Noble)

```ini
X-Repolib-Name: MariaDB
Types: deb
URIs: https://mirror.nextlayer.at/mariadb/repo/11.rolling/ubuntu
Suites: noble
Components: main
Signed-By: /etc/apt/keyrings/mariadb-keyring.pgp
```

### Debian 12 (Bookworm)

```ini
X-Repolib-Name: MariaDB
Types: deb
URIs: https://mirror.nextlayer.at/mariadb/repo/11.rolling/debian
Suites: bookworm
Components: main
Signed-By: /etc/apt/keyrings/mariadb-keyring.pgp
```

### Debian 13 (Trixie)

```ini
X-Repolib-Name: MariaDB
Types: deb
URIs: https://mirror.nextlayer.at/mariadb/repo/11.rolling/debian
Suites: trixie
Components: main
Signed-By: /etc/apt/keyrings/mariadb-keyring.pgp
```

## 4. Configure MariaDB:

1. Execute the following commands:

```bash
apt update
apt install -y mariadb-client mariadb-server php8.3-mysql
mysql_secure_installation
```

2. Access MariaDB:

```bash
mariadb -u root -p
```

3. Execute the following queries:

```bash
CREATE DATABASE loom;
CREATE USER 'loom'@'localhost' IDENTIFIED BY 'RANDOM_STRONG_PASSWORD';
GRANT ALL PRIVILEGES ON loom.* TO 'loom'@'localhost';
FLUSH PRIVILEGES;
```

Replace `loom` with your desired username and `RANDOM_STRONG_PASSWORD` with a secure password of your choice.

[Tune your MariaDB](https://github.com/major/MySQLTuner-perl)

## 5. Download Loom:

```bash
git clone https://github.com/getnamingo/loom.git /var/www/loom
```

## 6. Setup Loom:

```bash
cd /var/www/loom
composer update
cp env-sample .env
chmod -R 775 logs cache
chown -R www-data:www-data logs cache
```

Configure your `.env` with database and app settings, and set your admin credentials in `bin/create-admin-user.php`.

## 7. Install Database and Create Administrator:

```bash
php bin/install-db.php
php bin/create-admin-user.php
```