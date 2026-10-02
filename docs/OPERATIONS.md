# SAQF operations and security runbook

For the university IT team that hosts and maintains SAQF. Connecting the Registrar, SIS, LMS, single
sign-on and e-mail is covered in [INTEGRATIONS.md](INTEGRATIONS.md).

## Daily operation

| What | Where | How often |
|---|---|---|
| Scheduler heartbeat: SIS sync and automatic term start (hourly), LMS imports, catalogue re-sync and rule re-checks (daily), e-mail digests and mail queue | `php bin/tick.php` (exit 0 = ok, 2 = a step failed — see *Error log*) | every 5 min |
| Audit-chain verification | `php bin/verify_audit.php` (exit 0 = intact, 2 = tampering), or *Admin → System health → Verify audit chain* | nightly, and after every restore |
| Backup | Docker: the `backup` service writes `./backups/saqf-*.sql.gz.enc` (database) and `saqf-files-*.tar.gz.enc` (evidence files), reads each one back to verify it, copies both to `SAQF_BACKUP_OFFSITE_PATH` with SHA-256 checksums, keeps 14 days, and writes `last-backup.json` for SAQF. Elsewhere: `docker/backup.sh` from cron | nightly; automatic alert if missing or failed |
| IT alerts | *Admin → IT alerts* (also e-mailed to administrators and posted to `SAQF_ALERT_WEBHOOK`) | raised and cleared automatically; reminders every 6 h while open |
| Security overview | *Admin → Security center*: every control with its live status and how to fix what is not yet on, people (administrators with two-step verification, active sessions, locked and dormant accounts) and the last 7 days of security events | weekly, and before go-live |
| Health probe | `GET /health.php` → `200 {"status":"ok","database":"ok","scheduler":"ok",…}` or `503` when the database is unreachable. No session, no sensitive data | monitoring / load balancer, every minute |
| Health overview | *Admin → System health*: DB, environment, HTTPS, demo mode, clock, scheduler heartbeat, last integration run, audit chain, backups, evidence store, IT alerts, errors (24 h), failed sign-ins, locked accounts | as needed |
| Connections | *Admin → Integrations → Test connections* | after any change on the SIS/LMS side |

Example `/etc/cron.d/saqf` (servers without Docker):

```
*/5 * * * *  www-data  php /var/www/saqf/bin/tick.php >> /var/log/saqf/tick.log 2>&1
15 2 * * *   www-data  php /var/www/saqf/bin/verify_audit.php >> /var/log/saqf/audit-verify.log 2>&1 || mail -s "SAQF audit chain alert" it-security@example.edu < /dev/null
30 2 * * *   saqf      SAQF_DB_USER=saqf SAQF_DB_PASS=… SAQF_BACKUP_DIR=/var/backups/saqf sh /var/www/saqf/docker/backup.sh
```

The Docker image runs the heartbeat loop itself (`SAQF_SCHEDULER=on`), and the compose file includes the backup service. Only one scheduler runs at a time (database lock), so cron and the container loop can safely overlap. The scheduler also re-verifies the audit chain every night and prunes expired rate-limit counters.

## IT alerts

SAQF raises an alert by itself, tells every administrator in SAQF and by e-mail, posts it to the Teams or Slack webhook in `SAQF_ALERT_WEBHOOK` (JSON `{"text": "…"}`), reminds every 6 hours while it stays open, and clears it when the check passes again:

| Alert | Raised when | Cleared when |
|---|---|---|
| SIS / LMS / catalogue sync is failing | the step failed on two scheduler runs in a row (one blip is ignored) | the next run succeeds |
| Automatic quality checks are failing | rule evaluation keeps failing | the next run succeeds |
| E-mail delivery is failing | messages could not be sent and none went out | mail goes out again |
| Database backup missing or failed (critical) | the backup service reported a failure, or the last good backup is older than 26 hours (production) | the next good backup |
| Audit log integrity check failed (critical) | the nightly hash-chain verification finds an edited or deleted entry | the chain verifies again (after a restore) |
| Background scheduler has stopped | in production, the container loop or cron has not run for 30 minutes (pages keep it going meanwhile) | the real scheduler runs again |
| Virus scanner unreachable | `SAQF_CLAMAV_HOST` is set but clamd does not answer (uploads pause) | clamd answers |
| Malware blocked at upload (critical) | the scanner rejected an uploaded file | resolved by IT after review |
| Password guessing blocked | sign-ins from one address were throttled | an hour without throttling |

