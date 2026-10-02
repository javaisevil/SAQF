#!/bin/sh
# SAQF backup: database dump (audit triggers included) + evidence files, optionally encrypted and
# copied off-site, each copy verified, with a status file that SAQF reads (System health, IT alerts).
#   docker compose: runs as the "backup" service with --loop (one backup per SAQF_BACKUP_INTERVAL_HOURS)
#   plain server:   cron  "30 2 * * *  SAQF_DB_USER=… SAQF_DB_PASS=… sh /var/www/saqf/docker/backup.sh"
#   one backup now: docker compose exec backup sh /usr/local/bin/saqf-backup
# Settings:
#   SAQF_DB_HOST, SAQF_DB_PORT, SAQF_DB_NAME, SAQF_DB_USER, SAQF_DB_PASS
#   SAQF_BACKUP_DIR (/backups)            where backups are written
#   SAQF_BACKUP_FILES_DIR (/evidence)     evidence store to include (skipped when absent)
#   SAQF_BACKUP_PASSPHRASE                when set, every file is encrypted (AES-256, PBKDF2) — keep the
#                                         passphrase in the university's password vault, not on this server only
#   SAQF_BACKUP_OFFSITE_DIR               a second location (mounted network share, NAS, another disk);
#                                         each copy is checked against its SHA-256 checksum
#   SAQF_BACKUP_STATUS_DIR (/status)      where last-backup.json is written for SAQF to read
#   SAQF_BACKUP_KEEP_DAYS (14), SAQF_BACKUP_INTERVAL_HOURS (24), SAQF_BACKUP_START_DELAY (900 s, --loop only)
# Restore: docker/restore.sh (decrypts when needed, restores the database and the evidence files).
set -u
umask 077   # backups contain every record: readable by the owner only
dir="${SAQF_BACKUP_DIR:-/backups}"
files_dir="${SAQF_BACKUP_FILES_DIR:-/evidence}"
offsite="${SAQF_BACKUP_OFFSITE_DIR:-}"
status_dir="${SAQF_BACKUP_STATUS_DIR:-/status}"
keep="${SAQF_BACKUP_KEEP_DAYS:-14}"
pass="${SAQF_BACKUP_PASSPHRASE:-}"

log() { echo "$(date -Iseconds) $*"; }

# Writes the status file atomically: status, finished_at, file, bytes, encrypted, offsite, files, verified, message.
status() {
  [ -d "$status_dir" ] || mkdir -p "$status_dir" 2>/dev/null || return 0
  tmpst="$status_dir/.last-backup.json.$$"
  printf '{"status":"%s","finished_at":"%s","file":"%s","bytes":%s,"encrypted":%s,"offsite":%s,"files":%s,"verified":%s,"message":"%s"}\n' \
    "$1" "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$2" "$3" "$4" "$5" "$6" "$7" "$8" > "$tmpst" && chmod 644 "$tmpst" && mv "$tmpst" "$status_dir/last-backup.json"
}

seal() { # $1 = file; encrypts in place when a passphrase is set, prints the final name
  if [ -n "$pass" ]; then
    SAQF_PASS="$pass" openssl enc -aes-256-cbc -pbkdf2 -iter 200000 -salt -pass env:SAQF_PASS -in "$1" -out "$1.enc" && rm -f "$1" && echo "$1.enc"
  else
    echo "$1"
  fi
}

check() { # $1 = file; proves the archive can be read back (decrypting when needed)
  case "$1" in
    *.enc) SAQF_PASS="$pass" openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 -pass env:SAQF_PASS -in "$1" | gzip -t ;;
    *)     gzip -t "$1" ;;
  esac
}

backup() {
  mkdir -p "$dir" || { status failed "" 0 false false false false "backup folder $dir not writable"; return 1; }
  stamp=$(date +%Y%m%d-%H%M%S)
  tmp="$dir/.saqf-$stamp.sql"
  if ! MYSQL_PWD="${SAQF_DB_PASS:-}" mysqldump --host="${SAQF_DB_HOST:-127.0.0.1}" --port="${SAQF_DB_PORT:-3306}" \
      --user="${SAQF_DB_USER:-saqf}" --single-transaction --routines --triggers --no-tablespaces \
      "${SAQF_DB_NAME:-saqf}" > "$tmp" || ! gzip -9 "$tmp"; then
    rm -f "$tmp" "$tmp.gz"
    log "BACKUP FAILED (database dump)" >&2
    status failed "" 0 false false false false "database dump failed"
    return 1
  fi
  mv "$tmp.gz" "$dir/saqf-$stamp.sql.gz"
  db=$(seal "$dir/saqf-$stamp.sql.gz") || { log "BACKUP FAILED (encryption)" >&2; status failed "" 0 false false false false "encryption failed"; return 1; }
  made="$db"

  with_files=false
  if [ -d "$files_dir" ]; then
    if tar -czf "$dir/saqf-files-$stamp.tar.gz" -C "$files_dir" . 2>/dev/null; then
      fa=$(seal "$dir/saqf-files-$stamp.tar.gz") && made="$made $fa" && with_files=true
    else
      rm -f "$dir/saqf-files-$stamp.tar.gz"
      log "WARNING: evidence files could not be archived from $files_dir" >&2
    fi
  fi

  verified=true
  for f in $made; do
    check "$f" || verified=false
    sha256sum "$f" | sed "s#  .*/#  #" > "$f.sha256"
  done
  if [ "$verified" != true ]; then
    log "BACKUP FAILED (archive could not be read back)" >&2
    status failed "$(basename "$db")" 0 "$([ -n "$pass" ] && echo true || echo false)" false "$with_files" false "verification failed"
    return 1
  fi

  copied=false
  if [ -n "$offsite" ]; then
    copied=true
    if mkdir -p "$offsite"; then
      for f in $made; do
        cp "$f" "$f.sha256" "$offsite/" && (cd "$offsite" && sha256sum -c "$(basename "$f").sha256" >/dev/null) || copied=false
      done
    else
      copied=false
    fi
    [ "$copied" = true ] || log "WARNING: off-site copy to $offsite failed" >&2
    find "$offsite" -name 'saqf-*' -mtime +"$keep" -exec rm -f {} \; 2>/dev/null
  fi
  find "$dir" -name 'saqf-*' -mtime +"$keep" -exec rm -f {} \;

  bytes=0
  for f in $made; do bytes=$((bytes + $(wc -c < "$f"))); done
  enc=false; [ -n "$pass" ] && enc=true
  if [ -n "$offsite" ] && [ "$copied" != true ]; then
    status failed "$(basename "$db")" "$bytes" "$enc" false "$with_files" true "off-site copy failed"
    return 1
  fi
  status ok "$(basename "$db")" "$bytes" "$enc" "$copied" "$with_files" true ""
  log "backup written and verified: $made$([ "$enc" = true ] && echo ' (encrypted)')$([ "$copied" = true ] && echo " · copied to $offsite")"
}

if [ "${1:-}" = "--loop" ]; then
  [ -n "$pass" ] || log "NOTE: SAQF_BACKUP_PASSPHRASE is not set — backups are not encrypted."
  [ -n "$offsite" ] || log "NOTE: SAQF_BACKUP_OFFSITE_DIR is not set — copy ./backups off this server."
  sleep "${SAQF_BACKUP_START_DELAY:-900}"   # let a first-time installation finish before the first dump
  while true; do
    backup || true
    sleep $(( ${SAQF_BACKUP_INTERVAL_HOURS:-24} * 3600 ))
  done
else
  backup
fi
