#!/bin/sh
# Creates the secret files for the hardened production stack (docker-compose.prod.yml):
#   secrets/saqf_app_key  secrets/saqf_db_pass  secrets/saqf_db_root_pass  secrets/saqf_backup_passphrase
# Each is 64 random hex characters. NEVER overwrites a file that exists (replacing the application key of an
# installation that has data would orphan every student pseudonym; see docs/OPERATIONS.md, "Application key").
# The folder is private to the host user; the files are world-readable *inside the container mount only*
# because the web server user must read them. Put copies in the university's password vault: without the
# backup passphrase and the application key, backups and pseudonyms cannot be recovered.
#   sh bin/make_secrets.sh [folder]
set -eu
dir="${1:-secrets}"
umask 077
mkdir -p "$dir"
chmod 700 "$dir"
gen() {
  f="$dir/$1"
  if [ -e "$f" ]; then echo "kept    $f (already exists, not changed)"; return; fi
  openssl rand -hex 32 > "$f"
  chmod 444 "$f"
  echo "created $f"
}
gen saqf_app_key
gen saqf_db_pass
gen saqf_db_root_pass
gen saqf_backup_passphrase
echo "Now store copies in the password vault, then: docker compose -f docker-compose.yml -f docker-compose.prod.yml --profile https up -d --build"