## HTTPS

`docker compose --profile https up -d` adds a Caddy proxy (`docker/Caddyfile`) on ports 80/443. With `SAQF_DOMAIN` set to a name that points at the server, it obtains and renews a certificate automatically; with the default `localhost` it uses its own local certificate (for testing). To use a certificate issued by the university, mount it into the `proxy` service and replace the `tls` line as the Caddyfile explains. In production also set `SAQF_PORT=127.0.0.1:8080` so the plain HTTP port is reachable only from the server itself, and `SAQF_BASE_URL=https://…`.

SAQF accepts the client address from `X-Forwarded-For` only when the request comes from an address in `SAQF_TRUSTED_PROXIES` (the bundled proxy has the fixed address 172.28.250.10 on the compose network). Behind the university's own load balancer, list its address or range instead.

## Evidence files

Uploaded course-file evidence lives outside the web root in the `saqf_evidence` volume (`SAQF_STORAGE_DIR`), under random names, readable by the web server only. Each file's content is checked against its type (macro-enabled Office files are refused), its SHA-256 fingerprint is recorded, and every download is audited. Set `SAQF_CLAMAV_HOST=clamav:3310` and start with `--profile antivirus` to scan every upload; if the scanner is down, uploads pause instead of skipping the scan. The size limit is the policy *Maximum evidence file size* (20 MB by default; PHP accepts up to 25 MB).

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

## Restore

With Docker (decrypts with `SAQF_BACKUP_PASSPHRASE`, checks the checksum, restores the database and the evidence files of the same night):

```bash
docker compose stop app
docker compose run --rm --entrypoint sh backup /usr/local/bin/saqf-restore saqf-20261002-023000.sql.gz.enc
docker compose start app
docker compose exec app php bin/verify_audit.php
```

Without Docker: `openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 -in saqf-….sql.gz.enc | gunzip | mysql saqf` (drop `openssl` for unencrypted files).

Restore drill (quarterly): restore into a staging instance, run `php bin/verify_audit.php` (a broken chain means the dump is incomplete or was altered), and spot-check a frozen report snapshot (*Report → View frozen snapshot* shows its SHA-256 seal). The CI pipeline performs an encrypted backup and a restore on every change.

## Security controls you can rely on

