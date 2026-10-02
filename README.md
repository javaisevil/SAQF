# SAQF: Academic Quality Automation for Al Yamamah University

SAQF connects university academic data, learning outcomes, assessments, achievement, validation, improvement and reporting into one continuous system. It runs routine quality-assurance work in the background, so faculty, Heads of Department, Quality staff and deans spend their time on the decisions that need academic judgement.

> **Status:** SAQF 2.0, ready to deploy. It began in the FARQ hackathon (Track 2: university administration and faculty operations) and now includes connectors for the university's SIS, LMS (Moodle, Blackboard), single sign-on and e-mail, automatic semester rollover, database upgrades, backups and health checks, with four automated test suites run on MySQL 8 and in Docker.
> Out of the box it starts in **demo mode**: fictional people, teaching assignments and student results delivered through simulated SIS/LMS feeds. In **production mode** it runs on the university's own systems once IT provides access (see [`docs/INTEGRATIONS.md`](docs/INTEGRATIONS.md)). It has not yet been piloted against YU's live systems.
> Institutional data ships as a structured snapshot of Al Yamamah University's **public** study plans; the Registrar can replace it with its own export.
> SAQF *supports NCAAA-oriented academic quality workflows*. It is not a compliance certification.

The plain-language guide for the whole team is in [`docs/TEAM_REPORT.md`](docs/TEAM_REPORT.md). It covers what changed, why, and which tools are used where.

---

## What it does

| Instead of… | SAQF… |
|---|---|
| Faculty typing course codes, credit hours, programs and prerequisites | syncs them from the study plans (Registrar catalogue, re-synced daily) and shows where each value came from |
| Creating a course record each semester | creates the course workspace automatically when the SIS assigns an instructor, and inherits the approved specification |
| Someone starting each new semester | starts the term on its SIS start date by itself: the previous term is closed and its reports frozen, every workspace is created, new instructors get accounts |
| Re-submitting the whole specification every term | uses a **change-based** workflow: unchanged specs carry forward with no approval, and reviewers see only the changes |
| QA catching missing fields, wrong weight totals and unmapped CLOs | runs **33 deterministic rules** continuously while the record is built; obvious errors never reach a person |
| Calculating CLO/PLO achievement by hand | imports grades as the LMS publishes them (Moodle, Blackboard or gradebook exports), then calculates CLO and PLO achievement with a configurable method |
| Chasing people by e-mail | e-mails each person a digest of only the items that need them (they can opt out) |
| Noticing missed targets at report time | flags the gap on import (first, recurring or worsening), drafts an improvement action with an owner and due date, and checks next term whether performance changed |
| Writing reports | generates them from the structured data; each closed term is frozen into a hash-sealed snapshot |
| QA inspecting every record | gives QA an **Exception Center**: only data conflicts, policy exceptions, quality risks and sampled auto-approvals reach a person |

## Architecture

```
 University systems (connectors)    Registrar catalogue · SIS (CSV/API) ·    src/Integration
                                    LMS (Moodle/Blackboard/CSV)
            │ sync / events
 Academic-quality data model        programs, plans, courses, offerings,     database/schema.sql
            │                       CLOs, PLOs, assessments, results…
 Rules engine (deterministic)       33 rules → findings with auto-resolve    src/Quality/Rules.php, Findings.php
 Assisted insights (labelled)       TF-IDF mapping suggestions, trends       src/Quality/Intelligence.php
            │ event bus             spec.changed, results.imported, …        src/Core/Events.php, Quality/Engine.php
 Workflow & routing                 change-based approvals, red/amber/green  src/Quality/Specs.php, Overrides.php
            │
 Role views & reports               Action Center per role, live reports,    public/*.php, src/Quality/Reports.php
                                    immutable snapshots
 Cross-cutting                      RBAC (server-side), hash-chained audit,  src/Security, src/Core/Audit.php
                                    policies, automation ledger, error log,
                                    SSO (OpenID Connect), e-mail, migrations  src/Security, src/Core
```

