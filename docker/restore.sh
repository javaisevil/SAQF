#!/bin/sh
# Restores a SAQF backup written by docker/backup.sh (decrypting it when it ends in .enc).
#   docker compose stop app
#   docker compose run --rm --entrypoint sh backup /usr/local/bin/saqf-restore saqf-20261002-023000.sql.gz.enc
#   docker compose start app        (then: System administration → Audit log → Verify the chain)
# A saqf-files-*.tar.gz[.enc] archive with the same timestamp is restored into the evidence store too.
# Needs the same settings as the backup service (SAQF_DB_*, SAQF_BACKUP_PASSPHRASE for encrypted files).
set -eu
dir="${SAQF_BACKUP_DIR:-/backups}"
files_dir="${SAQF_BACKUP_FILES_DIR:-/evidence}"
[ $# -eq 1 ] || { echo "usage: saqf-restore <saqf-YYYYmmdd-HHMMSS.sql.gz[.enc]>"; ls -1 "$dir" | grep '^saqf-[0-9].*\.sql\.gz' | tail -5; exit 1; }
f="$1"; [ -f "$f" ] || f="$dir/$1"
[ -f "$f" ] || { echo "No such backup: $1"; exit 1; }
[ -f "$f.sha256" ] && (cd "$(dirname "$f")" && sha256sum -c "$(basename "$f").sha256")

open() {
  case "$1" in
    *.enc) [ -n "${SAQF_BACKUP_PASSPHRASE:-}" ] || { echo "SAQF_BACKUP_PASSPHRASE is needed to decrypt $1"; exit 1; }
           SAQF_PASS="$SAQF_BACKUP_PASSPHRASE" openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 -pass env:SAQF_PASS -in "$1" ;;
    *)     cat "$1" ;;
  esac
}

echo "Restoring database ${SAQF_DB_NAME:-saqf} from $(basename "$f") …"
open "$f" | gunzip | MYSQL_PWD="${SAQF_DB_PASS:-}" mysql --host="${SAQF_DB_HOST:-127.0.0.1}" --port="${SAQF_DB_PORT:-3306}" --user="${SAQF_DB_USER:-saqf}" "${SAQF_DB_NAME:-saqf}"

stamp=$(basename "$f" | sed -n 's/^saqf-\([0-9]\{8\}-[0-9]\{6\}\)\.sql\.gz.*$/\1/p')
for a in "$(dirname "$f")/saqf-files-$stamp.tar.gz.enc" "$(dirname "$f")/saqf-files-$stamp.tar.gz"; do
  if [ -f "$a" ] && [ -d "$files_dir" ]; then
    echo "Restoring evidence files from $(basename "$a") …"
    open "$a" | tar -xz -C "$files_dir"
    break
  fi
done
echo "Done. Start SAQF and verify the audit chain (System administration → Audit log, or php bin/verify_audit.php)."
