# SAQF operations and security runbook

For the university IT team that hosts and maintains SAQF. Connecting the Registrar, SIS, LMS, single
sign-on and e-mail is covered in [INTEGRATIONS.md](INTEGRATIONS.md).

## Daily operation

| What | Where | How often |
|---|---|---|
| Scheduler heartbeat: SIS sync and automatic term start (hourly), LMS imports, catalogue re-sync and rule re-checks (daily), e-mail digests and mail queue | `php bin/tick.php` (exit 0 = ok, 2 = a step failed — see *Error log*) | every 5 min |
| Audit-chain verification | `php bin/verify_audit.php` (exit 0 = intact, 2 = tampering), or *Admin → System health → Check the activity log* | nightly, and after every restore |
| Backup | Docker: the `backup` service writes `./backups/saqf-*.sql.gz[.enc]` (database) and `saqf-files-*.tar.gz[.enc]` (evidence files), reads each one back to verify it (decrypting it when `SAQF_BACKUP_PASSPHRASE` is set), copies both to `SAQF_BACKUP_OFFSITE_PATH` when set and re-checks their SHA-256 there, keeps 14 days, and writes `last-backup.json` for SAQF with the state of each part (`files_status`, `offsite_status`). A failed evidence archive fails the whole run. Elsewhere: `docker/backup.sh` from cron | nightly; automatic alert if missing, failed or incomplete |
| IT alerts | *Admin → IT alerts* (also e-mailed to administrators and posted to `SAQF_ALERT_WEBHOOK`) | raised and cleared automatically; reminders every 6 h while open |
| Go-live status | *Admin → Go-live*: demo or live for every connection, missing settings, next steps, catalogue check, templates | before go-live, then after any connection change |
| Access review | *Admin → Access review*: confirm or remove each person's access (another administrator reviews you; a role change makes the review due at once) | every 90 days (policy `security.access_review_days`); an alert opens when overdue |
| Security overview | *Admin → Security center*: every control with its live status and how to fix what is not yet on, people (administrators with two-step verification, active sessions, locked and dormant accounts) and the last 7 days of security events | weekly, and before go-live |
| Health probe | `GET /health.php` → `200 {"status":"ok","database":"ok","scheduler":"ok","app_key":"ok",…}`, or `503` when the database is unreachable or (production) no application key exists at all. `app_key` reads `ok`, `development` or `not ready (database / environment)`; the key itself is never shown. No session, no sensitive data | monitoring / load balancer, every minute |
| Health overview | *Admin → System health*: DB, environment, HTTPS, demo mode, clock, scheduler heartbeat, last integration run, audit chain, backups, evidence store, IT alerts, errors (24 h), failed sign-ins, locked accounts | as needed |
| Connections | *Admin → University systems → Test connections* | after any change on the SIS/LMS side |

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
| Backup missing, failed or incomplete (critical) | the backup service reported a failure; the last good backup is older than 26 hours; it did not include the evidence files although SAQF holds some; or (production) it was not encrypted or has no verified second copy | the next complete backup |
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

**"I didn't get my sign-in code."** Codes go to the e-mail address on the account (check *Users & access*); the person can press *Send a new code* after 30 seconds (5 per sign-in). If mail is down, the IT alert says so; meanwhile the person can use an authenticator app if they set one up, or an administrator can set `auth.mfa_required` to `admins` for the duration (audited). Administrators always use the authenticator app.

**"Sign-in says to confirm I'm not a robot."** The robot check runs in the browser and needs JavaScript; it ticks itself within a second. After 3 failed sign-ins from one network the puzzle is 16 times harder (a few seconds). `SAQF_BOT_CHECK=off` switches it off (the Security center then flags it).

**"I lost the trusted browser / a shared PC was trusted."** *Account & security → Trusted browsers → Forget*, or *This wasn't me*, which also signs out every other session and alerts IT. A password change forgets every trusted browser; so does an administrator's *Sign out everywhere* or two-step reset.

**"I forgot my password."** With university SSO, passwords are managed by the identity provider. Otherwise, when e-mail is configured, people use *Forgot your password?* on the sign-in page (a single-use link valid for 30 minutes, throttled per account and network). An administrator can always use *Manage → Reset password*, which shows a temporary password once; the user must change it at next sign-in.

