# SAQF technology stack (one page)

Only what the repository or its Docker/CI configuration actually uses is listed. There are no package
manifests (no `composer.json`, `package.json` or `requirements.txt`): SAQF has **no third-party
libraries**, no paid services, and **no AI / LLM API** inside the product.

## Runtime

| Layer | Technology | Where |
|---|---|---|
| Language | PHP 8.1+ (tested on 8.3) with `pdo_mysql`, `mbstring`, `curl`, `openssl` | `src/`, `public/`, `bin/` |
| Database | MySQL 8.0 (MariaDB untested) | `database/schema.sql`, `database/migrations/` |
| Web server | Apache (`php:8.3-apache` image) with security headers; or any server with `public/` as document root | `Dockerfile`, `docker/apache-saqf.conf`, `docker/php.ini` |
| Front end | Server-rendered HTML, one CSS file and a small amount of vanilla JavaScript (`app.js`, `passkey.js`, `evidence.js`); no inline scripts (strict CSP), nothing loaded from outside the server | `public/assets/` |
| Documents | Word (.docx), ZIP, QR codes and TOTP generated in plain PHP | `src/Web/Docx.php`, `Zip.php`, `Qr.php`, `src/Security/Totp.php` |
| Password hashing | Argon2id where the PHP build provides it, otherwise bcrypt (the Security center says which) | `src/Security/Auth.php` |
| Second factors | TOTP (authenticator app), e-mailed codes, **passkeys (WebAuthn)**: a small hand-written implementation (ES256, attestation "none", user verification required) that has **not been independently reviewed** | `src/Security/Mfa.php`, `Totp.php`, `WebAuthn.php`, `Cbor.php`, `Passkeys.php` |
| Integration | read-only connectors; **mapped JSON API** connectors configured by a validated mapping file (no code) with bearer, API-key or OAuth2 client-credentials authentication | `src/Integration/`, `docs/mappings/`, `docs/openapi/` |
| Tamper evidence | hash-chained audit log, append-only triggers, external witnesses (e-mail/webhook), browser CSP violation reports | `src/Core/Audit.php`, `src/Security/Witness.php`, `public/csp_report.php` |
| Encryption | AES-256-GCM for stored secrets (OpenSSL); HMAC-SHA-256 student pseudonyms | `src/Core/Secrets.php` |
| "Assisted insights" | TF-IDF text similarity and simple statistics, always labelled and never applied without a person. Not AI | `src/Quality/Intelligence.php` |

## Deployment (Docker Compose)

| Service | Image | Status |
|---|---|---|
| `app` | built from `Dockerfile` (php:8.3-apache) | always |
| `db` | `mysql:8.0` | always |
| `backup` | `mysql:8.0` running `docker/backup.sh` (mysqldump, gzip, tar, openssl AES-256 + PBKDF2, sha256sum) | always; encryption and second copy only when configured |
| `proxy` | `caddy:2` (HTTPS, automatic certificates) | optional profile `https` |
| production overlay | `docker-compose.prod.yml`: secrets from files, read-only root, `no-new-privileges`, capabilities dropped, ports on 127.0.0.1, resource and log limits | `bin/make_secrets.sh`; verified by the CI job *Hardened production stack* |
| `clamav` | `clamav/clamav:stable` | optional profile `antivirus` |

## Development and quality

| Tool | Use |
|---|---|
| Plain PHP test scripts + `php -S` stand-in servers (`tests/mock/`) | the suites listed in `bin/test_all.sh` (no PHPUnit); run it for the current counts |
| Playwright + Chromium (outside the repository's runtime) | manual browser checks: `tests/e2e/passkeys.mjs`, `evidence_upload.mjs`, `a11y.mjs` (axe-core, WCAG 2.2 A/AA rules) |
| GitHub Actions (`.github/workflows/ci.yml`) | lint, every suite on MySQL 8.0, the Docker stack with HTTPS, an encrypted backup and restore drill, the hardened production stack, and an informational Trivy scan (does not fail the build) |
| Dependabot (`.github/dependabot.yml`) | update pull requests for GitHub Actions and the Docker base images |
| Python 3 (standard library only) | `tools/build_yu_snapshot.py`, used once to build the public YU catalogue snapshot |

## Implemented (working in the demo, tested against stand-ins)

Course workspaces from the timetable, change-based specification approval, 35 deterministic rules,
CLO/PLO achievement, improvement loop with effectiveness check, evidence with type/content checks
(several files at once, with suggestions from file names that a person corrects), gradebook upload
that shows what SAQF understood before anything is written, plain "facts" next to the report (numbers,
never meaning), deadline-aware course-file reminders, a closeout board for Heads of Department and
Quality, course file closeout with a Quality-configured checklist and human evidence review, course
file package with checksums, NCAAA-layout Word exports (English/Arabic), Arabic RTL interface,
role-based access, tamper-evident audit log with external witnesses, security incident register with a
72-hour notification clock (decisions stay with people), passkeys, sign-in protections (two-step
codes, robot check, lockout, sessions), Security center with recorded status, production preflight,
restore drill, connectors for SIS files, a generic SIS REST API, Moodle, Blackboard, LMS export files,
**mapped JSON APIs for the SIS and LMS grades**, OIDC sign-in and SMTP, and a read-only integration
dry run (`bin/dry_run.php`) and mapping checker (`bin/mapping_check.php`).

## Optional controls (off until configured)

University SSO (`SAQF_OIDC_*`), refusal of SSO tokens without MFA (`SAQF_OIDC_REQUIRE_MFA`), e-mail
(`SAQF_MAIL_*`), virus scanning (`SAQF_CLAMAV_HOST`), backup encryption (`SAQF_BACKUP_PASSPHRASE`) and
second copy (`SAQF_BACKUP_OFFSITE_PATH`), HTTPS proxy (`--profile https`), administrator network
restriction (`SAQF_ADMIN_ALLOWED_IPS`), IT alert webhook (`SAQF_ALERT_WEBHOOK`). The Security center
shows each one as configured or not.

## Planned / not done

- Edugate (SIS) and university LMS connection: awaiting the approved API or export, scopes and IT
  sign-off ([integration contract](docs/INTEGRATIONS.md#edugate-and-lms-integration-contract-awaiting-university-it)). No Edugate adapter exists.
- Restore drill on the university's infrastructure, independent security review, retention and
  accessibility policy, department pilot ([docs/READINESS.md](docs/READINESS.md)).
- Independent review of the passkey (WebAuthn/CBOR) code and a penetration test: neither has been done.
- Arabic wording: every screen reached by the automated crawl is translated; the crawler
  (`php bin/i18n_coverage.php`) and the source checker (`php bin/i18n_check.php --extract public src`)
  list what is left, mostly data shown as entered.
