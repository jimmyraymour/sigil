#!/usr/bin/env bash
set -euo pipefail

# Fixed local backup utility. It reads DB_* values from the application's .env,
# but never archives that file. Override SIGIL_ROOT, SIGIL_BACKUP_DIR, or
# SIGIL_BACKUP_RETENTION_DAYS for a host-specific layout.
APP_ROOT="${SIGIL_ROOT:-/var/www/sigil}"
BACKUP_DIR="${SIGIL_BACKUP_DIR:-/var/backups/sigil}"
RETENTION_DAYS="${SIGIL_BACKUP_RETENTION_DAYS:-14}"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
WORK_DIR="${BACKUP_DIR}/.sigil-${STAMP}"
ARCHIVE="${BACKUP_DIR}/sigil-${STAMP}.tar.gz"

if [[ ! -f "${APP_ROOT}/.env" ]]; then
    printf 'Missing %s/.env\n' "$APP_ROOT" >&2
    exit 1
fi

# Read only KEY=value records. Never source .env as shell code: a backup job
# should not execute arbitrary commands from a configuration file.
env_value() {
    local key="$1"
    awk -v key="$key" '
        /^[[:space:]]*#/ || !index($0, "=") { next }
        {
            line=$0
            sub(/^[[:space:]]*/, "", line)
            if (index(line, key "=") != 1) { next }
            sub(/^[^=]*=/, "", line)
            gsub(/^[[:space:]]+|[[:space:]]+$/, "", line)
            if (line ~ /^".*"$/ || line ~ /^'\''.*'\''$/) {
                line=substr(line, 2, length(line) - 2)
            }
            print line
            exit
        }
    ' "${APP_ROOT}/.env"
}

DB_HOST="$(env_value DB_HOST)"
DB_PORT="$(env_value DB_PORT)"
DB_NAME="$(env_value DB_NAME)"
DB_USER="$(env_value DB_USER)"
DB_PASS="$(env_value DB_PASS)"

: "${DB_HOST:?DB_HOST is required in .env}"
: "${DB_PORT:?DB_PORT is required in .env}"
: "${DB_NAME:?DB_NAME is required in .env}"
: "${DB_USER:?DB_USER is required in .env}"
: "${DB_PASS:?DB_PASS is required in .env}"

case "$RETENTION_DAYS" in
    ''|*[!0-9]*) printf 'SIGIL_BACKUP_RETENTION_DAYS must be an integer\n' >&2; exit 1 ;;
esac

mkdir -p "$BACKUP_DIR" "$WORK_DIR"
chmod 0700 "$BACKUP_DIR" "$WORK_DIR"
trap 'rm -rf "$WORK_DIR"' EXIT

# MYSQL_PWD avoids placing the password in the process argument list.
export MYSQL_PWD="$DB_PASS"
mysqldump \
    --single-transaction \
    --routines \
    --triggers \
    --host="$DB_HOST" \
    --port="$DB_PORT" \
    --user="$DB_USER" \
    "$DB_NAME" > "${WORK_DIR}/database.sql"
unset MYSQL_PWD

# Render files are immutable and are useful when restoring the database rows.
# Logs, tmp files, .env, source secrets, and repository metadata are excluded.
if [[ -d "${APP_ROOT}/storage/renders" ]]; then
    tar -C "${APP_ROOT}/storage" -cf "${WORK_DIR}/renders.tar" renders
fi

printf 'created_at=%s\n' "$STAMP" > "${WORK_DIR}/manifest.txt"
printf 'database=%s\n' "$DB_NAME" >> "${WORK_DIR}/manifest.txt"
printf 'application_root=%s\n' "$APP_ROOT" >> "${WORK_DIR}/manifest.txt"

tar -C "$BACKUP_DIR" -czf "$ARCHIVE" ".sigil-${STAMP}"
chmod 0600 "$ARCHIVE"
rm -rf "$WORK_DIR"
trap - EXIT

find "$BACKUP_DIR" -maxdepth 1 -type f -name 'sigil-*.tar.gz' -mtime "+$RETENTION_DAYS" -delete
printf 'Wrote %s\n' "$ARCHIVE"
