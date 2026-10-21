#!/usr/bin/env bash
# بکاپ روزانه: دیتابیس MySQL + پوشه storage (مدارک خصوصی).
# در cron:  0 3 * * * /var/www/arezoo-backend/deploy/backup.sh
set -euo pipefail

DEST=/var/backups/arezoo
STAMP=$(date +%F)
APP=/var/www/arezoo-backend

mkdir -p "$DEST"
mysqldump --single-transaction --defaults-file=/root/.my.cnf arezoo | gzip > "$DEST/db-$STAMP.sql.gz"
tar -czf "$DEST/storage-$STAMP.tar.gz" -C "$APP" storage/app

# فقط ۱۴ بکاپ اخیر نگه داشته می‌شود.
find "$DEST" -type f -mtime +14 -delete