**"I can't sign in with my university account."** The sign-in page shows the reason. "Not set up in SAQF yet": add the person under *Users & access* (or give them a mapped role in the identity provider when auto-provisioning is on). Refused sign-ins are listed under *Security events → Recent failed sign-ins* with reason `sso: …`. If the identity provider itself is down, administrators can still use the password form (`SAQF_PASSWORD_LOGIN=admins`).

**"A new instructor has no account / a course has no workspace."** *University systems → Recent updates* shows each SIS run with counts of unknown courses and instructors. Instructors in the SIS feed with a name are created automatically; those without one need the SIS record fixed or an account added by hand with their *SIS / HR identifier*.

**"The new term has not started."** Terms start on the `starts_on` date from the SIS calendar (policy *Start terms automatically*). *University systems → Academic calendar* shows every term; an administrator can start the next one early (reason required, audited) or add a term the SIS does not provide.

**"Grades did not arrive."** The gradebook column must be named like the assessment in the approved specification. Ignored columns are recorded in the audit log (`results.columns_ignored`). *Run scheduler now* re-reads the LMS.

**"Something went wrong" with a reference code.** Every unhandled error shows the user an 8-character reference. Search it under *Admin → Error log*. The entry holds the message, file and line, URL, user, request id and stack trace. Users never see technical details in production.

**"A number on my dashboard looks wrong."** Open the record and check its source label (University records / Timetable / Gradebook / Calculated / Carried over / Instructor). Then check *Admin → University systems → Automatic actions* to see which automated reaction produced it and when. Calculated values are re-derived from source data, so re-running the source sync or the scheduler (`php bin/tick.php --force`) recomputes them.

**"Something shows in English in the Arabic interface."** If it is data (a course or program name, a learning outcome, an assessment, a person's name), Quality or IT adds the Arabic on *Arabic wording*: the page lists everything still in English, or offers it as a spreadsheet to fill in and upload. Catalogue names normally arrive in Arabic with the Registrar sync; a correction made on that page is kept across syncs. If it is interface text, run `php bin/i18n_coverage.php https://saqf.yu.edu.sa` (demo mode) to list it, and add it to `src/Web/lang/ar/strings-6.php` or a pattern file.

**"Someone changed X."** *Admin → Activity log* filters by action prefix, actor, object and date, and exports to CSV. Each entry shows old and new values, reason, IP and request id, and whether a person or SAQF automation did it.

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

Restore drill (quarterly, and before go-live): restore into a staging instance, run `php bin/verify_audit.php` (a broken chain means the dump is incomplete or was altered), and spot-check a frozen report snapshot (*Report → View frozen snapshot* shows its SHA-256 seal). Record the date and result in the IT change log: SAQF cannot see whether a restore of the university's own backups was ever tested, so the Security center always says so. (The CI pipeline backs up and restores its own throw-away demo stack on every change; that proves the scripts, not the university's backups.)

## Application key (`SAQF_APP_KEY`)

The application key derives every student pseudonym (keyed HMAC), signs robot-check puzzles and e-mailed codes, and encrypts stored two-step verification secrets (AES-256-GCM). It is as sensitive as the database password and more important to keep: **without the exact same key, new grade imports no longer match earlier pseudonyms and stored authenticator secrets cannot be read.**

- **Production:** supply it from the environment. Generate one before the first install with `php bin/app_key.php generate` (64 random hex characters), store it in the university's password vault, and set `SAQF_APP_KEY` in `.env` / the secret store. SAQF never generates or stores a production key: the production installer refuses to start without a strong key, `/health.php` answers `503` when no key exists, and the Security center shows *Application key* as needing attention otherwise.
- **Back it up separately from the database backups** (vault entry plus a sealed offline copy held by IT security). A database backup without the key restores data whose pseudonyms cannot be extended and whose two-step secrets are unreadable.
- **Local / demo:** with no `SAQF_APP_KEY`, a random development key is generated and kept in the database. That is fine for a demo and is reported as "development".
- **Installations from before 2.5** kept their key in the database. They keep working, but production readiness says *not ready* until the key is moved, without changing it: `php bin/app_key.php export-stored` (prints it once, audited) → vault + `SAQF_APP_KEY` → restart → `php bin/app_key.php forget-stored` (refused unless `SAQF_APP_KEY` holds the same key). `php bin/app_key.php status` reports the state without printing the key.
- **Rotation is not supported in place.** A new key would change every student pseudonym (results imported afterwards would not join earlier ones for the same students) and make every stored authenticator secret unreadable. If the key is compromised: treat it as an incident, plan a migration window, re-import the term's grades from the LMS under the new key, and have every user re-enrol their authenticator app. Do not attempt this without a tested backup and a dry run on staging. SAQF does not ship a rotation tool.

