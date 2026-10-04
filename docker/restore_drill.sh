#!/bin/sh
# Restore drill: proves that the newest backup can actually be restored, without touching the live database.
#   docker compose exec -T backup sh /usr/local/bin/saqf-restore-drill [saqf-YYYYmmdd-HHMMSS.sql.gz[.enc]]
# It decrypts and unpacks the backup, loads it into a THROW-AWAY MySQL server started inside this container
# (own data folder, no network), and checks: the archive reads, the dump loads with its triggers, the audit
# chain head recorded at backup time is present, the append-only triggers exist, and every current evidence
# file is in the evidence archive. It writes last-restore-drill.json for SAQF (Security center, preflight).
# What it does NOT prove: the recovery time on real hardware, that the live server could be rebuilt from scratch,
# or the full hash-chain verification (run `php bin/verify_audit.php` after a real restore).
# Settings: SAQF_BACKUP_DIR (/backups), SAQF_BACKUP_STATUS_DIR (/status), SAQF_BACKUP_PASSPHRASE[_FILE]
set -u
for v in SAQF_BACKUP_PASSPHRASE; do
  eval "cur=\${$v:-}"; eval "file=\${${v}_FILE:-}"
  if [ -z "$cur" ] && [ -n "$file" ] && [ -f "$file" ]; then eval "$v=\$(cat \"$file\")"; export "$v"; fi
done
umask 077
dir="${SAQF_BACKUP_DIR:-/backups}"
status_dir="${SAQF_BACKUP_STATUS_DIR:-/status}"
work=$(mktemp -d "${TMPDIR:-/tmp}/saqf-drill.XXXXXX")
# Unix socket paths are limited to ~107 characters: keep the socket in a short folder of its own.
sock=$(mktemp -d /tmp/saqfd.XXXXXX)
started=$(date +%s)
msg=""; ok=false; tables=0; audit_entries=0; head_state=not_recorded; triggers=0; ev_checked=0; ev_missing=0; file=""

finish() {
  [ -f "$work/mysqld.pid" ] && kill "$(cat "$work/mysqld.pid")" 2>/dev/null && sleep 2
  rm -rf "$work" "$sock"
  secs=$(( $(date +%s) - started ))
  mkdir -p "$status_dir" 2>/dev/null && {
    tmpst="$status_dir/.last-restore-drill.json.$$"
    printf '{"ok":%s,"finished_at":"%s","file":"%s","tables":%s,"audit_entries":%s,"audit_head":"%s","triggers":%s,"evidence_checked":%s,"evidence_missing":%s,"seconds":%s,"message":"%s"}\n' \
      "$ok" "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$(basename "$file")" "$tables" "$audit_entries" "$head_state" "$triggers" "$ev_checked" "$ev_missing" "$secs" "$msg" > "$tmpst" \
      && chmod 644 "$tmpst" && mv "$tmpst" "$status_dir/last-restore-drill.json"
  }
  echo "$(date -Iseconds) restore drill: ok=$ok $msg (tables=$tables audit_entries=$audit_entries head=$head_state triggers=$triggers evidence_missing=$ev_missing, ${secs}s)"
  [ "$ok" = true ]
  exit $?
}
fail() { msg="$1"; ok=false; finish; }

file="${1:-}"
if [ -z "$file" ]; then
  file=$(ls -1 "$dir"/saqf-2*.sql.gz* 2>/dev/null | grep -v '\.sha256$' | grep -v '\.audithead$' | sort | tail -1)
elif [ ! -f "$file" ] && [ -f "$dir/$file" ]; then
  file="$dir/$file"
fi
[ -n "$file" ] && [ -f "$file" ] || fail "no backup found in $dir"
[ -f "$file.sha256" ] && { (cd "$(dirname "$file")" && sha256sum -c "$(basename "$file").sha256" >/dev/null 2>&1) || fail "checksum of $(basename "$file") does not match"; }

open() { # prints the plain content of an archive
  case "$1" in
    *.enc) [ -n "${SAQF_BACKUP_PASSPHRASE:-}" ] || return 3
           SAQF_PASS="$SAQF_BACKUP_PASSPHRASE" openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 -pass env:SAQF_PASS -in "$1" ;;
    *)     cat "$1" ;;
  esac
}
open "$file" | gunzip > "$work/dump.sql" 2>/dev/null || fail "the backup could not be decrypted or unpacked (wrong passphrase, or damaged)"
[ -s "$work/dump.sql" ] || fail "the unpacked backup is empty"

