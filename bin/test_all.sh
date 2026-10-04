#!/bin/sh
# Runs every SAQF test suite against a fresh demo database and prints one plain summary.
#   SAQF_DB_HOST=… SAQF_DB_USER=… SAQF_DB_PASS=… sh bin/test_all.sh
# Inside the Docker stack:  docker compose exec app sh bin/test_all.sh
# Each suite reinstalls the demo first (the database named by SAQF_DB_NAME is replaced).
set -u
cd "$(dirname "$0")/.." || exit 1
export APP_ENV="${APP_ENV:-local}" SAQF_DEMO="${SAQF_DEMO:-true}" NO_PROXY="127.0.0.1,localhost" no_proxy="127.0.0.1,localhost"
log="${TMPDIR:-/tmp}/saqf-tests-$$"
mkdir -p "$log"
port=$(php -r '$s = stream_socket_server("tcp://127.0.0.1:0"); $n = stream_socket_get_name($s, false); echo substr($n, strrpos($n, ":") + 1);')

total_pass=0
total_fail=0
failed=""
run() { # $1 = label, $2 = file stem, rest = command
  label="$1"; stem="$2"; shift 2
  php bin/install.php --demo --fresh > "$log/$stem.install.log" 2>&1 || { echo "  $label: demo install FAILED (see $log/$stem.install.log)"; total_fail=$((total_fail + 1)); failed="$failed $stem"; return; }
  "$@" > "$log/$stem.log" 2>&1
  line=$(grep -E '^[0-9]+ passed, [0-9]+ failed' "$log/$stem.log" | tail -1)
  p=$(echo "$line" | sed -n 's/^\([0-9]*\) passed.*/\1/p'); f=$(echo "$line" | sed -n 's/.*, \([0-9]*\) failed.*/\1/p')
  [ -n "$p" ] || { p=0; f=1; }
  total_pass=$((total_pass + p)); total_fail=$((total_fail + f))
  [ "$f" = 0 ] && mark="ok  " || { mark="FAIL"; failed="$failed $stem"; }
  printf '  %s %-58s %4s passed  %s failed\n' "$mark" "$label" "$p" "$f"
}

echo "SAQF test suites ($(php -r 'echo PHP_VERSION;'), database ${SAQF_DB_NAME:-saqf} on ${SAQF_DB_HOST:-127.0.0.1})"
echo
php -l public/index.php > /dev/null && find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l > "$log/lint.log" 2>&1 \
  && echo "  ok   Syntax check of every PHP file" || { echo "  FAIL Syntax check (see $log/lint.log)"; total_fail=$((total_fail + 1)); failed="$failed lint"; }
run "Automation scenarios (the quality loop end to end)" automation php tests/automation_test.php
run "Connectors, semester cycle, accounts, e-mail, SSO tokens" production php tests/production_test.php
php -S "127.0.0.1:$port" -t public > "$log/server.log" 2>&1 &
server=$!
sleep 1
run "Every page for every role, authorization, CSRF, lockout" http php tests/http_smoke.php "http://127.0.0.1:$port"
kill "$server" 2>/dev/null
run "University sign-in (OpenID Connect) end to end" sso php tests/sso_test.php
run "Sections, import, evidence, Word, 2-step, alerts, Arabic" features php tests/features_test.php
run "Plain wording, whole numbers, Arabic course content" wording php tests/wording_test.php
run "Go-live readiness, data pack, access review, security proof" readiness php tests/readiness_test.php
run "Robot check, two-step codes, trusted browsers, session, help" signin php tests/signin_test.php
run "Privacy and security hardening (pseudonyms, key, https, backups)" hardening php tests/hardening_test.php
php bin/migrate.php > "$log/migrate.log" 2>&1 && php bin/migrate.php --status >> "$log/migrate.log" 2>&1 \
  && echo "  ok   Database migrations are idempotent (upgrade path)" || { echo "  FAIL Migrations (see $log/migrate.log)"; total_fail=$((total_fail + 1)); failed="$failed migrate"; }
php bin/install.php --demo --fresh > /dev/null 2>&1
# Inside the container the demo papers were just filed as root: give them back to the web server.
if [ "$(id -u)" = 0 ] && id www-data > /dev/null 2>&1; then chown -R www-data:www-data "${SAQF_STORAGE_DIR:-storage/evidence}" 2>/dev/null; fi

echo
echo "Total: $total_pass checks passed, $total_fail failed. Logs: $log"
[ "$total_fail" = 0 ] || { echo "Failed:$failed"; exit 1; }