## Security controls: what they do, and what they do not do

- Server-side authorization on every record. Denied attempts are logged as `security.access_denied` and appear under *Security events*.
- The audit log is **tamper-evident, not tamper-proof**: database triggers refuse UPDATE/DELETE through the application's account, and the SHA-256 hash chain reveals any row edited or removed behind the application's back when the chain is verified (nightly and on demand). Someone with database administrator rights can still drop the triggers and change rows; the chain then shows that it happened, not what was there before. Keep exported copies of the log (CSV export) off the server if independent evidence is needed. If your MySQL account cannot create triggers, install them from `database/audit_guard.sql` with a privileged account; the Security center reads whether they are present.
- Robot check on sign-in and password reset: a signed, single-use proof-of-work puzzle (15 bits; 19 after 3 failures from the network in 15 minutes) solved by the browser, plus a hidden trap field. Self-hosted, no third-party script, no cookies. Failures are recorded as `bot_*` in *Security events*.
- Two-step verification for everyone signing in with a password (policy `auth.mfa_required`, default `all`): a 6-digit code e-mailed to the university address (10 minutes, single use, 5 tries, 5 sends) or an authenticator app; administrators must use the app. *Don't ask again on this browser* trusts a browser for `auth.trusted_device_days` (30) days via an HttpOnly cookie whose hash only is stored; never offered to administrators.
- After each sign-in people see when and from where they last signed in; *Account & security* shows a security checkup, the sign-in history in plain words, trusted browsers and *This wasn't me*.
- Sign-in: Argon2id password hashes (older hashes are upgraded at the next sign-in); passwords need 10+ characters and may not be common words, keyboard runs, the person's name or the university's name; account lockout and network throttling; rate limits on changes, uploads and exports.
- Two-step verification (authenticator app, RFC 6238) with ten single-use recovery codes. Required for administrators by default (policy `auth.mfa_required`: off / admins / all); an administrator without it is sent to set it up before anything else. Secrets are stored encrypted (AES-256-GCM); a code cannot be used twice. An administrator can reset a person's two-step verification (*Users & access → Manage*).
- Administrators can be limited to campus or VPN addresses (`SAQF_ADMIN_ALLOWED_IPS`), and must re-confirm their identity (password and code) before account changes when they signed in more than 15 minutes earlier (policy `security.reauth_minutes`).
- Sessions: 30 min idle, 8 h absolute (policies), HttpOnly, SameSite=Strict, Secure under HTTPS, bound to the browser. Every session is listed under *Account & security* and can be ended remotely; changing a password ends all other sessions; sign-ins from a new device are notified (policy `security.new_device_alert`).
- University sign-in (OpenID Connect): PKCE, single-use state bound to the browser, nonce, ID-token signature verified against the provider's keys, issuer/audience/expiry checks. Refused attempts are logged. **Two-step verification for university sign-in is the identity provider's policy, not SAQF's**: SAQF's own code applies only to password sign-ins. SAQF records whether the ID token reports MFA (the `amr` claim, which many providers omit) and shows the last observation in the Security center; with `SAQF_OIDC_REQUIRE_MFA=true` it refuses tokens that do not report `mfa`. University IT must confirm that the identity provider's conditional-access policy requires MFA for SAQF.
- Outbound connections: in production SAQF refuses plain `http://` for the SIS, LMS, identity provider (including the endpoints its discovery document lists) and the alert webhook; TLS certificates are always verified and redirects are not followed. Local and demo mode accept `http://` for the stand-in servers in `tests/mock/`. The Security center names any setting that would be refused.
- Password reset links are single-use, expire after 30 minutes, are stored only as hashes and always use `SAQF_BASE_URL` (never the request's host name).
- Student identities from every grade source (Moodle, Blackboard, the LMS export folder and the manual gradebook upload) are pseudonymised (keyed HMAC) while the data is read, before anything is stored or logged; a batch keyed by digit-only identifiers is refused. There is no opt-out (`SAQF_LMS_PSEUDONYMIZE` is ignored). Error traces never include argument values. Backups are written owner-readable only.
- CSRF tokens on every change; a strict Content-Security-Policy (`script-src 'self'`, no inline scripts or event handlers anywhere), `object-src 'none'`, frame blocking, Cross-Origin-Opener/Resource-Policy; no third-party scripts or fonts.
- Access review: every `security.access_review_days` (90) days an administrator other than the person concerned confirms or removes each account's access; decisions are kept and audited, and an overdue review raises an IT alert. The only administrator cannot review themselves, so create a second administrator.
- Security **self-test** (*Security center → Run security self-test*, also every night): SAQF exercises some of its own protections on the installation (weak passwords, forged requests, an edit to the audit log, authenticator codes, encryption and modification detection, the gradebook reader's pseudonymisation, session settings); a failure raises a critical alert. It is a self-check, not a penetration test, a vulnerability scan or an independent review. *Security evidence report* (administrators) is a self-assessment: every statement in it is read from the configuration and recorded results, it lists what needs attention and what is optional or not configured, and it says what it does not cover. It carries a fingerprint that is also entered in the audit log.
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
- [ ] *Admin → Go-live*: every connection shows *Configured* (or is consciously off), *Test connections* succeeds for each, and *Check the catalogue* passes. "Configured" only means the settings are present.
- [ ] *Admin → Security center* reviewed: every control green or consciously accepted; *Run security self-test* passes; *Access review* completed by a second administrator.
- [ ] `APP_ENV=production`, `APP_DEBUG=false`, `SAQF_DEMO=false`, `SAQF_AUTO_INSTALL=production` (first start only).
- [ ] `SAQF_APP_KEY` generated, stored in the password vault with an offline copy, and set; `php bin/app_key.php status` says *ready*; `/health.php` shows `"app_key":"ok"`.
- [ ] A dedicated DB user with rights on the `saqf` schema only. Strong passwords and connector secrets kept in a secret store, never in git.
- [ ] Web server document root = `public/` (never the repository root).
- [ ] Scheduler running (container loop or cron), `/health.php` monitored, log rotation.
- [ ] Backups encrypted (`SAQF_BACKUP_PASSPHRASE` kept in the university's password vault) and copied to a share on **another machine** (`SAQF_BACKUP_OFFSITE_PATH`; SAQF can verify the copy, not where the share lives); a restore drill done and recorded; *System health → Backups* green.
- [ ] IT alerts reach people: e-mail configured or `SAQF_ALERT_WEBHOOK` set to the IT team's Teams/Slack channel.
- [ ] University SSO configured; `SAQF_PASSWORD_LOGIN=admins`; first administrator password changed and two-step verification set up; `SAQF_ADMIN_ALLOWED_IPS` set to the campus/VPN ranges.
- [ ] University IT confirms in writing that the identity provider requires MFA for SAQF; if its ID tokens carry `amr`, `SAQF_OIDC_REQUIRE_MFA=true`.
- [ ] Every outbound address is `https://` (the Security center's *Encrypted connections to university systems* is green).
- [ ] Malware-scanning policy decided by IT security: either scanning on (`--profile antivirus`, `SAQF_CLAMAV_HOST=clamav:3310`, signature updates monitored) or a documented decision that the university's own controls cover it. Without a scanner, SAQF says plainly that uploads are not scanned.
- [ ] SIS and LMS connectors configured and *Test connections* green (see [INTEGRATIONS.md](INTEGRATIONS.md)); `SAQF_LMS_COURSE_KEY` matches the LMS course IDs.
- [ ] People provisioned (CSV import, SIS feed or SSO roles); Heads of Department and deans have their department/college set.
- [ ] E-mail configured and a test digest received.
- [ ] Programs without published PLOs (Architecture, EMBA, LLB, LLM) have their PLOs entered by the Heads of Department.
- [ ] Deanship of Quality confirms every policy under *Quality policies*, including the *Course file checklist* (until then the Closeout tab says the checklist is SAQF's default).
- [ ] Records retention, accessibility and privacy notices approved by the responsible offices; an independent security review (not the self-test) completed; a pilot with one department finished (see [READINESS.md](READINESS.md)).
