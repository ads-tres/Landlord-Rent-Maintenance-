#!/usr/bin/env bash
# Copies the daily database backups to a second server inside Ethiopia (data must
# not leave the country without consent or approved safeguards).
#
# Setup once on the app server, as the app user:
#   ssh-keygen -t ed25519 -N "" -f ~/.ssh/kiray_backup
#   ssh-copy-id -i ~/.ssh/kiray_backup.pub backup@BACKUP_HOST
# Then add to cron (after the 02:10 backup):
#   40 2 * * * BACKUP_TARGET=backup@BACKUP_HOST:/srv/kiray-backups bash /var/www/kiray/deploy/backup-offsite.sh
set -euo pipefail

: "${BACKUP_TARGET:?Set BACKUP_TARGET, e.g. backup@10.0.0.5:/srv/kiray-backups}"
APP_DIR="${APP_DIR:-/var/www/kiray}"
SRC="$APP_DIR/storage/app/private/backups/"

latest="$(ls -1t "$SRC"*.sql.gz 2>/dev/null | head -n1 || true)"
if [ -z "$latest" ] || [ "$(find "$latest" -mmin -1500 | wc -l)" -eq 0 ]; then
  echo "No backup from the last 25 hours in $SRC. Check 'php artisan backup:database'." >&2
  exit 1
fi

rsync -az --delete -e "ssh -i $HOME/.ssh/kiray_backup -o StrictHostKeyChecking=accept-new" "$SRC" "$BACKUP_TARGET"
echo "Copied backups to $BACKUP_TARGET ($(basename "$latest") newest)"
