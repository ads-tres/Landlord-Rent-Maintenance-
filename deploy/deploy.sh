#!/usr/bin/env bash
# Deploys the latest code. Run as the app user on the server:  bash deploy/deploy.sh
set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/kiray}"
BRANCH="${BRANCH:-main}"
cd "$APP_DIR"

if [ "${1:-}" != "--first-run" ]; then
  php artisan down --retry=15 || true
  trap 'php artisan up' EXIT
fi

git fetch --quiet origin "$BRANCH"
git checkout --quiet "$BRANCH"
git reset --quiet --hard "origin/$BRANCH"

composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader

grep -q '^APP_KEY=base64:' .env || php artisan key:generate --force

mkdir -p storage/fonts storage/app/private/backups
chmod -R ug+rwX storage bootstrap/cache

php artisan migrate --force
php artisan db:seed --class=SettingsSeeder --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:restart

echo "Deployed $(git rev-parse --short HEAD) at $(date)"