There is no framework and no third-party library: plain **PHP 8.1+** with PDO, **MySQL 8**, server-rendered pages and about 130 lines of vanilla JavaScript. The goal is that any university web team can host and maintain it. More detail: [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md).

## Requirements

- PHP 8.1+ (8.3 recommended) with `pdo_mysql`, `mbstring`, `curl` and `openssl`
- MySQL 8.0+ (MariaDB 10.6+ should work but is not tested). The database user needs `TRIGGER` privilege. With binary logging on, set `log_bin_trust_function_creators=1` so the append-only audit triggers can be created.
- Apache or Nginx with the **document root set to `public/`**, or Docker

## Quick start: demo (Docker)

```bash
cp .env.example .env          # change the passwords
docker compose up --build     # first start installs the schema and the demo scenario
# open http://localhost:8080
```

`SAQF_AUTO_INSTALL=demo` installs the demo on first start only. It never re-installs a database that already has tables.

## Quick start: production (Docker)

```bash
cp .env.example .env
# set APP_ENV=production, SAQF_DEMO=false, SAQF_AUTO_INSTALL=production, strong passwords,
# SAQF_BASE_URL, and the SIS / LMS / SSO / e-mail settings (docs/INTEGRATIONS.md)
docker compose up -d --build
docker compose logs app | grep -A2 "Administrator account"   # one-time admin password
```

Put HTTPS in front (reverse proxy or load balancer, then `SAQF_TRUST_PROXY=true`). The stack runs the
scheduler, applies database migrations on every start, answers `/health.php`, and the `backup`
service writes nightly compressed backups to `./backups`. Sign in as `admin`, change the password,
open *Administration → Integrations → Test connections*, then add or import people under
*Users & access* (or let SSO and the SIS feed provision them).

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

All demo accounts use the password **`Yamamah@2026`**. They are fictional people and are listed on the sign-in page only while demo mode is on.

| Username | Role | What to look at |
|---|---|---|
| `f.omar` | Faculty | SWE 401: CLO3 missed its target two terms running, and the improvement action is shown as *improved following the intervention* (53.3% → 63.3%) |
| `f.faisal` | Faculty | SWE 412 draft revision with 4 deterministic issues. Fix them and the course turns *Ready* |
| `f.yousef` | Faculty | SWE 411 needs its first specification. CIS 491 has a policy-exception request waiting at QA |
| `f.sara` | Faculty | SWE 302 midterm grades are scheduled in the LMS. Publishing them triggers an early warning |
| `hod.ced` | Head of Department | Department exceptions only: recurring CLO gap, SWE SO2 below target |
| `qa.director` | Quality | Exception Center, CIS 491 override decision, sampled auto-approvals, policies |
| `dean.coe` / `dean.cob` | College Dean | College health → department → program → course drill-down |
| `vp.academic` | University leadership | Programs needing intervention, recurring issues, quality cycles |
| `it.admin` | System administrator | Health, users and lockouts, security events, audit log (verify, CSV export), error log, integration simulator |

The demo clock is anchored to **Fall 2026, week 6** at install time and then runs forward, so the story looks the same whenever you install it. Set `SAQF_DEMO_CLOCK=real` to use today's date.

**Live automation to show:** as `it.admin`, open *Integrations* and use the simulator. *Publish now* (LMS midterm grades) recalculates achievement and raises an early warning. *Publish assignment* (SIS) creates a new course workspace. *Activate term* (Spring 2027) freezes Fall 2026 reports, rolls every course forward and evaluates last term's improvement actions.

## Roles

- **Faculty:** an Action Center with only what needs them, and one workspace per course (overview, outcomes and assessment, results, improvement, report, history). They type only academic content.
- **Head of Department:** department exceptions, changed specifications (the diff only), course assignment fallback, PLO changes with impact analysis.
- **Quality (QA):** Exception Center, override decisions with reasons, QA sample of auto-cleared approvals, configurable quality policies.
- **Dean:** college health with drill-down. Persistent program-level problems escalate here.
- **University leadership:** institution-wide view of interventions, recurrence, overdue actions and quality cycles.
- **System administrator:** operations and security. By design (separation of duties), administrators cannot make academic decisions.

