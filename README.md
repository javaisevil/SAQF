# SAQF: Academic Quality Automation for Al Yamamah University

SAQF connects university academic data, learning outcomes, assessments, achievement, validation, improvement and reporting into one continuous system. It runs routine quality-assurance work in the background, so faculty, Heads of Department, Quality staff and deans spend their time on the decisions that need academic judgement.

> **Status:** hackathon prototype (FARQ, Track 2: university administration and faculty operations).
> Institutional data is a structured snapshot of Al Yamamah University's **public** study plans. People, teaching assignments and student results are **fictional demo data** delivered through simulated SIS/LMS adapters. SAQF has no live connection to any YU system.
> SAQF *supports NCAAA-oriented academic quality workflows*. It is not a compliance certification.

The plain-language guide for the whole team is in [`docs/TEAM_REPORT.md`](docs/TEAM_REPORT.md). It covers what changed, why, and which tools are used where.

---

## What it does

| Instead of… | SAQF… |
|---|---|
| Faculty typing course codes, credit hours, programs and prerequisites | syncs them from the study plans (Registrar adapter) and shows where each value came from |
| Creating a course record each semester | creates the course workspace automatically when the SIS assigns an instructor, and inherits the approved specification |
| Re-submitting the whole specification every term | uses a **change-based** workflow: unchanged specs carry forward with no approval, and reviewers see only the changes |
| QA catching missing fields, wrong weight totals and unmapped CLOs | runs **33 deterministic rules** continuously while the record is built; obvious errors never reach a person |
| Calculating CLO/PLO achievement by hand | imports results (LMS adapter), then calculates CLO and PLO achievement with a configurable method |
| Noticing missed targets at report time | flags the gap on import (first, recurring or worsening), drafts an improvement action with an owner and due date, and checks next term whether performance changed |
| Writing reports | generates them from the structured data; each closed term is frozen into a hash-sealed snapshot |
| QA inspecting every record | gives QA an **Exception Center**: only data conflicts, policy exceptions, quality risks and sampled auto-approvals reach a person |

## Architecture

```
 University systems (adapters)      Registrar/study plans · SIS · LMS        src/Integration
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
                                    policies, automation ledger, error log
```

There is no framework: plain **PHP 8.1+** with PDO, **MySQL 8**, server-rendered pages and about 130 lines of vanilla JavaScript. The goal is that any university web team can host and maintain it. More detail: [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md).

## Requirements

- PHP 8.1+ (8.3 recommended) with `pdo_mysql` and `mbstring` (`curl` only for the HTTP tests)
- MySQL 8.0+ (or MariaDB 10.6+, not yet tested). The database user needs `TRIGGER` privilege. With binary logging on, set `log_bin_trust_function_creators=1` so the append-only audit triggers can be created.
- Apache or Nginx with the **document root set to `public/`**, or Docker

## Quick start: Docker

```bash
cp .env.example .env          # change the passwords
docker compose up --build     # first start installs the schema and the demo scenario
# open http://localhost:8080
```

`SAQF_AUTO_INSTALL=demo` installs the demo on first start only. It never re-installs a database that already has tables.

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
php bin/install.php --demo --fresh          # tests expect the demo database
php tests/automation_test.php               # 57 automation scenarios (event pipeline, rollover, gaps, effectiveness, impact, audit tamper detection…)
php -S 127.0.0.1:8080 -t public &
php tests/http_smoke.php http://127.0.0.1:8080   # 101 checks: every page as every role, cross-role and cross-scope denials, CSRF, lockout, error log, maintenance mode
php bin/install.php --demo --fresh          # reset the demo afterwards (the tests change data)
```

## Production notes (summary)

- `APP_ENV=production`, `APP_DEBUG=false`, HTTPS in front (`SAQF_TRUST_PROXY=true` behind a proxy). Demo mode and `--fresh` are disabled in production.
- `php bin/install.php` (without `--demo`) creates the schema, syncs YU data and prints a one-time administrator password.
- Cron: `*/5 * * * * php bin/tick.php` (LMS imports, deadline checks, daily program re-evaluation). Nightly: `php bin/verify_audit.php` (exit code 2 = tampering).
- Backups: `mysqldump --single-transaction --routines --triggers saqf`. Verify the audit chain after every restore.
- Operations runbook (lockouts, error references, maintenance mode, incidents): [`docs/OPERATIONS.md`](docs/OPERATIONS.md).

## Known prototype limitations

- No live YU integration. Study plans are a transcribed snapshot (see [`data/yu/SOURCES.md`](data/yu/SOURCES.md)). SIS assignments and LMS results are generated demo files behind the same adapter interfaces production would use.
- Authentication is local username/password. Production should use YU SSO (SAML/OIDC) through `src/Security/Auth.php`.
- The achievement method (threshold 70%, default target 70%) and other thresholds are **configurable defaults, not YU-approved methodology**. The Deanship of Quality must confirm them.
- SWE PLOs are the ABET CAC student outcomes YU publishes. CNE and IE use ABET EAC general outcomes as placeholders. Business programs, MBA and MCS use the PLOs on their YU pages. Architecture, EMBA, LLB and LLM have no published PLOs, so SAQF flags them as data exceptions.
- Tested against a MySQL-compatible server (Dolt 2.4) and PHP 8.3. The Docker files follow the official images but were not run in this environment.
- Assisted insights are text-similarity and statistics heuristics. They are not AI and are never applied without a person.

## Production integration path

Implement three interfaces in `src/Integration/Sources.php` against the real systems. Nothing else changes:

1. `InstitutionSource`: Registrar/academic catalogue (colleges, departments, programs, study plans, courses, prerequisites, approved PLOs)
2. `SisSource`: terms, teaching assignments and enrolment (Banner/PeopleSoft/etc.)
3. `LmsSource`: assessment results per student (e.g. Blackboard/Moodle gradebook export)

Then add SSO, point cron at `bin/tick.php`, and have the Deanship of Quality confirm the policies under *Quality policies*.

## Repository layout

```
public/      web root (pages, assets) — the only directory a web server should expose
src/         Core (db, audit, events, policy), Security, Integration, Quality (rules, engine…), Web
database/    schema.sql, audit_guard.sql
data/yu/     YU institutional snapshot (from public study-plan PDFs) + SOURCES.md
data/demo/   simulated SIS/LMS feeds for the demo scenario
bin/         install, tick (scheduler), verify_audit, wait_for_db, build_demo_data
tests/       automation_test.php, http_smoke.php
docs/        TEAM_REPORT.md, ARCHITECTURE.md, OPERATIONS.md, AUDIT_BEFORE.md
docker/      Apache/PHP hardening and the container entrypoint
```

The original AQMS prototype this version was built from is preserved in git history: see the commit *Import AQMS hackathon baseline* and the tag `aqms-baseline`.
