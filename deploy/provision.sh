#!/usr/bin/env bash
# One-time server setup for Kiray on Ubuntu 24.04 (a VPS physically in Ethiopia,
# e.g. Ethio telecom TeleCloud, as Proclamation 1321/2024 requires).
#
# Usage (as root):  DOMAIN=api.example.et EMAIL=you@example.com REPO=https://github.com/ads-tres/Landlord-Rent-Maintenance- bash provision.sh
set -euo pipefail

: "${DOMAIN:?Set DOMAIN, e.g. DOMAIN=api.example.et}"
: "${EMAIL:?Set EMAIL for HTTPS certificate notices}"
REPO="${REPO:-https://github.com/ads-tres/Landlord-Rent-Maintenance-}"
BRANCH="${BRANCH:-main}"
APP_USER="${APP_USER:-kiray}"
APP_DIR="${APP_DIR:-/var/www/kiray}"
DB_NAME="${DB_NAME:-kiray}"
DB_USER="${DB_USER:-kiray}"
DB_PASS="${DB_PASS:-$(openssl rand -hex 24)}"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

echo "==> Packages"
export DEBIAN_FRONTEND=noninteractive
apt-get update -y
apt-get install -y --no-install-recommends \
  nginx postgresql postgresql-client supervisor git unzip curl ufw certbot python3-certbot-nginx \
  php8.3-fpm php8.3-cli php8.3-pgsql php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip \
  php8.3-gd php8.3-intl php8.3-bcmath php8.3-exif rsync
timedatectl set-timezone Africa/Addis_Ababa

if ! command -v composer >/dev/null; then
  echo "==> Composer"
  curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
  php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer
  rm /tmp/composer-setup.php
fi

echo "==> PHP limits (photo uploads up to 8 MB)"
for ini in /etc/php/8.3/fpm/php.ini /etc/php/8.3/cli/php.ini; do
  sed -i 's/^upload_max_filesize = .*/upload_max_filesize = 10M/; s/^post_max_size = .*/post_max_size = 40M/; s/^;\?date.timezone =.*/date.timezone = Africa\/Addis_Ababa/' "$ini"
done
sed -i 's/^;\?opcache.enable=.*/opcache.enable=1/' /etc/php/8.3/fpm/php.ini

echo "==> App user and folder"
id -u "$APP_USER" >/dev/null 2>&1 || adduser --system --group --home "/home/$APP_USER" --shell /bin/bash "$APP_USER"
usermod -aG "$APP_USER" www-data
mkdir -p "$APP_DIR"
chown "$APP_USER:$APP_USER" "$APP_DIR"

echo "==> PostgreSQL"
sudo -u postgres psql -tc "SELECT 1 FROM pg_roles WHERE rolname='$DB_USER'" | grep -q 1 \
  || sudo -u postgres psql -c "CREATE ROLE $DB_USER LOGIN PASSWORD '$DB_PASS';"
sudo -u postgres psql -tc "SELECT 1 FROM pg_database WHERE datname='$DB_NAME'" | grep -q 1 \
  || sudo -u postgres createdb -O "$DB_USER" "$DB_NAME"

echo "==> Code"
if [ ! -d "$APP_DIR/.git" ]; then
  sudo -u "$APP_USER" git clone --branch "$BRANCH" "$REPO" "$APP_DIR"
fi

if [ ! -f "$APP_DIR/.env" ]; then
  echo "==> .env (fill in AFROMESSAGE_* afterwards)"
  sudo -u "$APP_USER" cp "$HERE/env.production.example" "$APP_DIR/.env"
  sed -i "s|^APP_URL=.*|APP_URL=https://$DOMAIN|; s|^DB_DATABASE=.*|DB_DATABASE=$DB_NAME|; s|^DB_USERNAME=.*|DB_USERNAME=$DB_USER|; s|^DB_PASSWORD=.*|DB_PASSWORD=$DB_PASS|" "$APP_DIR/.env"
  chmod 640 "$APP_DIR/.env"
  chown "$APP_USER:www-data" "$APP_DIR/.env"
  echo "    Database password saved in $APP_DIR/.env"
fi

echo "==> nginx"
sed "s/__DOMAIN__/$DOMAIN/g; s|__APP_DIR__|$APP_DIR|g" "$HERE/nginx/kiray.conf" > /etc/nginx/sites-available/kiray
ln -sf /etc/nginx/sites-available/kiray /etc/nginx/sites-enabled/kiray
rm -f /etc/nginx/sites-enabled/default
nginx -t && systemctl reload nginx

echo "==> Queue worker (SMS) and scheduler (charges, late flags, reminders, backups)"
sed "s/__APP_USER__/$APP_USER/g; s|__APP_DIR__|$APP_DIR|g" "$HERE/supervisor/kiray-worker.conf" > /etc/supervisor/conf.d/kiray-worker.conf
echo "* * * * * $APP_USER cd $APP_DIR && php artisan schedule:run >> /dev/null 2>&1" > /etc/cron.d/kiray
chmod 644 /etc/cron.d/kiray

echo "==> Firewall"
ufw allow OpenSSH
ufw allow 'Nginx Full'
ufw --force enable

echo "==> First deploy"
sudo -u "$APP_USER" bash "$HERE/deploy.sh" --first-run
supervisorctl reread && supervisorctl update

echo "==> HTTPS"
certbot --nginx -d "$DOMAIN" --non-interactive --agree-tos -m "$EMAIL" --redirect || \
  echo "!! certbot failed: check that $DOMAIN points to this server, then run: certbot --nginx -d $DOMAIN"

cat <<DONE

Done. Next:
  1. Edit $APP_DIR/.env: SMS_DRIVER=afromessage and the AFROMESSAGE_* values.
  2. Create your admin:   sudo -u $APP_USER php $APP_DIR/artisan admin:create 09XXXXXXXX --name="Your name"
  3. Open https://$DOMAIN/admin
  4. Offsite backups: see deploy/README.md (backup-offsite.sh).
DONE