- Server-side authorization on every record. Denied attempts are logged as `security.access_denied` and appear under *Security events*.
- The audit log is append-only: database triggers refuse UPDATE/DELETE, and the SHA-256 hash chain detects any row edited or removed behind the application's back. If your MySQL account cannot create triggers, install them from `database/audit_guard.sql` with a privileged account.
- Sign-in: Argon2id password hashes (older hashes are upgraded at the next sign-in); passwords need 10+ characters and may not be common words, keyboard runs, the person's name or the university's name; account lockout and network throttling; rate limits on changes, uploads and exports.
- Two-step verification (authenticator app, RFC 6238) with ten single-use recovery codes. Required for administrators by default (policy `auth.mfa_required`: off / admins / all); an administrator without it is sent to set it up before anything else. Secrets are stored encrypted (AES-256-GCM); a code cannot be used twice. An administrator can reset a person's two-step verification (*Users & access → Manage*).
- Administrators can be limited to campus or VPN addresses (`SAQF_ADMIN_ALLOWED_IPS`), and must re-confirm their identity (password and code) before account changes when they signed in more than 15 minutes earlier (policy `security.reauth_minutes`).
- Sessions: 30 min idle, 8 h absolute (policies), HttpOnly, SameSite=Strict, Secure under HTTPS, bound to the browser. Every session is listed under *Account & security* and can be ended remotely; changing a password ends all other sessions; sign-ins from a new device are notified (policy `security.new_device_alert`).
- University sign-in (OpenID Connect): PKCE, single-use state bound to the browser, nonce, ID-token signature verified against the provider's keys, issuer/audience/expiry checks. Refused attempts are logged.
- Password reset links are single-use, expire after 30 minutes, are stored only as hashes and always use `SAQF_BASE_URL` (never the request's host name).
- Student identities from the LMS are pseudonymised (keyed HMAC) before storage. Backups are written owner-readable only.
- CSRF tokens on every change; a strict Content-Security-Policy (`script-src 'self'`, no inline scripts or event handlers anywhere), `object-src 'none'`, frame blocking, Cross-Origin-Opener/Resource-Policy; no third-party scripts or fonts.
- Separation of duties: the administrator role cannot approve specifications, decide exceptions or edit academic content.
- Demo mode, demo accounts, the one-click demo sign-in, the guided tour, the integration simulator and `install --fresh` are all disabled when `APP_ENV=production` (an attempt to use the demo sign-in is logged as a security event).

## Incident checklist (suspected tampering or account compromise)

1. Turn on maintenance mode and take a backup *before* investigating.
2. Run `php bin/verify_audit.php`. Note the first broken entry id.
3. *Security events*: look for lockouts, access denials and privileged changes around the time in question.
4. Disable affected accounts (*Users & access → Disable*, with a reason) and reset their passwords.
5. Compare frozen report snapshots with the live data for affected courses.
6. Record the incident and its resolution. Re-enable access when it is resolved.

## Hardening checklist before go-live (production)

- [ ] HTTPS with HSTS (`--profile https` with `SAQF_DOMAIN`, or the university's proxy listed in `SAQF_TRUSTED_PROXIES`). `SAQF_BASE_URL` set to the public https address; `SAQF_PORT=127.0.0.1:8080`.
- [ ] *Admin → Security center* reviewed: every control green or consciously accepted.
- [ ] `APP_ENV=production`, `APP_DEBUG=false`, `SAQF_DEMO=false`, `SAQF_AUTO_INSTALL=production` (first start only).
- [ ] A dedicated DB user with rights on the `saqf` schema only. Strong passwords and connector secrets kept in a secret store, never in git.
- [ ] Web server document root = `public/` (never the repository root).
- [ ] Scheduler running (container loop or cron), `/health.php` monitored, log rotation.
- [ ] Backups encrypted (`SAQF_BACKUP_PASSPHRASE` kept in the university's password vault) and copied off the server (`SAQF_BACKUP_OFFSITE_PATH`); a restore drill done; *System health → Backups* green.
- [ ] IT alerts reach people: e-mail configured or `SAQF_ALERT_WEBHOOK` set to the IT team's Teams/Slack channel.
- [ ] University SSO configured; `SAQF_PASSWORD_LOGIN=admins`; first administrator password changed and two-step verification set up; `SAQF_ADMIN_ALLOWED_IPS` set to the campus/VPN ranges.
- [ ] Virus scanning on (`--profile antivirus`, `SAQF_CLAMAV_HOST=clamav:3310`) or the university's own scanning confirmed.
- [ ] SIS and LMS connectors configured and *Test connections* green (see [INTEGRATIONS.md](INTEGRATIONS.md)); `SAQF_LMS_COURSE_KEY` matches the LMS course IDs.
- [ ] People provisioned (CSV import, SIS feed or SSO roles); Heads of Department and deans have their department/college set.
- [ ] E-mail configured and a test digest received.
- [ ] Programs without published PLOs (Architecture, EMBA, LLB, LLM) have their PLOs entered by the Heads of Department.
- [ ] Deanship of Quality confirms every policy under *Quality policies*.
