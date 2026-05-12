#!/usr/bin/env bash
set -euo pipefail

DOMAIN=""
EMAIL=""
ADMIN_LOGIN="admin"
ADMIN_PASSWORD=""
INSTALL_DIR="/opt/sesame-portal/current"
STATE_DIR="/var/lib/sesame-portal"
NO_ACME=0

usage() {
  cat <<'USAGE'
Usage:
  sudo bash scripts/install.sh --domain portal.example.com --email admin@example.com --admin-login admin --admin-password 'secret123'

Options:
  --domain <name>             Public domain for nginx server_name.
  --email <email>             ACME email for Let's Encrypt.
  --admin-login <login>       Initial admin login, default admin.
  --admin-password <password> Initial admin password, minimum 6 chars.
  --install-dir <path>        Install target, default /opt/sesame-portal/current.
  --state-dir <path>          State directory, default /var/lib/sesame-portal.
  --no-acme                   Configure HTTP only and skip certbot.
USAGE
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --domain) DOMAIN="${2:-}"; shift 2 ;;
    --email) EMAIL="${2:-}"; shift 2 ;;
    --admin-login) ADMIN_LOGIN="${2:-}"; shift 2 ;;
    --admin-password) ADMIN_PASSWORD="${2:-}"; shift 2 ;;
    --install-dir) INSTALL_DIR="${2:-}"; shift 2 ;;
    --state-dir) STATE_DIR="${2:-}"; shift 2 ;;
    --no-acme) NO_ACME=1; shift ;;
    -h|--help) usage; exit 0 ;;
    *) echo "Unknown option: $1" >&2; usage; exit 2 ;;
  esac
done

if [[ "$(id -u)" != "0" ]]; then
  echo "installer must run as root" >&2
  exit 1
fi

if [[ -z "$DOMAIN" ]]; then
  echo "--domain is required" >&2
  exit 2
fi

if [[ "$NO_ACME" != "1" && -z "$EMAIL" ]]; then
  echo "--email is required unless --no-acme is used" >&2
  exit 2
fi

if [[ ${#ADMIN_PASSWORD} -lt 6 ]]; then
  echo "--admin-password must be at least 6 characters" >&2
  exit 2
fi

SOURCE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHP_BIN="${PHP_BIN:-$(command -v php || true)}"

install_packages() {
  if command -v apt-get >/dev/null 2>&1; then
    apt-get update
    DEBIAN_FRONTEND=noninteractive apt-get install -y \
      nginx certbot php-fpm php-cli php-sqlite3 php-curl php-mbstring sqlite3 rsync
  fi
}

detect_php_fpm_socket() {
  local socket
  socket="$(find /run/php -maxdepth 1 -type s -name 'php*-fpm.sock' 2>/dev/null | sort -V | tail -n 1 || true)"
  if [[ -n "$socket" ]]; then
    echo "unix:$socket"
    return
  fi
  echo "127.0.0.1:9000"
}

write_config() {
  local secret
  secret="$(openssl rand -hex 32)"
  install -d -m 0750 -o www-data -g www-data "$STATE_DIR"
  cat > "$STATE_DIR/config.php" <<PHP
<?php
return [
    'state_dir' => '$STATE_DIR',
    'db_path' => '$STATE_DIR/portal.sqlite',
    'app_secret' => '$secret',
    'timezone' => 'UTC',
    'base_url' => 'https://$DOMAIN',
    'auth_backend_path' => '/api/sesamedvr/auth',
];
PHP
  chown www-data:www-data "$STATE_DIR/config.php"
  chmod 0640 "$STATE_DIR/config.php"
}

copy_release() {
  install -d -m 0755 "$(dirname "$INSTALL_DIR")"
  install -d -m 0755 "$INSTALL_DIR"
  rsync -a --delete \
    --exclude '.git/' \
    --exclude 'var/' \
    "$SOURCE_DIR/" "$INSTALL_DIR/"
  chmod +x "$INSTALL_DIR/bin/portal" "$INSTALL_DIR/scripts/install.sh"
}

run_portal_cli() {
  sudo -u www-data \
    SESAME_PORTAL_STATE_DIR="$STATE_DIR" \
    SESAME_PORTAL_CONFIG="$STATE_DIR/config.php" \
    php "$INSTALL_DIR/bin/portal" "$@"
}

write_nginx_http() {
  local fpm="$1"
  cat > /etc/nginx/sites-available/sesame-portal.conf <<NGINX
server {
    listen 80;
    listen [::]:80;
    server_name $DOMAIN;

    root $INSTALL_DIR/public;
    index index.php;

    location / {
        try_files \$uri /index.php?\$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass $fpm;
        fastcgi_param SESAME_PORTAL_STATE_DIR "$STATE_DIR";
        fastcgi_param SESAME_PORTAL_CONFIG "$STATE_DIR/config.php";
    }
}
NGINX
  ln -sfn /etc/nginx/sites-available/sesame-portal.conf /etc/nginx/sites-enabled/sesame-portal.conf
}

write_nginx_https() {
  local fpm="$1"
  cat > /etc/nginx/sites-available/sesame-portal.conf <<NGINX
server {
    listen 80;
    listen [::]:80;
    server_name $DOMAIN;
    return 301 https://\$host\$request_uri;
}

server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name $DOMAIN;

    ssl_certificate /etc/letsencrypt/live/$DOMAIN/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/$DOMAIN/privkey.pem;

    root $INSTALL_DIR/public;
    index index.php;

    location / {
        try_files \$uri /index.php?\$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass $fpm;
        fastcgi_param HTTPS on;
        fastcgi_param SESAME_PORTAL_STATE_DIR "$STATE_DIR";
        fastcgi_param SESAME_PORTAL_CONFIG "$STATE_DIR/config.php";
    }
}
NGINX
}

write_cron() {
  cat > /etc/cron.d/sesame-portal <<CRON
SHELL=/bin/sh
PATH=/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin

5 0 * * * www-data SESAME_PORTAL_STATE_DIR=$STATE_DIR SESAME_PORTAL_CONFIG=$STATE_DIR/config.php php $INSTALL_DIR/bin/portal rotate-tokens >/dev/null 2>&1
CRON
}

main() {
  install_packages
  PHP_BIN="${PHP_BIN:-$(command -v php || true)}"
  if [[ -z "$PHP_BIN" ]]; then
    echo "php not found" >&2
    exit 1
  fi

  copy_release
  write_config
  run_portal_cli migrate
  run_portal_cli create-admin "$ADMIN_LOGIN" "$ADMIN_PASSWORD"
  write_cron

  local fpm
  fpm="$(detect_php_fpm_socket)"
  write_nginx_http "$fpm"
  nginx -t
  systemctl reload nginx || systemctl restart nginx

  if [[ "$NO_ACME" != "1" ]]; then
    certbot certonly --webroot -w "$INSTALL_DIR/public" -d "$DOMAIN" --agree-tos -m "$EMAIL" --non-interactive
    write_nginx_https "$fpm"
    nginx -t
    systemctl reload nginx || systemctl restart nginx
  fi

  echo "SesamePortal installed: https://$DOMAIN"
}

main "$@"
