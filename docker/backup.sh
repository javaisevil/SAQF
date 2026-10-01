#!/bin/sh
# SAQF database backup (compressed logical dump, audit triggers included).
#   docker compose: runs as the "backup" service with --loop (one backup per SAQF_BACKUP_INTERVAL_HOURS)
#   plain server:   cron  "30 2 * * *  SAQF_DB_USER=… SAQF_DB_PASS=… sh /var/www/saqf/docker/backup.sh"
# Settings: SAQF_DB_HOST, SAQF_DB_PORT, SAQF_DB_NAME, SAQF_DB_USER, SAQF_DB_PASS,
#           SAQF_BACKUP_DIR (/backups), SAQF_BACKUP_KEEP_DAYS (14), SAQF_BACKUP_INTERVAL_HOURS (24),
#           SAQF_BACKUP_START_DELAY (seconds before the first backup in --loop mode, default 900)
# Restore: gunzip -c saqf-YYYYmmdd-HHMMSS.sql.gz | mysql saqf   then   php bin/verify_audit.php
set -u
umask 077   # backups contain every record: readable by the owner only
dir="${SAQF_BACKUP_DIR:-/backups}"
keep="${SAQF_BACKUP_KEEP_DAYS:-14}"

backup() {
  mkdir -p "$dir" || return 1
  stamp=$(date +%Y%m%d-%H%M%S)
  tmp="$dir/.saqf-$stamp.sql"
  if MYSQL_PWD="${SAQF_DB_PASS:-}" mysqldump --host="${SAQF_DB_HOST:-127.0.0.1}" --port="${SAQF_DB_PORT:-3306}" \
      --user="${SAQF_DB_USER:-saqf}" --single-transaction --routines --triggers --no-tablespaces \
      "${SAQF_DB_NAME:-saqf}" > "$tmp" && gzip -9 "$tmp"; then
    mv "$tmp.gz" "$dir/saqf-$stamp.sql.gz"
    find "$dir" -name 'saqf-*.sql.gz' -mtime +"$keep" -exec rm -f {} \;
    echo "$(date -Iseconds) backup written: $dir/saqf-$stamp.sql.gz"
  else
    rm -f "$tmp" "$tmp.gz"
    echo "$(date -Iseconds) BACKUP FAILED" >&2
    return 1
  fi
}

if [ "${1:-}" = "--loop" ]; then
  sleep "${SAQF_BACKUP_START_DELAY:-900}"   # let a first-time installation finish before the first dump
  while true; do
    backup || true
    sleep $(( ${SAQF_BACKUP_INTERVAL_HOURS:-24} * 3600 ))
  done
else
  backup
fi
