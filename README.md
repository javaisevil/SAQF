# SAQF: Academic Quality Automation for Al Yamamah University

[![CI](https://github.com/javaisevil/SAQF/actions/workflows/ci.yml/badge.svg)](https://github.com/javaisevil/SAQF/actions/workflows/ci.yml)

SAQF connects university academic data, learning outcomes, assessments, achievement, validation, improvement and reporting into one continuous system. It runs routine quality-assurance work in the background, so faculty, Heads of Department, Quality staff and deans spend their time on the decisions that need academic judgement.

> **Status: SAQF 2.6 — a hardened, Docker-deployable demo with an integration-ready handoff. Not yet a production system.** It began in the FARQ hackathon (Track 2: university administration and faculty operations). It runs end to end on **fictional people, simulated SIS/LMS feeds and synthetic pseudonymous results** over a snapshot of Al Yamamah University's *public* study plans, in English and Arabic. Connectors for SIS export files, a generic SIS REST API, **any other JSON API through a mapping file (no code)**, Moodle, Blackboard, LMS export files, OpenID Connect sign-in and SMTP are implemented and **tested against local stand-in servers only** (including a *simulated* university API shaped nothing like SAQF's own contract); nothing has been connected to YU's systems, and there is **no Edugate adapter** (the [integration contract](docs/INTEGRATIONS.md#edugate-and-lms-integration-contract-awaiting-university-it) is awaiting university IT; its API documentation was not available to the project). It has had **no penetration test and no independent code review**, and its hand-written passkey code is unreviewed. What separates this demo from a controlled pilot and from a live service is listed in [`docs/READINESS.md`](docs/READINESS.md).
>
> New in 2.5: student numbers are pseudonymised on **every** grade path (the manual gradebook upload used to store them), the application key must come from the environment in production, production refuses plain-http connectors, the backup and Security center pages report only what was recorded (no fixed claims about encryption, off-site copies or virus scanning), the self-test is labelled as a self-test, and a **Course file closeout** tab shows what is complete, missing or waiting for a person's review, with a Quality-configured checklist, human evidence review and an audited course file package with checksums. Ten automated test suites run on MySQL 8 on every change, and CI also checks the Docker stack, HTTPS, an encrypted backup and a restore.
> SAQF *supports NCAAA-oriented academic quality workflows*. It is not a compliance certification.

- **Presenting SAQF?** The 5-minute judges' script, with the reset procedure and a fallback, is [`docs/JUDGES_DEMO.md`](docs/JUDGES_DEMO.md); in the app, open **Guided tour** (`/tour.php`).
- **Is it ready?** [`docs/READINESS.md`](docs/READINESS.md): demo vs controlled pilot vs live system, and the open prerequisites.
- **Connecting it to YU's systems?** *Administration → Go-live* shows each connection as demo or live, what is missing and the exact next step, and downloads the templates ([`docs/INTEGRATIONS.md`](docs/INTEGRATIONS.md#replacing-the-demo-data-with-the-universitys-own)).
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
| Collecting exam papers for accreditation at the last minute | **asks for the evidence** when results arrive, checks it by type and content (and virus-scans it when a scanner is configured), stores it outside the web server, and lists it in the course report |
| Calculating CLO/PLO achievement by hand | imports grades as the LMS publishes them (Moodle, Blackboard or gradebook exports), then works out how many students met each course and program outcome with a configurable method, shown as "72% · goal 70%" |
| Chasing people by e-mail | e-mails each person a digest of only the items that need them (they can opt out) |
| Noticing missed targets at report time | flags the gap on import (first, recurring or worsening), drafts an improvement action with an owner and due date, and checks next term whether results went up |
| Writing reports | generates them from the structured data, as pages and as **NCAAA-layout Word documents** (English or Arabic); each closed term is frozen into a hash-sealed snapshot (sealing records the content; it is not an approval) |
| Chasing the course file at the end of term | shows a **Course file closeout** per course: complete, missing or waiting for a person's review, with owner and next step, against a checklist Quality sets; the Head of Department or Quality accepts the evidence, never SAQF; one audited ZIP with report, specification, evidence index and checksums |
| QA inspecting every record | gives Quality a **Problems to sort out** list: only data conflicts, exceptions to policy, risks and sampled automatic approvals reach a person |
| Translating course names and outcomes for the Arabic version | shows the **Arabic interface with Arabic data**: names arrive in Arabic from the Registrar catalogue, instructors can type the Arabic of an outcome as they write it, and Quality fills any gap on the *Arabic wording* page (one by one or as a spreadsheet); the Arabic Word documents use the same wording |
| Re-checking a gradebook CSV by eye before importing it | **shows what it understood first** (students, average and range per assessment, replaced marks, a 0–1 scale, a column full of zeros, unknown columns) and imports only when a person presses Import; student numbers are replaced by codes before the preview exists |
| Digging for the numbers behind "what the results mean" | puts plain **facts** next to the report box (goal met or not, change since last term, section gaps, evidence count); the meaning stays the instructor's and SAQF never writes it |
| Uploading evidence one file at a time | takes **several files at once** and suggests what each is and which assessment it belongs to from the file *name* (Arabic names too); the person corrects it before filing; names and digit runs from file names are never copied into titles, logs or packages |
| Remembering the course file deadline | **reminds** instructors 14, 7 and 2 days before grades are due and weekly when overdue (the Head of Department hears about overdue files weekly), only about what is missing, never twice |
| Asking every instructor how far the course file is | gives the Head of Department and Quality a **closeout board** per term with progress bars and a CSV export |
| Wondering whether the university's real systems will fit | lets IT **describe any JSON API in a mapping file** and check it offline on a saved sample (`bin/mapping_check.php`) before anything is connected |

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

There is no framework and no third-party library: plain **PHP 8.1+** with PDO, **MySQL 8**, server-rendered pages and about 390 lines of vanilla JavaScript (no inline scripts, so a strict Content-Security-Policy applies). The one-page summary of what is used, what is optional and what is planned is [`TECH_STACK.md`](TECH_STACK.md). Word files, QR codes, ZIP packaging and authenticator codes are generated in plain PHP. The goal is that any university web team can host and maintain it. More detail: [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md).

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
php bin/app_key.php generate  # → SAQF_APP_KEY; keep it in the password vault (the installer refuses to start without it)
# set APP_ENV=production, SAQF_DEMO=false, SAQF_AUTO_INSTALL=production, SAQF_APP_KEY, strong passwords,
# SAQF_DOMAIN and SAQF_BASE_URL=https://…, SAQF_PORT=127.0.0.1:8080, SAQF_BACKUP_PASSPHRASE,
# SAQF_BACKUP_OFFSITE_PATH, and the SIS / LMS / SSO / e-mail settings (docs/INTEGRATIONS.md)
docker compose --profile https up -d --build                 # add --profile antivirus for ClamAV
docker compose logs app | grep -A2 "Administrator account"   # one-time admin password
```

The `https` profile adds a Caddy proxy that obtains and renews the certificate for `SAQF_DOMAIN`
(or use the university's certificate or load balancer). The stack runs the scheduler, applies
database migrations on every start, answers `/health.php`, and the `backup` service writes a
verified backup of the database and the evidence files every night (encrypted when
`SAQF_BACKUP_PASSPHRASE` is set, copied to `SAQF_BACKUP_OFFSITE_PATH` when set) and reports to SAQF
exactly what it did. These are the *production steps*; they are not yet a production deployment
([`docs/READINESS.md`](docs/READINESS.md)). Sign in as `admin` (you will set up two-step verification
first), change the password, open *Administration → Security center* and *University systems → Test
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
| `f.omar` | Faculty (coordinator) | **SWE 412**: a draft revision with 4 problems found by SAQF — fix them and the *Checks* card says "Everything checks out". **SWE 401**: two sections, section 02 is 16 points behind; last year's improvement shows 53% → 63%, *Results went up afterwards*; the Evidence tab asks for the midterm paper. **CIS 491**: an exception request waiting at Quality |
| `f.sara` | Faculty (section instructor) | Teaches section 02 of SWE 401 and SWE 302 (midterm grades scheduled in the LMS: publishing them triggers an early warning) |
| `f.noura` | Faculty, Accounting & Finance | ACC 311 and MIS 327, whose specifications reached SAQF through the bulk import |
| `hod.ced` | Head of Department | Only what needs a decision: the repeated CLO3 gap, SWE SO2 below its goal; approvals by difference; specification import |
| `qa.director` | Quality | Problems to sort out, the CIS 491 exception decision, spot-checks of automatic approvals, policies, specification import, Arabic wording |
| `dean.coe` | College Dean | College view → department → program → course |
| `vp.academic` | University leadership | Programs that need help, problems that keep coming back, progress term by term |
| `it.admin` | System administrator | Security center, health, IT alerts, users and sessions, security events, activity log (check, CSV export), error log, university systems simulator, Arabic wording of names |

The demo clock is anchored to **Fall 2026, week 6** at install time and then runs forward, so the story looks the same whenever you install it. Set `SAQF_DEMO_CLOCK=real` to use today's date.

**Live automation to show:** as `it.admin`, open *University systems* and use the simulator. *Publish now* (LMS midterm grades) recalculates achievement and raises an early warning. *Publish assignment* (SIS) creates a new instructor's account and course workspace. *Activate term* (Spring 2027) freezes Fall 2026 reports, rolls every course forward and evaluates last term's improvement actions.

## Roles

- **Faculty:** *What needs you* with only what needs them, a plain list of what SAQF did for them, and one page per course (overview with the course facts in sentences, outcomes and assessment, results, evidence, improvement, report, history). They type only academic content, and can add the Arabic wording of an outcome as they write it. In a course with several sections, the coordinator owns the specification and section instructors contribute results and evidence.
- **Head of Department:** department exceptions, changed specifications (the diff only), course assignment fallback, PLO changes with impact analysis.
- **Quality (QA):** problems to sort out, exception decisions with reasons, spot-checks of automatic approvals, configurable quality policies, bulk import of existing specifications (also open to Heads of Department for their own courses), and the *Arabic wording* page for anything still in English.
- **Dean:** college health with drill-down. Persistent program-level problems escalate here.
- **University leadership:** institution-wide view of interventions, recurrence, overdue actions and quality cycles.
- **System administrator:** operations and security (Security center, IT alerts, users and sessions, activity and error logs, university systems, Arabic wording of names). By design (separation of duties), administrators cannot make academic decisions or change course content.

Everyone can switch the interface between **English and Arabic** (العربية, right to left) at the top of every page; the choice is saved on their account. In Arabic, the data is Arabic too: course and program names, program outcomes and study-plan groups come in Arabic with the Registrar catalogue ([`data/yu/arabic.json`](data/yu/arabic.json) in the demo), and course content (learning outcomes, assessments, topics) uses the Arabic wording entered by faculty or Quality. Search finds courses by their Arabic names, and the Arabic Word documents use the same wording.

## Tests

```bash
sh bin/test_all.sh                          # every suite on a fresh demo database, with a plain summary
docker compose exec app sh bin/test_all.sh  # the same inside the Docker image
```

| Suite | Checks | What it shows |
|---|---|---|
| `tests/automation_test.php` | 58 | the quality loop end to end: event pipeline, rollover, gaps, effectiveness, impact analysis, overrides, audit tamper detection |
| `tests/production_test.php` | 96 | SIS/LMS connectors against stand-in Moodle, Blackboard and SIS API, export folders, automatic term start, accounts, SMTP, password reset, SSO token validation |
| `tests/http_smoke.php` | 126 | every page as every role, cross-role and cross-scope denials, section-instructor limits, CSRF, lockout, error log, maintenance mode |
| `tests/sso_test.php` | 29 | university sign-in end to end against a stand-in provider, including replayed, forged and wrong-audience tokens, and the administrator's two-step sign-in |
| `tests/features_test.php` | 91 | sections, specification import, evidence (content checks, stand-in ClamAV, fail-closed), Word exports, TOTP/QR/recovery codes, password policy, sessions, step-up re-authentication, IP rules, trusted proxies, CSP, rate limits, IT alerts, backup monitoring, Arabic interface, demo shortcuts refused in production |
| `tests/wording_test.php` | 31 | plain wording, Arabic names from the catalogue, Arabic course content and search, the *Arabic wording* page, the Arabic Word report |
| `tests/readiness_test.php` | 59 | Go-live status, the data pack and templates (a faulty catalogue is refused, also on first install), access review, the security self-test and evidence report, the faculty calendar file |
| `tests/signin_test.php` | 55 | robot check, two-step codes by e-mail and app, trusted browsers, session warning, help and keyboard shortcuts, and the JSON endpoints refusing a session that still owes a new password or two-step set-up |
| `tests/hardening_test.php` | 128 | student numbers never stored or logged on any gradebook path, production application-key rules, https-only connectors in production, backup status from recorded runs (real `docker/backup.sh` runs when mysqldump is present), the SSO MFA claim, factual Security center and report, read-only integration dry run |
| `tests/closeout_test.php` | 39 | the course file closeout from records, human-only items, evidence review by a reviewer, Quality-only checklist settings, the course file package (checksums, audit, authorisation), Arabic and narrow screens |
| `tests/passkey_test.php` | 74 | the CBOR decoder and WebAuthn verifier against hostile inputs (origin, relying party, challenge, flags, keys, counters), then registration and sign-in over HTTP, always after the password |
| `tests/injection_test.php` | 28 | hostile text through the real inputs shown escaped on every page in English and Arabic, reflected parameters, SQL meta-characters, redirects and headers, CSP violation reports |
| `tests/usability_test.php` | 91 | the gradebook preview, facts next to the instructor's text, home progress, deadline reminders, the closeout board, several evidence files with suggestions, and the code review's findings (section scope, identifiers in file names, passkey revocation) |
| `tests/mapping_test.php` | 107 | mapping files (validation, transforms, every paging style) against a simulated university API, cross-host links refused, size and page limits, pseudonymised mapped grades, the offline checker, the go-live cards |

The counts are from the last full run (`sh bin/test_all.sh` on PHP 8.3 / MySQL 8.0, 4 Oct 2026: 1012 checks, 0 failed, plus the migration upgrade check); the script prints the current numbers. These are the project's own automated tests, not a penetration test or an independent review.

Each suite reinstalls the demo first; run `php bin/install.php --demo --fresh` afterwards to reset the story. The stand-in systems live in `tests/mock/`; nothing leaves the machine. CI (`.github/workflows/ci.yml`) runs every suite on MySQL 8.0, then starts the Docker stack with the HTTPS proxy, runs the HTTP suite against Apache, checks HTTPS, writes an encrypted backup and restores it. Plain-language test cases: [`docs/TEST_CASES.md`](docs/TEST_CASES.md).

## Production notes (summary)

- `APP_ENV=production`, `APP_DEBUG=false`, HTTPS (the `https` compose profile, or the university's proxy listed in `SAQF_TRUSTED_PROXIES`), `SAQF_BASE_URL` set. Demo mode, its one-click sign-in and `--fresh` are disabled in production.
- Security: two-step verification for password sign-in (policy `auth.mfa_required`; for university SSO sign-in MFA is the identity provider's policy, which IT must confirm — SAQF can additionally refuse ID tokens that do not report it, `SAQF_OIDC_REQUIRE_MFA`), administrators can be limited to campus networks (`SAQF_ADMIN_ALLOWED_IPS`), uploads are virus-scanned only if a scanner is configured (`SAQF_CLAMAV_HOST`), production refuses plain-http connectors and a missing application key, the Security center shows each control's recorded status (in place, needs attention, or optional/not configured), an administrator other than the person concerned confirms everyone's access every 90 days (*Access review*), and **Run security self-test** exercises some protections on the installation. The self-test is a self-check, **not a penetration test**; the *Security evidence report* is a self-assessment, not a certification, and lists what it does not cover.
- `php bin/install.php` (without `--demo`) creates the schema, syncs the catalogue and the SIS calendar, and prints a one-time administrator password. `php bin/migrate.php` applies upgrades (automatic in Docker).
- Scheduler: `*/5 * * * * php bin/tick.php` (built into the Docker image). Nightly: `php bin/verify_audit.php` (exit code 2 = tampering).
- Application key: `SAQF_APP_KEY` from the environment in production, backed up separately from the database; rotation is not supported in place (`bin/app_key.php`, [`docs/OPERATIONS.md`](docs/OPERATIONS.md#application-key-saqf_app_key)).
- Backups: the compose `backup` service (database + evidence files, each read back to verify; encrypted only with `SAQF_BACKUP_PASSPHRASE`; a second copy only with `SAQF_BACKUP_OFFSITE_PATH`), or `docker/backup.sh` from cron. A failed evidence archive fails the run. Restore with `docker/restore.sh`; the audit chain is verified afterwards. A restore drill on the university's infrastructure is still to be done and recorded.
- Monitoring: `GET /health.php` (200 / 503), plus **IT alerts** (failing connectors, stalled scheduler, missing backups, broken audit chain, mail failures, blocked malware, password guessing) in SAQF, by e-mail and to a Teams/Slack webhook (`SAQF_ALERT_WEBHOOK`).
- University connections, SSO and e-mail: [`docs/INTEGRATIONS.md`](docs/INTEGRATIONS.md). Operations runbook (lockouts, error references, maintenance mode, upgrades, incidents, go-live checklist): [`docs/OPERATIONS.md`](docs/OPERATIONS.md).

## Before go-live and known limits

- **University access is needed.** The connectors are built and tested against stand-ins only. YU IT must provide the approved Edugate export or API (an Edugate-specific adapter may be needed once its API is documented), an LMS service token, an identity-provider app registration with its MFA policy confirmed, and a mail account. Run `php bin/dry_run.php` on an anonymised staging sample first ([integration contract](docs/INTEGRATIONS.md#edugate-and-lms-integration-contract-awaiting-university-it)).
- **Demo data is fictional.** Demo people, teaching assignments and student results are fictional or synthetic and shown with a DEMO label; the YU catalogue snapshot is public information transcribed by the project, not validated by the Registrar (`data/yu/SOURCES.md`).
- **Human judgement stays human.** SAQF never writes the reading of results, recommendations or improvement plans, never accepts evidence, and never approves anything by itself; the course file checklist is SAQF's default until Quality confirms it.
- **Policies need approval.** The achievement method (threshold 70%, default target 70%) and other thresholds are **configurable defaults, not YU-approved methodology**. The Deanship of Quality must confirm them under *Quality policies*.
- **Program outcomes.** SWE PLOs are the ABET CAC student outcomes YU publishes. CNE and IE use ABET EAC general outcomes as placeholders. Business programs, MBA and MCS use the PLOs on their YU pages. Architecture, EMBA, LLB and LLM have no published PLOs, so SAQF flags them until Heads of Department enter them (they can, in SAQF).
- **Sign-in** uses OpenID Connect (Microsoft Entra ID / 365, Google, Keycloak, ADFS 2019+, Okta). A SAML-only identity provider needs an OIDC bridge.
- **Interface language:** English and Arabic (right to left). The Arabic interface translates every screen's labels, messages and automatic findings (about 2,000 phrases and 480 patterns, `src/Web/lang/ar.php`). Data appears in Arabic wherever its Arabic wording is known: from the Registrar catalogue, from faculty, or from Quality on the *Arabic wording* page; anything without Arabic wording is shown as entered and listed on that page. `php bin/i18n_coverage.php <url>` lists any English left on a screen.
- **Tested** on MySQL 8.0 and PHP 8.3, directly and in the Docker stack (Apache). MariaDB is not tested.
- Assisted insights are text-similarity and statistics heuristics. They are not AI and are never applied without a person.

## Connecting the university's systems

| System | Choose with | Options |
|---|---|---|
| SIS (calendar, teaching assignments) | `SAQF_SIS_SOURCE` | `file` (nightly CSV export), `rest` (integration API), `none` |
| LMS (grades) | `SAQF_LMS_SOURCE` | `moodle`, `blackboard`, `file` (gradebook CSV exports), `none` |
| Registrar catalogue | `storage/inbox/catalog/` or `SAQF_INSTITUTION_DIR` | the bundled YU snapshot, or a Registrar export in the same format; checked completely before use (`php bin/pack.php validate`) |
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
bin/         install, migrate, tick (scheduler), verify_audit, pack (check/export the data pack), dry_run (read-only preview of SIS/LMS exports), app_key (application key status/migration), wait_for_db, build_demo_data, test_all.sh, i18n_coverage
tests/       automation_test, production_test, http_smoke, sso_test, features_test, wording_test, readiness_test, signin_test, hardening_test, closeout_test, mock/ (stand-in systems)
docs/        READINESS.md, DEPLOY.md, SECURITY_ASSURANCE.md, PRIVACY.md, THREAT_MODEL.md, COMPLIANCE_MAP.md, PRESENTATION.md, JUDGES_DEMO.md,
             TEST_CASES.md, TEAM_REPORT.md, ARCHITECTURE.md, INTEGRATIONS.md, OPERATIONS.md, AUDIT_BEFORE.md, mappings/ (SIMULATED example mappings),
             openapi/ (SIS contract), demo/
docker/      Apache/PHP hardening, entrypoint, backup and restore scripts, Caddyfile (HTTPS)
```

The original AQMS prototype this version was built from is preserved in git history: see the commit *Import AQMS hackathon baseline*.