## Tests

```bash
php bin/install.php --demo --fresh && php tests/automation_test.php    # 57 automation scenarios (event pipeline, rollover, gaps, effectiveness, impact, audit tamper detection…)
php bin/install.php --demo --fresh && php tests/production_test.php    # 96 checks: SIS/LMS connectors (stand-in Moodle, Blackboard, SIS API), export folders, automatic term start, accounts, SMTP, password reset, SSO token validation
php bin/install.php --demo --fresh && php -S 127.0.0.1:8080 -t public &
php tests/http_smoke.php http://127.0.0.1:8080                         # 101 checks: every page as every role, cross-role and cross-scope denials, CSRF, lockout, error log, maintenance mode
php bin/install.php --demo --fresh && php tests/sso_test.php            # 28 checks: university sign-in end to end, including replay, forged and wrong-audience tokens
php bin/install.php --demo --fresh                                      # reset the demo afterwards (the tests change data)
```

The stand-in systems live in `tests/mock/`; nothing leaves the machine. CI (`.github/workflows/ci.yml`) runs all four suites on MySQL 8.0 and the HTTP suite against the Docker image.

## Production notes (summary)

- `APP_ENV=production`, `APP_DEBUG=false`, HTTPS in front (`SAQF_TRUST_PROXY=true` behind a proxy), `SAQF_BASE_URL` set. Demo mode and `--fresh` are disabled in production.
- `php bin/install.php` (without `--demo`) creates the schema, syncs the catalogue and the SIS calendar, and prints a one-time administrator password. `php bin/migrate.php` applies upgrades (automatic in Docker).
- Scheduler: `*/5 * * * * php bin/tick.php` (built into the Docker image). Nightly: `php bin/verify_audit.php` (exit code 2 = tampering).
- Backups: the compose `backup` service, or `docker/backup.sh` from cron. Verify the audit chain after every restore.
- Monitoring: `GET /health.php` (200 / 503).
- University connections, SSO and e-mail: [`docs/INTEGRATIONS.md`](docs/INTEGRATIONS.md). Operations runbook (lockouts, error references, maintenance mode, upgrades, incidents, go-live checklist): [`docs/OPERATIONS.md`](docs/OPERATIONS.md).

## Before go-live and known limits

- **University access is needed.** The connectors are built and tested against stand-ins; YU IT must provide the SIS export or API, an LMS token, an identity-provider app registration and a mail account (all configuration, no code).
- **Policies need approval.** The achievement method (threshold 70%, default target 70%) and other thresholds are **configurable defaults, not YU-approved methodology**. The Deanship of Quality must confirm them under *Quality policies*.
- **Program outcomes.** SWE PLOs are the ABET CAC student outcomes YU publishes. CNE and IE use ABET EAC general outcomes as placeholders. Business programs, MBA and MCS use the PLOs on their YU pages. Architecture, EMBA, LLB and LLM have no published PLOs, so SAQF flags them until Heads of Department enter them (they can, in SAQF).
- **Sign-in** uses OpenID Connect (Microsoft Entra ID / 365, Google, Keycloak, ADFS 2019+, Okta). A SAML-only identity provider needs an OIDC bridge.
- **Interface language:** English. Course and program data may contain Arabic, and e-mails handle Arabic text.
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
storage/     inbox/ for SIS and LMS export files (runtime, not committed)
bin/         install, migrate, tick (scheduler), verify_audit, wait_for_db, build_demo_data
tests/       automation_test.php, production_test.php, http_smoke.php, sso_test.php, mock/ (stand-in systems)
docs/        TEAM_REPORT.md, ARCHITECTURE.md, INTEGRATIONS.md, OPERATIONS.md, AUDIT_BEFORE.md
docker/      Apache/PHP hardening, the container entrypoint and the backup script
```

The original AQMS prototype this version was built from is preserved in git history: see the commit *Import AQMS hackathon baseline*.
