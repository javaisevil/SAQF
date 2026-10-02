# SAQF operations and security runbook

For the university IT team that hosts and maintains SAQF. Connecting the Registrar, SIS, LMS, single
sign-on and e-mail is covered in [INTEGRATIONS.md](INTEGRATIONS.md).

## Daily operation

| What | Where | How often |
|---|---|---|
| Scheduler heartbeat: SIS sync and automatic term start (hourly), LMS imports, catalogue re-sync and rule re-checks (daily), e-mail digests and mail queue | `php bin/tick.php` (exit 0 = ok, 2 = a step failed — see *Error log*) | every 5 min |
| Audit-chain verification | `php bin/verify_audit.php` (exit 0 = intact, 2 = tampering), or *Admin → System health → Verify audit chain* | nightly, and after every restore |
| Database backup | Docker: the `backup` service writes `./backups/saqf-*.sql.gz` (owner-only, kept 14 days). Elsewhere: `docker/backup.sh` from cron | nightly; copy backups off the server |
| Health probe | `GET /health.php` → `200 {"status":"ok","database":"ok","scheduler":"ok",…}` or `503` when the database is unreachable. No session, no sensitive data | monitoring / load balancer, every minute |
| Health overview | *Admin → System health*: DB, environment, HTTPS, demo mode, clock, scheduler heartbeat, last integration run, audit chain, errors (24 h), failed sign-ins, locked accounts | as needed |
| Connections | *Admin → Integrations → Test connections* | after any change on the SIS/LMS side |

Example `/etc/cron.d/saqf` (servers without Docker):

```
*/5 * * * *  www-data  php /var/www/saqf/bin/tick.php >> /var/log/saqf/tick.log 2>&1
15 2 * * *   www-data  php /var/www/saqf/bin/verify_audit.php >> /var/log/saqf/audit-verify.log 2>&1 || mail -s "SAQF audit chain alert" it-security@example.edu < /dev/null
30 2 * * *   saqf      SAQF_DB_USER=saqf SAQF_DB_PASS=… SAQF_BACKUP_DIR=/var/backups/saqf sh /var/www/saqf/docker/backup.sh
```

The Docker image runs the heartbeat loop itself (`SAQF_SCHEDULER=on`), and the compose file includes the backup service. Only one scheduler runs at a time (database lock), so cron and the container loop can safely overlap.

## Common support requests

**"I'm locked out."** After 5 failed sign-ins (policy `auth.max_failed_logins`) the account locks for 15 minutes (`auth.lockout_minutes`). Find the user under *Admin → Users & access → Manage → Unlock* and enter the ticket number as the reason. Repeated failures from one IP are throttled separately (`auth.ip_max_attempts_15min`). See *Security events → Failed sign-ins by IP*.

**"I forgot my password."** With university SSO, passwords are managed by the identity provider. Otherwise, when e-mail is configured, people use *Forgot your password?* on the sign-in page (a single-use link valid for 30 minutes, throttled per account and network). An administrator can always use *Manage → Reset password*, which shows a temporary password once; the user must change it at next sign-in.

**"I can't sign in with my university account."** The sign-in page shows the reason. "Not set up in SAQF yet": add the person under *Users & access* (or give them a mapped role in the identity provider when auto-provisioning is on). Refused sign-ins are listed under *Security events → Recent failed sign-ins* with reason `sso: …`. If the identity provider itself is down, administrators can still use the password form (`SAQF_PASSWORD_LOGIN=admins`).

**"A new instructor has no account / a course has no workspace."** *Integrations → Recent integration runs* shows each SIS run with counts of unknown courses and instructors. Instructors in the SIS feed with a name are created automatically; those without one need the SIS record fixed or an account added by hand with their *SIS / HR identifier*.

**"The new term has not started."** Terms start on the `starts_on` date from the SIS calendar (policy *Start terms automatically*). *Integrations → Academic calendar* shows every term; an administrator can start the next one early (reason required, audited) or add a term the SIS does not provide.

**"Grades did not arrive."** The gradebook column must be named like the assessment in the approved specification. Ignored columns are recorded in the audit log (`results.columns_ignored`). *Run scheduler now* re-reads the LMS.

