#!/bin/sh
# SAQF container start-up:
#  1. wait for MySQL
#  2. optionally install the schema (+ demo scenario) on first start — never re-installs an existing database
#  3. run the scheduler heartbeat every 5 minutes in the background (LMS imports, deadline checks)
#  4. hand over to Apache
set -e
cd /var/www/saqf

php bin/wait_for_db.php

case "${SAQF_AUTO_INSTALL:-off}" in
  demo)       php bin/install.php --demo --skip-if-installed ;;
  production) php bin/install.php --skip-if-installed ;;
  off|"")     echo "SAQF_AUTO_INSTALL=off — expecting an installed database." ;;
  *)          echo "Unknown SAQF_AUTO_INSTALL value '${SAQF_AUTO_INSTALL}' (use demo, production or off)"; exit 1 ;;
esac

if [ "${SAQF_SCHEDULER:-on}" = "on" ]; then
  ( while true; do php bin/tick.php || true; sleep 300; done ) &
fi

exec "$@"
