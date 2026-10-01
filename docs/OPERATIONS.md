# SAQF operations and security runbook

For the university IT team that would host and maintain SAQF. Everything below is available in the prototype. Production-only steps are marked.

## Daily operation

| What | Where | How often |
|---|---|---|
| Scheduler heartbeat (LMS imports, overdue checks, daily program re-evaluation) | `php bin/tick.php` from cron | every 5 min |
| Audit-chain verification | `php bin/verify_audit.php` (exit 0 = intact, 2 = tampering), or *Admin → System health → Verify audit chain* | nightly, and after every restore |
| Database backup | `mysqldump --single-transaction --routines --triggers saqf > saqf-$(date +%F).sql` | nightly, keep 30 days (production) |
| Health overview | *Admin → System health*: DB, environment, HTTPS, demo mode, clock, scheduler heartbeat, last integration run, audit chain, errors (24 h), failed sign-ins, locked accounts | as needed / monitoring probe on `/login.php` |

Example `/etc/cron.d/saqf` (production):

```
*/5 * * * *  www-data  php /var/www/saqf/bin/tick.php >> /var/log/saqf/tick.log 2>&1
15 2 * * *   www-data  php /var/www/saqf/bin/verify_audit.php >> /var/log/saqf/audit-verify.log 2>&1 || mail -s "SAQF audit chain alert" it-security@example.edu < /dev/null
```

The Docker image runs the heartbeat loop itself (`SAQF_SCHEDULER=on`).

## Common support requests

**"I'm locked out."** After 5 failed sign-ins (policy `auth.max_failed_logins`) the account locks for 15 minutes (`auth.lockout_minutes`). Find the user under *Admin → Users & access → Manage → Unlock* and enter the ticket number as the reason. Repeated failures from one IP are throttled separately (`auth.ip_max_attempts_15min`). See *Security events → Failed sign-ins by IP*.

**"I forgot my password."** *Manage → Reset password* generates a temporary password shown once, and the user must change it at next sign-in. Production should use SSO instead.

**"Something went wrong" with a reference code.** Every unhandled error shows the user an 8-character reference. Search it under *Admin → Error log*. The entry holds the message, file and line, URL, user, request id and stack trace. Users never see technical details in production.

**"A number on my dashboard looks wrong."** Open the record and check its provenance chip (Registrar / SIS / LMS / Calculated / Inherited / Faculty input). Then check *Admin → Integrations → Event log* to see which automated reaction produced it and when. Calculated values are re-derived from source data, so re-running the source sync or the scheduler (`php bin/tick.php --force`) recomputes them.

**"Someone changed X."** *Admin → Audit log* filters by action prefix, actor, object and date, and exports to CSV. Each entry shows old and new values, reason, IP and request id, and whether a person or SAQF automation did it.

## Maintenance windows

*Admin → System health → Maintenance mode* (a reason is required and audited) blocks every non-admin user with a friendly page. No data is touched. Use it for upgrades and restores, then turn it off the same way.

Upgrade procedure (production):

1. Turn on maintenance mode.
2. Back up the database.
3. Deploy the new code (or the new container image).
4. Apply schema changes.
5. Run `php bin/verify_audit.php`.
6. Turn off maintenance mode.

## Restore drill

1. Restore the dump into a new database.
2. Point a staging instance at it.
3. Run `php bin/verify_audit.php`. A broken chain means the dump is incomplete or was altered.
4. Spot-check a frozen report snapshot. *Report → View frozen snapshot* shows its SHA-256 seal.

## Security controls you can rely on

- Server-side authorization on every record. Denied attempts are logged as `security.access_denied` and appear under *Security events*.
- The audit log is append-only: database triggers refuse UPDATE/DELETE, and the SHA-256 hash chain detects any row edited or removed behind the application's back. If your MySQL account cannot create triggers, install them from `database/audit_guard.sql` with a privileged account.
- Sessions: 30 min idle, 8 h absolute (policies), HttpOnly, SameSite=Strict, Secure under HTTPS, bound to the browser.
- CSRF tokens on every change, a strict Content-Security-Policy, no third-party scripts or fonts.
- Separation of duties: the administrator role cannot approve specifications, decide exceptions or edit academic content.
- Demo mode, demo accounts, the integration simulator and `install --fresh` are all disabled when `APP_ENV=production`.

## Incident checklist (suspected tampering or account compromise)

1. Turn on maintenance mode and take a backup *before* investigating.
2. Run `php bin/verify_audit.php`. Note the first broken entry id.
3. *Security events*: look for lockouts, access denials and privileged changes around the time in question.
4. Disable affected accounts (*Users & access → Disable*, with a reason) and reset their passwords.
5. Compare frozen report snapshots with the live data for affected courses.
6. Record the incident and its resolution. Re-enable access when it is resolved.

## Hardening checklist before go-live (production)

- [ ] HTTPS with HSTS. Set `SAQF_TRUST_PROXY=true` only behind a trusted proxy.
- [ ] `APP_ENV=production`, `APP_DEBUG=false`, `SAQF_DEMO=false`.
- [ ] A dedicated DB user with rights on the `saqf` schema only. Strong password kept in a secret store.
- [ ] Web server document root = `public/` (never the repository root).
- [ ] Cron for `tick.php` and `verify_audit.php`, nightly backups, log rotation.
- [ ] SSO integration replacing local passwords. Accounts and roles provisioned from HR/SIS.
- [ ] Real adapters for Registrar/SIS/LMS (see README → Production integration path).
- [ ] Deanship of Quality confirms every policy under *Quality policies*.
