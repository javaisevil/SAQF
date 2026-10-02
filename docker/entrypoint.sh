#!/bin/sh
# SAQF container start-up:
#  1. wait for MySQL
#  2. optionally install the schema (+ demo scenario) on first start — never re-installs an existing database
#  3. apply pending database migrations (upgrades keep their data)
#  4. run the scheduler heartbeat every 5 minutes in the background (SIS/LMS sync, term rollover,
#     deadline checks, e-mail delivery)
#  5. hand over to Apache
set -e
cd /var/www/saqf

php bin/wait_for_db.php

case "${SAQF_AUTO_INSTALL:-off}" in
  demo)       php bin/install.php --demo --skip-if-installed ;;
  production) php bin/install.php --skip-if-installed ;;
  off|"")     echo "SAQF_AUTO_INSTALL=off — expecting an installed database." ;;
  *)          echo "Unknown SAQF_AUTO_INSTALL value '${SAQF_AUTO_INSTALL}' (use demo, production or off)"; exit 1 ;;
esac

php bin/migrate.php || echo "WARNING: database migrations did not run (see message above)."

# Evidence store (a volume outside the web root): readable and writable by the web server only
# (the demo installer files its sample papers as root, so ownership is fixed after installing).
evidence="${SAQF_STORAGE_DIR:-/var/www/saqf/storage/evidence}"
mkdir -p "$evidence" && chown -R www-data:www-data "$evidence" && chmod 700 "$evidence"

if [ "${SAQF_SCHEDULER:-on}" = "on" ]; then
  ( while true; do php bin/tick.php || true; sleep 300; done ) &
fi

exec "$@"
