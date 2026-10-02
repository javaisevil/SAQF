# SAQF: Academic Quality Automation for Al Yamamah University

[![CI](https://github.com/javaisevil/SAQF/actions/workflows/ci.yml/badge.svg)](https://github.com/javaisevil/SAQF/actions/workflows/ci.yml)

SAQF connects university academic data, learning outcomes, assessments, achievement, validation, improvement and reporting into one continuous system. It runs routine quality-assurance work in the background, so faculty, Heads of Department, Quality staff and deans spend their time on the decisions that need academic judgement.

> **Status:** SAQF 2.1, ready to deploy. It began in the FARQ hackathon (Track 2: university administration and faculty operations) and now includes connectors for the university's SIS, LMS (Moodle, Blackboard), single sign-on and e-mail, courses with several sections, bulk import of existing specifications, course-file evidence with virus scanning, NCAAA-layout Word documents, an Arabic interface, two-step verification, built-in HTTPS, encrypted off-site backups and IT alerts. Five automated test suites (392 checks) run on MySQL 8 and inside the Docker image on every change.
> Out of the box it starts in **demo mode**: fictional people, teaching assignments and student results delivered through simulated SIS/LMS feeds. In **production mode** it runs on the university's own systems once IT provides access (see [`docs/INTEGRATIONS.md`](docs/INTEGRATIONS.md)). It has not yet been piloted against YU's live systems.
> Institutional data ships as a structured snapshot of Al Yamamah University's **public** study plans; the Registrar can replace it with its own export.
> SAQF *supports NCAAA-oriented academic quality workflows*. It is not a compliance certification.

- **Presenting SAQF?** The 5-minute judges' script is [`docs/JUDGES_DEMO.md`](docs/JUDGES_DEMO.md); in the app, open **Guided tour** (`/tour.php`).
- **Checking every feature yourself?** [`docs/TEST_CASES.md`](docs/TEST_CASES.md) lists each one with the clicks and the expected result.
- **New to the project?** The plain-language guide for the whole team is [`docs/TEAM_REPORT.md`](docs/TEAM_REPORT.md).

---

## What it does

| Instead of… | SAQF… |
|---|---|
| Faculty typing course codes, credit hours, programs and prerequisites | syncs them from the study plans (Registrar catalogue, re-synced daily) and shows where each value came from |
| Creating a course record each semester | creates the course workspace automatically when the SIS assigns an instructor, and inherits the approved specification |
| Someone starting each new semester | starts the term on its SIS start date by itself: the previous term is closed and its reports frozen, every workspace is created, new instructors get accounts |
| Re-submitting the whole specification every term | uses a **change-based** workflow: unchanged specs carry forward with no approval, and reviewers see only the changes |
| QA catching missing fields, wrong weight totals and unmapped CLOs | runs **35 deterministic rules** continuously while the record is built; obvious errors never reach a person |
| One coordinator chasing every section's instructor | handles **multi-section courses**: the coordinator owns the shared specification, section instructors add results and evidence, and achievement is compared per section (a large gap is flagged) |
| Re-typing the university's existing specifications | **imports** approved specifications from one CSV file; each course is checked completely before anything is written |
| Collecting exam papers for accreditation at the last minute | **asks for the evidence** when results arrive, scans and stores it outside the web server, and lists it in the course report |
| Calculating CLO/PLO achievement by hand | imports grades as the LMS publishes them (Moodle, Blackboard or gradebook exports), then calculates CLO and PLO achievement with a configurable method |
| Chasing people by e-mail | e-mails each person a digest of only the items that need them (they can opt out) |
| Noticing missed targets at report time | flags the gap on import (first, recurring or worsening), drafts an improvement action with an owner and due date, and checks next term whether performance changed |
| Writing reports | generates them from the structured data, as pages and as **NCAAA-layout Word documents** (English or Arabic); each closed term is frozen into a hash-sealed snapshot |
| QA inspecting every record | gives QA an **Exception Center**: only data conflicts, policy exceptions, quality risks and sampled auto-approvals reach a person |

## Architecture

```
 University systems (connectors)    Registrar catalogue · SIS (CSV/API) ·    src/Integration
                                    LMS (Moodle/Blackboard/CSV)
            │ sync / events
 Academic-quality data model        programs, plans, courses, offerings,     database/schema.sql
            │                       CLOs, PLOs, assessments, results…
 Rules engine (deterministic)       35 rules → findings with auto-resolve    src/Quality/Rules.php, Findings.php
 Assisted insights (labelled)       TF-IDF mapping suggestions, trends       src/Quality/Intelligence.php
            │ event bus             spec.changed, results.imported, …        src/Core/Events.php, Quality/Engine.php
 Workflow & routing                 change-based approvals, red/amber/green  src/Quality/Specs.php, Overrides.php
            │
 Role views & reports               Action Center per role, live reports,    public/*.php, src/Quality/Reports.php,
                                    snapshots, Word exports, evidence         NcaaaExport.php, Evidence.php
 Cross-cutting                      RBAC (server-side), hash-chained audit,  src/Security, src/Core/Audit.php
                                    SSO, two-step sign-in, sessions, alerts, src/Security, src/Core
                                    policies, e-mail, migrations, Arabic UI   src/Web/I18n.php
```

There is no framework and no third-party library: plain **PHP 8.1+** with PDO, **MySQL 8**, server-rendered pages and under 200 lines of vanilla JavaScript (no inline scripts, so a strict Content-Security-Policy applies). Word files, QR codes, ZIP packaging and authenticator codes are generated in plain PHP. The goal is that any university web team can host and maintain it. More detail: [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md).

## Requirements

- PHP 8.1+ (8.3 recommended) with `pdo_mysql`, `mbstring`, `curl` and `openssl`
- MySQL 8.0+ (MariaDB 10.6+ should work but is not tested). The database user needs `TRIGGER` privilege. With binary logging on, set `log_bin_trust_function_creators=1` so the append-only audit triggers can be created.
- Apache or Nginx with the **document root set to `public/`**, or Docker

## Quick start: demo (Docker)

```bash
cp .env.example .env          # change the passwords
docker compose up --build     # first start installs the schema and the demo scenario
# open http://localhost:8080 and press "Guided 5-minute tour"
```

`SAQF_AUTO_INSTALL=demo` installs the demo on first start only. It never re-installs a database that already has tables. Reset the story at any time with `docker compose exec app php bin/install.php --demo --fresh`.

## Quick start: production (Docker)

```bash
cp .env.example .env
# set APP_ENV=production, SAQF_DEMO=false, SAQF_AUTO_INSTALL=production, strong passwords,
# SAQF_DOMAIN and SAQF_BASE_URL=https://…, SAQF_PORT=127.0.0.1:8080, SAQF_BACKUP_PASSPHRASE,
# SAQF_BACKUP_OFFSITE_PATH, and the SIS / LMS / SSO / e-mail settings (docs/INTEGRATIONS.md)
docker compose --profile https up -d --build                 # add --profile antivirus for ClamAV
docker compose logs app | grep -A2 "Administrator account"   # one-time admin password
```

The `https` profile adds a Caddy proxy that obtains and renews the certificate for `SAQF_DOMAIN`
(or use the university's certificate or load balancer). The stack runs the scheduler, applies
database migrations on every start, answers `/health.php`, and the `backup` service writes a
verified, encrypted backup of the database and the evidence files every night, copies it off the
server and reports its status to SAQF. Sign in as `admin` (you will set up two-step verification
first), change the password, open *Administration → Security center* and *Integrations → Test
connections*, then add or import people under *Users & access* (or let SSO and the SIS feed
provision them). The full go-live checklist is in [`docs/OPERATIONS.md`](docs/OPERATIONS.md).

## Quick start: local PHP

```bash
# 1. a MySQL 8 server, then configuration (env vars or config.local.php)
cp config.example.php config.local.php     # set SAQF_DB_* and APP_ENV=local, SAQF_DEMO=true
# 2. install the schema, sync YU study plans and replay the demo story (~30 s)
php bin/install.php --demo
# 3. run
php -S 127.0.0.1:8080 -t public
```

`php bin/install.php --demo --fresh` drops and rebuilds the database. It is refused when `APP_ENV=production`.

## Demo accounts

Eight fictional people, one per role plus two colleagues. On the sign-in page each role has a **one-click button**, and every page has a **Switch role** menu in the yellow demo bar (demo mode only; in production these do not exist). The password for all of them is **`Yamamah@2026`**; the administrator also needs an authenticator code, which demo mode prints on the second screen.

| Username | Role | What to look at |
|---|---|---|
| `f.omar` | Faculty (coordinator) | **SWE 412**: a draft revision with 4 deterministic issues — fix them and the course turns *Ready*. **SWE 401**: two sections, section 02 trails by 15.7 points; the improvement action is *improved following the intervention* (53.3% → 63.3%); the Evidence tab asks for the midterm paper. **CIS 491**: a policy-exception request waiting at QA |
| `f.sara` | Faculty (section instructor) | Teaches section 02 of SWE 401 and SWE 302 (midterm grades scheduled in the LMS: publishing them triggers an early warning) |
| `f.noura` | Faculty, Accounting & Finance | ACC 311 and MIS 327, whose specifications reached SAQF through the bulk import |
| `hod.ced` | Head of Department | Department exceptions only: recurring CLO gap, SWE SO2 below target; approvals by difference; specification import |
| `qa.director` | Quality | Exception Center, CIS 491 override decision, sampled auto-approvals, policies, specification import |
| `dean.coe` | College Dean | College health → department → program → course drill-down |
| `vp.academic` | University leadership | Programs needing intervention, recurring issues, quality cycles |
| `it.admin` | System administrator | Security center, health, IT alerts, users and sessions, security events, audit log (verify, CSV export), error log, integration simulator |

The demo clock is anchored to **Fall 2026, week 6** at install time and then runs forward, so the story looks the same whenever you install it. Set `SAQF_DEMO_CLOCK=real` to use today's date.

**Live automation to show:** as `it.admin`, open *Integrations* and use the simulator. *Publish now* (LMS midterm grades) recalculates achievement and raises an early warning. *Publish assignment* (SIS) creates a new instructor's account and course workspace. *Activate term* (Spring 2027) freezes Fall 2026 reports, rolls every course forward and evaluates last term's improvement actions.

## Roles

- **Faculty:** an Action Center with only what needs them, and one workspace per course (overview, outcomes and assessment, results, evidence, improvement, report, history). They type only academic content. In a course with several sections, the coordinator owns the specification and section instructors contribute results and evidence.
- **Head of Department:** department exceptions, changed specifications (the diff only), course assignment fallback, PLO changes with impact analysis.
- **Quality (QA):** Exception Center, override decisions with reasons, QA sample of auto-cleared approvals, configurable quality policies, bulk import of existing specifications (also open to Heads of Department for their own courses).
- **Dean:** college health with drill-down. Persistent program-level problems escalate here.
- **University leadership:** institution-wide view of interventions, recurrence, overdue actions and quality cycles.
- **System administrator:** operations and security (Security center, IT alerts, users and sessions, audit and error logs, integrations). By design (separation of duties), administrators cannot make academic decisions.

Everyone can switch the interface between **English and Arabic** (العربية, right to left) at the top of every page; the choice is saved on their account.

## Tests

```bash
sh bin/test_all.sh                          # every suite on a fresh demo database, with a plain summary
docker compose exec app sh bin/test_all.sh  # the same inside the Docker image
```

| Suite | Checks | What it proves |
|---|---|---|
| `tests/automation_test.php` | 58 | the quality loop end to end: event pipeline, rollover, gaps, effectiveness, impact analysis, overrides, audit tamper detection |
| `tests/production_test.php` | 96 | SIS/LMS connectors against stand-in Moodle, Blackboard and SIS API, export folders, automatic term start, accounts, SMTP, password reset, SSO token validation |
| `tests/http_smoke.php` | 118 | every page as every role, cross-role and cross-scope denials, section-instructor limits, CSRF, lockout, error log, maintenance mode |
| `tests/sso_test.php` | 29 | university sign-in end to end, including replayed, forged and wrong-audience tokens, and the administrator's two-step sign-in |
| `tests/features_test.php` | 91 | sections, specification import, evidence (content checks, stand-in ClamAV, fail-closed), Word exports, TOTP/QR/recovery codes, password policy, sessions, step-up re-authentication, IP rules, trusted proxies, CSP, rate limits, IT alerts with a stand-in webhook, backup monitoring, Arabic interface, demo shortcuts refused in production |

Each suite reinstalls the demo first; run `php bin/install.php --demo --fresh` afterwards to reset the story. The stand-in systems live in `tests/mock/`; nothing leaves the machine. CI (`.github/workflows/ci.yml`) runs every suite on MySQL 8.0, then starts the Docker stack with the HTTPS proxy, runs the HTTP suite against Apache, checks HTTPS, writes an encrypted backup and restores it. Plain-language test cases: [`docs/TEST_CASES.md`](docs/TEST_CASES.md).

## Production notes (summary)

- `APP_ENV=production`, `APP_DEBUG=false`, HTTPS (the `https` compose profile, or the university's proxy listed in `SAQF_TRUSTED_PROXIES`), `SAQF_BASE_URL` set. Demo mode, its one-click sign-in and `--fresh` are disabled in production.
- Security: two-step verification is required for administrators (policy `auth.mfa_required`), administrators can be limited to campus networks (`SAQF_ADMIN_ALLOWED_IPS`), uploads can be virus-scanned (`SAQF_CLAMAV_HOST`), and the Security center shows every control's live status.
- `php bin/install.php` (without `--demo`) creates the schema, syncs the catalogue and the SIS calendar, and prints a one-time administrator password. `php bin/migrate.php` applies upgrades (automatic in Docker).
- Scheduler: `*/5 * * * * php bin/tick.php` (built into the Docker image). Nightly: `php bin/verify_audit.php` (exit code 2 = tampering).
- Backups: the compose `backup` service (database + evidence files, verified, encrypted with `SAQF_BACKUP_PASSPHRASE`, copied to `SAQF_BACKUP_OFFSITE_PATH`), or `docker/backup.sh` from cron. Restore with `docker/restore.sh`; the audit chain is verified afterwards.
- Monitoring: `GET /health.php` (200 / 503), plus **IT alerts** (failing connectors, stalled scheduler, missing backups, broken audit chain, mail failures, blocked malware, password guessing) in SAQF, by e-mail and to a Teams/Slack webhook (`SAQF_ALERT_WEBHOOK`).
- University connections, SSO and e-mail: [`docs/INTEGRATIONS.md`](docs/INTEGRATIONS.md). Operations runbook (lockouts, error references, maintenance mode, upgrades, incidents, go-live checklist): [`docs/OPERATIONS.md`](docs/OPERATIONS.md).

## Before go-live and known limits

- **University access is needed.** The connectors are built and tested against stand-ins; YU IT must provide the SIS export or API, an LMS token, an identity-provider app registration and a mail account (all configuration, no code).
- **Policies need approval.** The achievement method (threshold 70%, default target 70%) and other thresholds are **configurable defaults, not YU-approved methodology**. The Deanship of Quality must confirm them under *Quality policies*.
- **Program outcomes.** SWE PLOs are the ABET CAC student outcomes YU publishes. CNE and IE use ABET EAC general outcomes as placeholders. Business programs, MBA and MCS use the PLOs on their YU pages. Architecture, EMBA, LLB and LLM have no published PLOs, so SAQF flags them until Heads of Department enter them (they can, in SAQF).
- **Sign-in** uses OpenID Connect (Microsoft Entra ID / 365, Google, Keycloak, ADFS 2019+, Okta). A SAML-only identity provider needs an OIDC bridge.
- **Interface language:** English and Arabic (right to left). The Arabic interface translates every screen's labels, messages and automatic findings (1,100 phrases and 250 patterns, `src/Web/lang/ar.php`); course titles, outcome statements and names are shown as entered. `php bin/i18n_coverage.php <url>` lists any English left on a screen.
- **Tested** on MySQL 8.0 and PHP 8.3, directly and in the Docker stack (Apache). MariaDB is not tested.
- Assisted insights are text-similarity and statistics heuristics. They are not AI and are never applied without a person.

## Connecting the university's systems

| System | Choose with | Options |
|---|---|---|
| SIS (calendar, teaching assignments) | `SAQF_SIS_SOURCE` | `file` (nightly CSV export), `rest` (integration API), `none` |
| LMS (grades) | `SAQF_LMS_SOURCE` | `moodle`, `blackboard`, `file` (gradebook CSV exports), `none` |
| Registrar catalogue | `SAQF_INSTITUTION_DIR` | the bundled YU snapshot, or a Registrar export in the same format |
| Sign-in | `SAQF_OIDC_ISSUER`, `SAQF_OIDC_CLIENT_ID`, `SAQF_OIDC_CLIENT_SECRET` | any OpenID Connect provider; `SAQF_PASSWORD_LOGIN=admins` keeps a break-glass password for administrators |
| E-mail | `SAQF_MAIL_HOST` and friends | any SMTP server (STARTTLS or TLS) |

Every setting is listed in `.env.example`. File formats, API contracts, Moodle/Blackboard/Entra ID setup steps and troubleshooting: [`docs/INTEGRATIONS.md`](docs/INTEGRATIONS.md). New connectors implement one of the three interfaces in `src/Integration/Sources.php`; nothing else changes.

## Repository layout

```
public/      web root (pages, assets, sso.php, health.php) — the only directory a web server should expose
src/         Core (db, audit, events, policy, mail, migrations), Security (auth, SSO, accounts), Integration (connectors), Quality (rules, engine…), Web
database/    schema.sql, migrations/, audit_guard.sql
data/yu/     YU institutional snapshot (from public study-plan PDFs) + SOURCES.md
data/demo/   simulated SIS/LMS feeds for the demo scenario
storage/     inbox/ for SIS and LMS export files, evidence/ (uploaded course-file evidence; runtime, not committed)
bin/         install, migrate, tick (scheduler), verify_audit, wait_for_db, build_demo_data, test_all.sh, i18n_coverage
tests/       automation_test, production_test, http_smoke, sso_test, features_test, mock/ (stand-in systems)
docs/        JUDGES_DEMO.md, TEST_CASES.md, TEAM_REPORT.md, ARCHITECTURE.md, INTEGRATIONS.md, OPERATIONS.md, AUDIT_BEFORE.md, demo/
docker/      Apache/PHP hardening, entrypoint, backup and restore scripts, Caddyfile (HTTPS)
```

The original AQMS prototype this version was built from is preserved in git history: see the commit *Import AQMS hackathon baseline*.