**"Something went wrong" with a reference code.** Every unhandled error shows the user an 8-character reference. Search it under *Admin → Error log*. The entry holds the message, file and line, URL, user, request id and stack trace. Users never see technical details in production.

**"A number on my dashboard looks wrong."** Open the record and check its provenance chip (Registrar / SIS / LMS / Calculated / Inherited / Faculty input). Then check *Admin → Integrations → Event log* to see which automated reaction produced it and when. Calculated values are re-derived from source data, so re-running the source sync or the scheduler (`php bin/tick.php --force`) recomputes them.

**"Someone changed X."** *Admin → Audit log* filters by action prefix, actor, object and date, and exports to CSV. Each entry shows old and new values, reason, IP and request id, and whether a person or SAQF automation did it.

## Maintenance windows

*Admin → System health → Maintenance mode* (a reason is required and audited) blocks every non-admin user with a friendly page. No data is touched. Use it for upgrades and restores, then turn it off the same way.

Upgrade procedure (production):

1. Turn on maintenance mode.
2. Back up the database.
3. Deploy the new code (or the new container image).
4. Apply schema changes: `php bin/migrate.php` (the container does this on every start; `--status` lists pending migrations without applying them). Existing data is kept.
5. Run `php bin/verify_audit.php`.
6. Turn off maintenance mode.

## Restore drill

1. Restore the dump into a new database: `gunzip -c saqf-YYYYmmdd-HHMMSS.sql.gz | mysql saqf_restore` (the dump includes the audit-log triggers).
2. Point a staging instance at it.
3. Run `php bin/verify_audit.php`. A broken chain means the dump is incomplete or was altered.
4. Spot-check a frozen report snapshot. *Report → View frozen snapshot* shows its SHA-256 seal.

## Security controls you can rely on

- Server-side authorization on every record. Denied attempts are logged as `security.access_denied` and appear under *Security events*.
- The audit log is append-only: database triggers refuse UPDATE/DELETE, and the SHA-256 hash chain detects any row edited or removed behind the application's back. If your MySQL account cannot create triggers, install them from `database/audit_guard.sql` with a privileged account.
- Sessions: 30 min idle, 8 h absolute (policies), HttpOnly, SameSite=Strict, Secure under HTTPS, bound to the browser.
- University sign-in (OpenID Connect): PKCE, single-use state bound to the browser, nonce, ID-token signature verified against the provider's keys, issuer/audience/expiry checks. Refused attempts are logged.
- Password reset links are single-use, expire after 30 minutes, are stored only as hashes and always use `SAQF_BASE_URL` (never the request's host name).
- Student identities from the LMS are pseudonymised (keyed HMAC) before storage. Backups are written owner-readable only.
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

- [ ] HTTPS with HSTS. Set `SAQF_TRUST_PROXY=true` only behind a trusted proxy. `SAQF_BASE_URL` set to the public https address.
- [ ] `APP_ENV=production`, `APP_DEBUG=false`, `SAQF_DEMO=false`, `SAQF_AUTO_INSTALL=production` (first start only).
- [ ] A dedicated DB user with rights on the `saqf` schema only. Strong passwords and connector secrets kept in a secret store, never in git.
- [ ] Web server document root = `public/` (never the repository root).
- [ ] Scheduler running (container loop or cron), nightly `verify_audit.php`, backups copied off the server, log rotation, `/health.php` monitored.
- [ ] University SSO configured; `SAQF_PASSWORD_LOGIN=admins`; first administrator password changed.
- [ ] SIS and LMS connectors configured and *Test connections* green (see [INTEGRATIONS.md](INTEGRATIONS.md)); `SAQF_LMS_COURSE_KEY` matches the LMS course IDs.
- [ ] People provisioned (CSV import, SIS feed or SSO roles); Heads of Department and deans have their department/college set.
- [ ] E-mail configured and a test digest received.
- [ ] Programs without published PLOs (Architecture, EMBA, LLB, LLM) have their PLOs entered by the Heads of Department.
- [ ] Deanship of Quality confirms every policy under *Quality policies*.
