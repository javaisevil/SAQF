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
| Front end | Server-rendered HTML, one CSS file and ~390 lines of vanilla JavaScript; no inline scripts (strict CSP) | `public/assets/` |
| Documents | Word (.docx), ZIP, QR codes and TOTP generated in plain PHP | `src/Web/Docx.php`, `Zip.php`, `Qr.php`, `src/Security/Totp.php` |
| Password hashing | Argon2id where the PHP build provides it, otherwise bcrypt (the Security center says which) | `src/Security/Auth.php` |
| Encryption | AES-256-GCM for stored secrets (OpenSSL); HMAC-SHA-256 student pseudonyms | `src/Core/Secrets.php` |
| "Assisted insights" | TF-IDF text similarity and simple statistics, always labelled and never applied without a person. Not AI | `src/Quality/Intelligence.php` |

## Deployment (Docker Compose)

| Service | Image | Status |
|---|---|---|
| `app` | built from `Dockerfile` (php:8.3-apache) | always |
| `db` | `mysql:8.0` | always |
| `backup` | `mysql:8.0` running `docker/backup.sh` (mysqldump, gzip, tar, openssl AES-256 + PBKDF2, sha256sum) | always; encryption and second copy only when configured |
| `proxy` | `caddy:2` (HTTPS, automatic certificates) | optional profile `https` |
| `clamav` | `clamav/clamav:stable` | optional profile `antivirus` |

## Development and quality

| Tool | Use |
|---|---|
| Plain PHP test scripts + `php -S` stand-in servers (`tests/mock/`) | 10 suites run by `bin/test_all.sh` (no PHPUnit) |
| GitHub Actions (`.github/workflows/ci.yml`) | lint, every suite on MySQL 8.0, then the Docker stack with HTTPS, an encrypted backup and a restore |
| Python 3 (standard library only) | `tools/build_yu_snapshot.py`, used once to build the public YU catalogue snapshot |

## Implemented (working in the demo, tested against stand-ins)

Course workspaces from the timetable, change-based specification approval, 35 deterministic rules,
CLO/PLO achievement, improvement loop with effectiveness check, evidence with type/content checks,
course file closeout with a Quality-configured checklist and human evidence review, course file
package with checksums, NCAAA-layout Word exports (English/Arabic), Arabic RTL interface, role-based
access, tamper-evident audit log, sign-in protections (two-step codes, robot check, lockout, sessions),
Security center with recorded status, connectors for SIS files, a generic SIS REST API, Moodle,
Blackboard, LMS export files, OIDC sign-in and SMTP, and a read-only integration dry run.

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
- Arabic wording for some dynamic detail sentences (the closeout details and Security center status
  lines are still English in the Arabic interface; `php bin/i18n_coverage.php` lists them).