# A private, network-less MySQL server for the drill.
runas=$(id -un)
mkdir -p "$work/data"
mysqld --no-defaults --initialize-insecure --user="$runas" --datadir="$work/data" > "$work/init.log" 2>&1 || fail "could not initialise the scratch server"
mysqld --no-defaults --user="$runas" --datadir="$work/data" --socket="$sock/s.sock" --pid-file="$work/mysqld.pid" --skip-networking --mysqlx=OFF \
  --log-bin-trust-function-creators=1 --log-error="$work/mysqld.err" > /dev/null 2>&1 &
i=0; until mysqladmin --no-defaults --socket="$sock/s.sock" -uroot ping >/dev/null 2>&1; do i=$((i + 1)); [ "$i" -gt 60 ] && fail "the scratch server did not start: $(tail -n 2 "$work/mysqld.err" 2>/dev/null | tr '"\n' "' " | head -c 160)"; sleep 1; done
q() { mysql --no-defaults --socket="$sock/s.sock" -uroot -N -B "$@"; }

q -e 'CREATE DATABASE drill CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci' || fail "could not create the scratch database"
q drill < "$work/dump.sql" 2> "$work/import.err" || fail "the dump did not load: $(head -c 120 "$work/import.err" | tr '"\n' "' ")"

tables=$(q -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='drill'")
[ "${tables:-0}" -ge 20 ] || fail "only ${tables:-0} tables were restored"
audit_entries=$(q drill -e 'SELECT COUNT(*) FROM audit_log' 2>/dev/null || echo 0)
[ "${audit_entries:-0}" -ge 1 ] || fail "the restored audit log is empty"
triggers=$(q -e "SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema='drill' AND event_object_table='audit_log'")
[ "${triggers:-0}" -ge 2 ] || fail "the append-only triggers on the audit log were not restored"
q drill -e 'SELECT COUNT(*) FROM users' >/dev/null 2>&1 || fail "the users table could not be read"

headfile="${file%.sql.gz*}"; headfile="${headfile}.audithead"
if [ -f "$headfile" ]; then
  id=$(cut -d: -f1 "$headfile" | tr -cd '0-9'); want=$(cut -d: -f2 "$headfile" | tr -cd '0-9a-f')
  got=$(q drill -e "SELECT hash FROM audit_log WHERE id=${id:-0}")
  [ "$got" = "$want" ] && [ -n "$want" ] && head_state=match || fail "the audit-chain entry #$id recorded at backup time is missing or different in the restored data"
fi

# Evidence files: every current evidence row must have its file in the evidence archive of the same backup.
stamp=$(basename "$file" | sed -n 's/^saqf-\([0-9]\{8\}-[0-9]\{6\}\)\..*/\1/p')
arch=""; for c in "$(dirname "$file")/saqf-files-$stamp.tar.gz.enc" "$(dirname "$file")/saqf-files-$stamp.tar.gz"; do [ -f "$c" ] && arch="$c" && break; done
if [ -n "$arch" ]; then
  open "$arch" | tar -tz 2>/dev/null | sed 's#^\./##; s#.*/##' | sort > "$work/files.txt" || fail "the evidence archive could not be read"
  q drill -e 'SELECT stored_name FROM evidence_files WHERE deleted_at IS NULL' | sort > "$work/rows.txt"
  ev_checked=$(wc -l < "$work/rows.txt" | tr -d ' ')
  ev_missing=$(comm -23 "$work/rows.txt" "$work/files.txt" | wc -l | tr -d ' ')
  [ "$ev_missing" = 0 ] || fail "$ev_missing evidence file(s) listed in the database are not in the evidence archive"
elif [ "$(q drill -e 'SELECT COUNT(*) FROM evidence_files WHERE deleted_at IS NULL')" != 0 ]; then
  fail "the database lists evidence files but this backup has no evidence archive"
fi
ok=true
msg="restored into a scratch server: $tables tables, $audit_entries audit entries"
finish
