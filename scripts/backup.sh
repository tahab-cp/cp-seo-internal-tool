#!/usr/bin/env bash
#
# Production backup: database dump + private application files.
#
#   scripts/backup.sh                 # uses <app-dir>/.env for the database
#   BACKUP_DIR=/mnt/backups scripts/backup.sh
#
# - fails on the first error (set -euo pipefail) and never leaves a partial
#   archive behind under the final name
# - passes the database password through a temporary defaults file, never
#   on the command line
# - gzip-compresses the dump; keeps BACKUP_RETENTION_DAYS days locally
# - copy the resulting directory off the machine (rsync, object storage,
#   office NAS) with your own scheduled job; that step is deliberately not
#   hard-coded here
#
set -euo pipefail

APP_DIR="${APP_DIR:-$(cd "$(dirname "$0")/.." && pwd)}"
BACKUP_DIR="${BACKUP_DIR:-$APP_DIR/storage/backups}"
BACKUP_RETENTION_DAYS="${BACKUP_RETENTION_DAYS:-14}"
DUMP_BIN="${DUMP_BIN:-$(command -v mariadb-dump || command -v mysqldump || true)}"
STAMP="$(date +%Y%m%d-%H%M%S)"

if [[ -z "$DUMP_BIN" ]]; then
    echo "No mysqldump / mariadb-dump binary found (set DUMP_BIN)." >&2
    exit 1
fi

if [[ ! -f "$APP_DIR/.env" ]]; then
    echo "No .env in $APP_DIR" >&2
    exit 1
fi

# Read database settings from .env without exporting them to child processes' command lines.
env_value() { grep -E "^$1=" "$APP_DIR/.env" | head -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
DB_HOST="$(env_value DB_HOST)"; DB_PORT="$(env_value DB_PORT)"; DB_DATABASE="$(env_value DB_DATABASE)"
DB_USERNAME="$(env_value DB_USERNAME)"; DB_PASSWORD="$(env_value DB_PASSWORD)"

if [[ -z "$DB_DATABASE" ]]; then
    echo "DB_DATABASE is not set in .env" >&2
    exit 1
fi

mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"

DEFAULTS="$(mktemp)"
trap 'rm -f "$DEFAULTS" "$BACKUP_DIR/db-$STAMP.sql.gz.partial" "$BACKUP_DIR/files-$STAMP.tar.gz.partial"' EXIT
chmod 600 "$DEFAULTS"
printf '[client]\nhost=%s\nport=%s\nuser=%s\npassword=%s\n' "${DB_HOST:-127.0.0.1}" "${DB_PORT:-3306}" "$DB_USERNAME" "$DB_PASSWORD" > "$DEFAULTS"

echo "Dumping database $DB_DATABASE ..."
"$DUMP_BIN" --defaults-extra-file="$DEFAULTS" --single-transaction --quick --routines --triggers \
    --default-character-set=utf8mb4 "$DB_DATABASE" | gzip -9 > "$BACKUP_DIR/db-$STAMP.sql.gz.partial"
mv "$BACKUP_DIR/db-$STAMP.sql.gz.partial" "$BACKUP_DIR/db-$STAMP.sql.gz"

echo "Archiving private files (report PDFs, CSV uploads) ..."
tar -C "$APP_DIR" -czf "$BACKUP_DIR/files-$STAMP.tar.gz.partial" \
    --exclude='storage/app/tmp' --exclude='storage/app/livewire-tmp' \
    storage/app
mv "$BACKUP_DIR/files-$STAMP.tar.gz.partial" "$BACKUP_DIR/files-$STAMP.tar.gz"

# Environment recovery needs the .env too: keep a root-only copy next to the archives.
cp "$APP_DIR/.env" "$BACKUP_DIR/env-$STAMP.txt"
chmod 600 "$BACKUP_DIR/env-$STAMP.txt"

find "$BACKUP_DIR" -type f \( -name 'db-*.sql.gz' -o -name 'files-*.tar.gz' -o -name 'env-*.txt' \) -mtime +"$BACKUP_RETENTION_DAYS" -delete

echo "Backup complete:"
ls -la "$BACKUP_DIR/db-$STAMP.sql.gz" "$BACKUP_DIR/files-$STAMP.tar.gz"
