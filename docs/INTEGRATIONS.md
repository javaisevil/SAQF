# Connecting SAQF to university systems

For university IT. SAQF reads from four systems and writes to none of them. Each connection is
chosen with settings (environment variables, or `config.local.php` on shared hosting), tested from
*Administration → University systems → Test connections*, and then run automatically by the scheduler.

> **Status (SAQF 2.6).** No connector has been run against Al Yamamah University's systems. The
> connectors below are implemented and tested against **local stand-in servers** (`tests/mock/`: a
> Moodle, a Blackboard Learn, a generic SIS REST API, a deliberately differently shaped "university API",
> an OpenID Connect provider, SMTP, ClamAV and a webhook) and against export files in the documented layouts. There is **no Edugate adapter**:
> Edugate's API, export formats, scopes and version are not known to the project, and nothing has been
> guessed. Connecting Edugate and the university's LMS needs the agreement described in
> [the integration contract](#edugate-and-lms-integration-contract-awaiting-university-it) and IT sign-off.
> In the demo every feed is simulated and every record is fictional or synthetic.

| Supported today | How it was verified |
|---|---|
| Registrar catalogue files (JSON layout of `data/yu`) | bundled YU public snapshot (captured 1 Oct 2026, see `data/yu/SOURCES.md`); `bin/pack.php validate` |
| SIS export folder (`terms.csv`, `assignments.csv`) | `tests/production_test.php`, `tests/readiness_test.php`, `bin/dry_run.php sis` |
| Generic SIS REST API (`GET /terms`, `GET /terms/{code}/assignments`, bearer token) | stand-in server `tests/mock/sis.php` — a contract SAQF defines, not an Edugate API |
| **Mapped JSON API** for the SIS and for LMS grades (`mapped`): any HTTPS+JSON API described by a mapping file | stand-in `tests/mock/uni_api.php` (SIMULATED, shaped nothing like SAQF's contract), `tests/mapping_test.php`, `bin/mapping_check.php` |
| Moodle web services (REST) | stand-in server `tests/mock/moodle.php` |
| Blackboard Learn REST (OAuth client credentials) | stand-in server `tests/mock/blackboard.php` |
| LMS gradebook export folder and manual gradebook upload (CSV) | `tests/production_test.php`, `tests/hardening_test.php`, `bin/dry_run.php lms` |
| OpenID Connect sign-in | stand-in provider `tests/mock/idp.php` |
| SMTP e-mail, ClamAV, Teams/Slack webhook | stand-ins in `tests/mock/` |

| System | What SAQF reads | Connectors | Setting |
|---|---|---|---|
| Registrar / academic catalogue | colleges, departments, programs, study plans, courses, prerequisites, PLOs | catalogue files (JSON) | `SAQF_INSTITUTION_DIR` |
| SIS (Banner, PeopleSoft, in-house) | academic calendar, teaching assignments, enrolment | export folder (CSV) · REST API · **mapped API** | `SAQF_SIS_SOURCE=file \| rest \| mapped` |
| LMS | published grades per assessment | Moodle · Blackboard Learn · **mapped API** · export folder (CSV) | `SAQF_LMS_SOURCE=moodle \| blackboard \| mapped \| file` |
| Identity provider | who is signing in, optionally their role | OpenID Connect (Entra ID / Microsoft 365, Google, Keycloak, ADFS, Okta) | `SAQF_OIDC_*` |
| Mail server | — (sends two-step sign-in codes, notifications, invitations, password resets) | SMTP (Microsoft 365, Google, on-premise relay) | `SAQF_MAIL_*` |

`SAQF_SIS_SOURCE=none` or `SAQF_LMS_SOURCE=none` switches a connection off: terms are then added
under *University systems → Academic calendar* and courses assigned by Heads of Department, and instructors
upload gradebook CSVs in their course workspace.

## Replacing the demo data with the university's own

Everything shown in the demo is data in a fixed layout, not code. To go live, replace each demo input with the
university's own in the **same layout**; no program change is needed. *Administration → Go-live* is the
control panel for this: it shows each connection as **Demo data**, **Configured** (settings present) or
**Incomplete** (settings missing), lists the settings still missing (never their values) and the exact
next step, and downloads the templates below. "Configured" is not "working": only a successful
*Test connections* shows that SAQF can reach the system.

| Demo input | Template (Go-live → Templates for IT) | Replace with | Where it goes |
|---|---|---|---|
| Programs, study plans, courses, prerequisites, outcomes (`data/yu`) | Registrar catalogue (ZIP) | the Registrar's export in the same layout | `storage/inbox/catalog/` (used automatically) or `SAQF_INSTITUTION_DIR` |
| Timetable (`data/demo/sis.json`) | `terms.csv`, `assignments.csv` | the SIS scheduled report, or switch to the REST API | `storage/inbox/sis/` and `SAQF_SIS_SOURCE=file`, or `rest` |
| Student grades (`data/demo/lms`) | gradebook example | nothing to copy: choose Moodle or Blackboard | `SAQF_LMS_SOURCE=moodle` or `blackboard`, or CSV exports in `storage/inbox/lms/<term>/<course>/` |
| People and sign-in | | the university identity provider | `SAQF_OIDC_*` |
| E-mail | | the university mail server | `SAQF_MAIL_*` |

A settings file with every go-live setting (no secrets) downloads from the same page.

**A faulty catalogue cannot do harm.** Before any catalogue is used, SAQF checks it completely (structure,
unknown departments, credit hours, course types, owning department of every course prefix, prerequisites,
outcomes). Anything wrong is listed in plain words and the whole export is refused: the previous data stays
in place and the daily update fails visibly (Error log and an IT alert). Check an export yourself, without
changing anything, with *Go-live → Check the catalogue* or

```bash
php bin/pack.php validate /path/to/export        # exit code 1 when it is not usable
php bin/pack.php export saqf-catalogue.zip       # today's catalogue as the template
php bin/pack.php templates /tmp/saqf-templates   # terms.csv, assignments.csv, gradebook example
```

Order of precedence for the catalogue: `SAQF_INSTITUTION_DIR`, else `storage/inbox/catalog/institution.json`
when present, else the bundled YU snapshot.

---

## What runs automatically

`bin/tick.php` runs every 5 minutes (the Docker image does this itself; elsewhere use cron):

| Step | When | Result |
|---|---|---|
| SIS calendar | hourly | new and changed terms |
| Semester rollover | when a term's start date arrives | previous term closed and its reports frozen; every course workspace created from the SIS assignments, inheriting its approved specification |
| Teaching assignments | hourly | new workspaces, instructor changes, enrolment; unknown instructors get faculty accounts (policy *Create accounts for new instructors from the SIS*) |
| LMS results | every run (Moodle / Blackboard: every `SAQF_LMS_POLL_MINUTES`, default 30) | newly published grades imported; achievement recalculated; gaps and early warnings raised |
| Catalogue | daily | programs, plans, courses, PLOs refreshed; conflicts between sources raised as data exceptions |
| E-mail | every run | one digest per person about notifications that need them; queued mail delivered with retries |

Each step is independent: if the LMS is down, the SIS sync and e-mail still run. Failures appear
under *Administration → Error log* and in the `bin/tick.php` output (exit code 2). Every switch is a
policy QA can turn off under *Quality policies*.

---

## SIS

### Option A: export folder (`SAQF_SIS_SOURCE=file`)

Works with any SIS that can run a scheduled report. Drop two UTF-8 CSV files (with header row) into
`SAQF_SIS_DIR` (default `storage/inbox/sis`; in Docker, `./storage/inbox/sis` on the host):

`terms.csv`

| column | example | notes |
|---|---|---|
| code | `2026-1` | stable term code |
| name | `Fall 2026` | |
| academic_year | `2026-2027` | |
| sequence | `7` | increases with every term (orders terms) |
| starts_on | `2026-08-23` | the term is activated automatically on this date |
| ends_on | `2026-12-20` | |
| grades_due_on | `2026-12-30` | drives "results overdue" checks |

`assignments.csv`

| column | example | notes |
|---|---|---|
| term | `2026-1` | term code |
| course | `SWE 401` | `SWE401` and `swe 401` are accepted |
| instructor_id | `YU-F1034` | HR/SIS staff number; matched to the SAQF account's *SIS / HR identifier* |
| instructor_name | `Dr. Omar Al-Harbi` | optional; lets SAQF create the account for a new instructor |
| instructor_email | `o.alharbi@yu.edu.sa` | optional; links an existing account or receives the invitation |
| department | `CED` | optional; defaults to the department that owns the course prefix |
| sections | `2` | used when the file has one row per course |
| enrolled | `41` | for a section row: students in that section |
| section | `02` | optional; one row **per section** of the course (see below) |
| coordinator | `1` | optional; marks the section instructor who coordinates the course |

**Courses with several sections.** Give one row per section with the `section` column filled in.
SAQF keeps one course record per term: the coordinator (the row with `coordinator=1`, otherwise the
current coordinator if still teaching, otherwise the first section's instructor) owns the shared
specification, and every section instructor gets access to the course to add results and evidence.
New section instructors are notified; achievement is compared between sections and a gap larger than
the policy *Gap between sections* (15 points) is raised for the coordinator.

Replace the files whenever the SIS changes; SAQF reads the current content every hour and only acts
on differences. Rows for courses that are not in the catalogue are counted in the integration log.

### Option B: REST API (`SAQF_SIS_SOURCE=rest`)

For universities with an integration layer (Ellucian Ethos, MuleSoft, an API gateway):

```
GET {SAQF_SIS_URL}/terms                     → [ {code, name, academic_year, sequence, starts_on, ends_on, grades_due_on}, … ]
GET {SAQF_SIS_URL}/terms/{code}/assignments  → [ {course, instructor_id, instructor_name, instructor_email, department, sections, enrolled, section?, coordinator?}, … ]
Authorization: Bearer {SAQF_SIS_TOKEN}
```

Fields are the same as the CSV columns above. Either response may wrap the list as
`{"terms": […]}` / `{"assignments": […]}`.

### Option C: any other JSON API, by configuration (`SAQF_SIS_SOURCE=mapped`, `SAQF_LMS_SOURCE=mapped`)

When the university's own API (Edugate's or the LMS's, once IT documents it) does not look like SAQF's
contract, nobody writes code: a **mapping file** says where the lists are in the answers and which JSON
path feeds which SAQF field. A mapping is *data*: it is validated, never executed, and the same strict checks
as every other source (dates, course codes, section codes, pseudonymisation) run after it.

| Setting | Meaning |
|---|---|
| `SAQF_SIS_MAPPING` / `SAQF_LMS_MAPPING` | path of the mapping file (relative to the SAQF folder or absolute) |
| `SAQF_SIS_URL` / `SAQF_LMS_URL` | base address of the API (`https://` in production; plain `http://` is refused there) |
| `SAQF_SIS_TOKEN` / `SAQF_LMS_TOKEN` | bearer token or API key (`auth.type` `bearer` or `header`) |
| `SAQF_SIS_CLIENT_ID` + `SAQF_SIS_CLIENT_SECRET` (same for `LMS`) | OAuth2 client-credentials (`auth.type` `oauth2`, with `auth.token_url`) |

Every secret may be given as a file instead (`…_FILE`, the Docker secrets pattern; see `docs/OPERATIONS.md`).

**What a mapping can say** (see `docs/mappings/university-sis.simulated.json` and `university-lms.simulated.json`):

```jsonc
{
  "version": 1,
  "name": "University registry API",
  "simulated": false,                       // true on any file that describes a stand-in, so the screens say SIMULATED
  "auth": { "type": "bearer" },             // bearer | header (+ "name") | oauth2 (+ "token_url", "scope") | none
  "check": { "path": "/health" },           // optional harmless endpoint for "Test connections"
  "terms": {
    "path": "/registry/v2/semesters",       // placeholders: none here; {term} for assignments;
    "list": "payload.rows",                 //   {term} {code} {code_nospace} {course_key} {section} for grades
    "pagination": { "type": "page", "param": "page", "size_param": "per_page", "size": 100 },
    "fields": {
      "code": "semester_code",
      "starts_on": { "path": "begin", "transform": ["date:d/m/Y"] },
      "sequence": { "path": "ordinal", "transform": ["int"] }
      // … every SAQF field of the section (see bin/mapping_check.php for the list it expects)
    }
  }
}
```

* **Field forms:** `"path"`; or an object with `path`, `join` (several paths, e.g. first + last name), `const`,
  `default`, `map` (a table such as `{"MT": "Midterm exam"}` for coded columns) and `transform`.
* **Whitelisted conversions** (nothing else exists): `trim`, `upper`, `lower`, `int`, `float`, `bool` (Y/N/true → yes/no),
  `digits`, `course` (puts `swe401` into catalogue format `SWE 401`) and `date:<format>` (strict: `31/02/2026`
  is refused, not rolled over). Grades become percentages from `score` ÷ `max`, or are taken from `percent`.
* **Paging:** `none`, `page`, `offset`, `cursor` and `next_link`. A link to another host is never followed
  (credentials would leave the system SAQF was configured for); a server that repeats a page is stopped;
  reaching `max_pages` with more to read is an error (data is never silently dropped); at most 200,000 rows.
* **LMS grades** arrive as rows of *student × assessment × mark*. Student identifiers are replaced by keyed
  pseudonyms while the rows are read, with the same key as the manual upload and the export folder, so every route
  agrees and none can store a student number. `skip_assessments` leaves out columns such as the LMS's own total.
  Assessment names must equal the approved specification's names (use `map` for coded columns).
* **Read-only:** only `GET` requests go to the university API (the single `POST` is the OAuth2 token request).
  Answers over 32 MB are refused. Tokens and secrets are never shown in messages.

**Check a mapping before anything is connected** (no network, no database):

```
php bin/mapping_check.php config/sis.mapping.json --system=sis \
    --sample=terms=samples/terms.json --sample=assignments=samples/assignments.json --term=2026-1
php bin/mapping_check.php config/lms.mapping.json --system=lms --sample=grades=samples/marks.json
```

Save an (anonymised) response from the university's API, run the command, and read what SAQF would take from it.
Student numbers are never printed (they become pseudonyms first) and instructors' e-mail addresses are masked.
Then set the settings, press *Test connections* in Administration → Go-live, and follow the sign-off steps of the
[integration contract](#edugate-and-lms-integration-contract-awaiting-university-it).

**What is and is not proven.** The two example mappings and `tests/mapping_test.php` prove, against a *simulated*
API with nested objects, `d/m/Y` dates, split names, coded assessment columns and three paging styles, that a
mapping with no code carries terms, assignments (sections, coordinators, new instructors) and graded marks all the
way through the same pipeline as every other connector. They do **not** prove anything about Edugate or the
university's LMS: their API documentation was not available to the project, and nothing was guessed.

---

## LMS

SAQF imports a gradebook column only when its name matches an assessment in the course's approved
specification (case-insensitive) — for example *Midterm exam*. Other columns (attendance, bonus,
totals) are ignored and noted in the audit log. Scores are converted to percentages. Student
identities from **every** grade source (Moodle, Blackboard, the export folder and the instructor's
manual upload) are replaced by keyed pseudonyms while the data is read, before anything is stored or
logged, so SAQF never holds student numbers or names; a batch keyed by digit-only identifiers is
refused. The key is `SAQF_APP_KEY` (required in production; a development key is generated in demo
mode — see [OPERATIONS.md](OPERATIONS.md#application-key-saqf_app_key)). There is no setting to keep
raw identifiers.

In production every connector address (SIS API, Moodle, Blackboard, identity provider, webhook) must
be `https://`; SAQF refuses plain `http://` before connecting. Local and demo mode accept `http://`
only so the stand-in servers can be used.

Courses are matched with `SAQF_LMS_COURSE_KEY`, default `{term}-{code_nospace}` (e.g. `2026-1-SWE401`).
Tokens: `{term}`, `{code}` (`SWE 401`), `{code_nospace}` (`SWE401`), `{section}` (`02`). Use the pattern
your LMS course IDs already follow. When the university runs **one LMS course per section**, include
`{section}` (e.g. `{term}-{code_nospace}-{section}` → `2026-1-SWE401-02`): SAQF reads every section's
course and tags each student's results with their section. With one LMS course for all sections,
results are still tagged per section when the gradebook export has a `section` column.

### Moodle (`SAQF_LMS_SOURCE=moodle`)

1. *Site administration → Server → Web services*: enable web services and the REST protocol.
2. Create an external service (e.g. "SAQF") with the functions `core_webservice_get_site_info`,
   `core_course_get_courses_by_field` and `gradereport_user_get_grade_items`.
3. Create a dedicated user with permission to view grades in the relevant courses (or site-wide),
   authorise it for the service and create a token.
4. Settings: `SAQF_MOODLE_URL=https://lms.yu.edu.sa`, `SAQF_MOODLE_TOKEN=…`,
   `SAQF_MOODLE_COURSE_FIELD=idnumber` (or `shortname`), and `SAQF_LMS_COURSE_KEY` matching your
   course ID numbers.

Hidden grade items, items without a grade and the course total are skipped.

### Blackboard Learn (`SAQF_LMS_SOURCE=blackboard`)

1. Register a REST application in the Anthology Developer Portal; note the key and secret.
2. In Learn, *System Admin → REST API Integrations*: add the application ID with a system user that
   can read courses and gradebooks.
3. Settings: `SAQF_BLACKBOARD_URL=https://yu.blackboard.com`, `SAQF_BLACKBOARD_KEY=…`,
   `SAQF_BLACKBOARD_SECRET=…`, and `SAQF_LMS_COURSE_KEY` matching the course `externalId`.

Only graded attempts are read; external-grade (total) columns are skipped.

### Export folder (`SAQF_LMS_SOURCE=file`)

For any other LMS: export each course's gradebook as CSV into
`SAQF_LMS_DIR/<term>/<course>/` (default `storage/inbox/lms`), e.g. `storage/inbox/lms/2026-1/SWE401/midterm.csv`:

```
student,Midterm exam,Quiz
202600001,77,90
202600002,64.5,82
```

An optional second column `section` tags each student with their course section
(`student,section,Midterm exam,…`). Scores are percentages (0–100). A changed file is re-imported; a file is read only once it has not
changed for 30 seconds. Invalid files are skipped and reported in the error log.

---

## Edugate and LMS integration contract (awaiting university IT)

**Status: not connected. Awaiting the university's approved endpoint or export, scopes, vendor/version
details and IT sign-off.** SAQF does not scrape Edugate or the LMS, does not use personal credentials,
and has no Edugate-specific code. What follows is the narrow, read-only contract SAQF needs; the
university chooses how to meet it. Fill in the blanks with IT, then run the dry run below before
anything is connected.

### 1. Scope (read-only, minimum data)

| Feed | Fields SAQF needs | Fields SAQF must NOT receive |
|---|---|---|
| Academic calendar | term code, name, academic year, sequence, start, end, grades-due dates | — |
| Teaching assignments | term, course code, section, staff number, staff name and university e-mail, owning department, enrolment count per section, coordinator flag | student lists, student names, national IDs, grades of individual students outside the gradebook feed |
| Gradebook (per course or section) | a student key (student number or LMS user id: pseudonymised on arrival), section, one percentage per assessment named as in the approved specification | names, e-mails, national IDs, contact details, demographic data, free-text feedback |

### 2. Delivery: one of two options

| | **A. Approved API** | **B. Secure scheduled export** |
|---|---|---|
| Transport | HTTPS only (TLS certificate verified; refused otherwise in production) | SFTP or a university file share mounted read-only into `storage/inbox/sis` and `storage/inbox/lms` |
| Authentication | a service account or OAuth2 client-credentials client created for SAQF, never a person's account | the export job's own account writes; SAQF only reads |
| Least privilege | read-only scopes limited to the fields above, the relevant terms and the colleges in the pilot | the export contains only the fields above |
| What SAQF has today | `SAQF_SIS_SOURCE=rest` expects SAQF's own simple contract (`GET /terms`, `GET /terms/{code}/assignments`, bearer token; machine-readable in [`docs/openapi/saqf-sis.openapi.yaml`](openapi/saqf-sis.openapi.yaml)). If the API differs, `SAQF_SIS_SOURCE=mapped` / `SAQF_LMS_SOURCE=mapped` connect it with a **mapping file** ([Option C](#option-c-any-other-json-api-by-configuration-saqf_sis_sourcemapped-saqf_lms_sourcemapped)): IT's API documentation and a service account are all that is needed, no code. Only an API that is not JSON over HTTPS would need a small adapter class implementing `SisSource` / `LmsSource` (`src/Integration/Sources.php`) | `SAQF_SIS_SOURCE=file`, `SAQF_LMS_SOURCE=file` read the layouts in this guide; Moodle and Blackboard connectors exist for those LMS products |
| To be provided by IT | base URL, API version and vendor documentation, scopes, credentials (into the secret store, never e-mail or git), rate limits | export schedule, file naming, folder, encoding (UTF-8), who monitors the job |

### 3. Field mapping (to be completed with the Registrar and the LMS owner)

| SAQF field | Edugate / LMS field | Transformation | Confirmed by |
|---|---|---|---|
| term `code` | _(to be provided)_ | e.g. `2026-1` | |
| course `code` | _(to be provided)_ | `SWE401` / `swe 401` accepted, compared with the catalogue | |
| `section` | _(to be provided)_ | two digits | |
| `instructor_id` | _(staff number field)_ | matched to the SAQF account's *SIS / HR identifier* | |
| `enrolled` | _(to be provided)_ | integer per section | |
| gradebook `student` | _(student number or LMS user id)_ | keyed pseudonym on arrival (never stored) | |
| assessment columns | _(gradebook item names)_ | percentage 0–100; names must match the approved specification | |

### 4. Steps before going live with a feed

1. **Anonymised staging sample.** IT provides one term's export (or API responses) for the pilot
   department with student identifiers replaced by random values. No real personal data in staging.
2. **Dry run.** On a staging copy of SAQF: `php bin/dry_run.php sis <folder>` and
   `php bin/dry_run.php lms <folder> [term]` read the files exactly as the connectors do and print row
   counts, the rows the import would skip (unknown course) or warn about (no instructor, duplicates),
   the gradebook columns that match the specification and those ignored, and every file that would be
   refused with its line. Nothing is written and no student identifier is printed. (For the catalogue:
   `php bin/pack.php validate <folder>`.) For an API, save its responses into the export layout and dry-run those.
3. **Idempotent import.** The SIS sync acts only on differences (re-reading the same export changes
   nothing); each gradebook file or Moodle/Blackboard batch is imported once, keyed by a checksum of its
   content, and a corrected file replaces the scores it covers. Running an import twice is safe. (A
   manual upload repeated by hand is listed as another batch, but each student's score is updated,
   never duplicated.)
4. **Reconciliation report.** After each import, compare SAQF's counts with the source:
   *University systems → Recent updates* (`sync_runs`: assignments, workspaces created, unknown courses,
   unknown instructors) and, per course, *Results & achievement → Grades received* (batch, rows, checksum)
   or the course file package's `grade-batches.csv`. The Registrar and the LMS owner confirm the numbers
   for the pilot term before the feed is relied on.
5. **Sign-off.** University IT (security and integration), the Registrar and the Deanship of Quality
   sign the completed contract. Until then the Go-live page keeps showing *Demo data*.

---

## Existing course specifications (one-time import)

Universities already have approved specifications. *Import specifications* (Quality, and Heads of
Department for their own courses) reads them from one CSV file — download the template from the page.
One row per item:

| column | used for |
|---|---|
| course | course code (`SWE 401`) |
| type | `objectives`, `strategies`, `clo`, `assessment`, `topic` or `resource` |
| code | CLO code (`CLO1`) |
| text | the statement, assessment name, topic or reference |
| category | CLO learning domain (Knowledge and Understanding / Skills / Values, Autonomy, and Responsibility), assessment kind (`quiz`, `midterm`, `final`, `project`, …) or resource kind (`essential`, `supportive`, `electronic`, `facility`) |
| weight, week | assessment weight (%) and week |
| hours | contact hours of a topic |
| target | CLO target (%), optional |
| links | for a CLO: the PLOs it develops (`SO1;SO2`, or `SWE:SO1` when several programs share codes); for an assessment: the CLOs it measures (`CLO1;CLO3`) |

Each course is checked completely before anything is written; a course with any mistake is reported
line by line and skipped, the others are imported. *Approved baseline* makes the import the course's
current approved specification (decision route `import`, audited with the reason given); *Draft* lets
the instructor review and submit it through the normal workflow. The file is UTF-8, so Arabic text is
kept (the template has a byte-order mark so Excel opens it correctly).

---

## IT alerts to Teams or Slack

Set `SAQF_ALERT_WEBHOOK` to an incoming-webhook address (Microsoft Teams *Incoming Webhook* or
*Workflows*, Slack *Incoming Webhooks*). Every new alert, and a reminder every 6 hours while it stays
open, is posted as JSON `{"text": "SAQF CRITICAL: Database backup missing or failed …"}`. Alerts are
also shown under *Admin → IT alerts* and e-mailed to administrators. The list of alerts is in
[OPERATIONS.md](OPERATIONS.md#it-alerts).

## Virus scanning

`SAQF_CLAMAV_HOST=host:3310` makes SAQF send every uploaded evidence file to a ClamAV daemon (clamd
`INSTREAM`) before storing it. The compose profile `antivirus` starts one (`clamav:3310`). If clamd
does not answer, uploads pause and IT is alerted; nothing is stored unscanned.

---

## Registrar catalogue

The catalogue is JSON in the format of `data/yu/` (`institution.json` plus one file per program
under `programs/`). The repository ships Al Yamamah University's structured study plans, built from
the published plans with `tools/build_yu_snapshot.py`. To use the Registrar's own export, write the
same structure to a folder and set `SAQF_INSTITUTION_DIR`. The scheduler re-syncs it daily; changes to
credits, titles or PLO statements are audited, and PLO changes trigger impact analysis.

### Arabic names

The Arabic interface and the Arabic Word documents show course and program names, department and
college names, program outcomes and study-plan groups in Arabic when the catalogue provides them.
Either add the Arabic next to each English field in the export (`name_ar`, `short_name_ar`,
`title_ar` for a course or elective slot, `text_ar` for an outcome or description, `group_ar`,
`condition_ar`), or put an `arabic.json` file beside `institution.json` keyed by codes, as in
[`data/yu/arabic.json`](../data/yu/arabic.json) (`departments`, `programs`, `courses`, `plos`,
`descriptions`, and, keyed by the English text, `groups`, `slots`, `conditions`, `notes`). Every
sync stores them as *catalogue* wording; a correction a person made on the *Arabic wording* page is
never overwritten. Anything still without Arabic is listed on that page, and Quality can download it
as a spreadsheet, fill in the Arabic column and upload it back. Course content (learning outcomes,
assessments, topics) is worded in Arabic by the instructor in the outcome editor or by Quality on the
same page.

---

## University sign-in (OpenID Connect)

SAQF uses the authorization-code flow with PKCE. ID tokens are verified against the provider's
published keys (RS256/384/512, ES256/384); issuer, audience, expiry and nonce are checked; each sign-in
attempt is single-use and bound to the browser that started it.

### Microsoft Entra ID (Azure AD / Microsoft 365)

1. *Entra admin center → App registrations → New registration*: name "SAQF", single tenant,
   redirect URI (Web) `https://saqf.yu.edu.sa/sso.php`.
2. *Certificates & secrets*: create a client secret.
3. *Token configuration*: add the optional claim `email` (ID token).
4. Optional roles: *App roles* → create `SAQF.Faculty`, `SAQF.HoD`, `SAQF.Quality`, `SAQF.Dean`,
   `SAQF.Leadership`, `SAQF.Admin`; assign people or groups under *Enterprise applications → SAQF →
   Users and groups*.
5. Settings:

```
SAQF_BASE_URL=https://saqf.yu.edu.sa
SAQF_OIDC_ISSUER=https://login.microsoftonline.com/<tenant-id>/v2.0
SAQF_OIDC_CLIENT_ID=<application (client) id>
SAQF_OIDC_CLIENT_SECRET=<secret>
SAQF_PASSWORD_LOGIN=admins
# optional, when app roles are used:
SAQF_OIDC_ROLE_CLAIM=roles
SAQF_OIDC_ROLE_MAP=SAQF.Faculty=faculty;SAQF.HoD=hod;SAQF.Quality=qa;SAQF.Dean=dean;SAQF.Leadership=leadership;SAQF.Admin=admin
```

### Google Workspace

OAuth client of type *Web application* with redirect URI `{SAQF_BASE_URL}/sso.php`;
`SAQF_OIDC_ISSUER=https://accounts.google.com`, `SAQF_OIDC_USERNAME_CLAIM=email`.

### Keycloak / ADFS 2019+ / Okta

Issuer = the realm or authority URL that serves `/.well-known/openid-configuration`
(e.g. `https://sso.yu.edu.sa/realms/yu`, `https://adfs.yu.edu.sa/adfs`). Confidential client, redirect
URI `{SAQF_BASE_URL}/sso.php`. Use `SAQF_OIDC_CLIENT_AUTH=basic` if the provider requires
client_secret_basic.

### Who gets in

1. A person whose identity was linked before signs straight in.
2. Otherwise SAQF looks for an existing account whose username equals the sign-in name (with or
   without `@domain`) or whose e-mail matches, and links it. Add people beforehand under
   *Administration → Users & access* (form or CSV import), or let the SIS feed create instructors.
3. Otherwise, with `SAQF_OIDC_AUTO_PROVISION=true`, an account is created when a role can be derived
   from `SAQF_OIDC_ROLE_MAP` (or `SAQF_OIDC_DEFAULT_ROLE`). Without a role the person is told to
   contact the administrator.

With a role map, roles follow the identity provider on every sign-in (audited as `user.role_synced`);
the most senior mapped role wins. Heads of Department and deans also need their department or
college set in SAQF.

`SAQF_PASSWORD_LOGIN` decides who may still use a password: `all`, `admins` (recommended — a
break-glass path if the identity provider is unavailable) or `off`. Sign-out also ends the identity
provider session when the provider supports it.

---

## E-mail

```
SAQF_MAIL_HOST=smtp.office365.com
SAQF_MAIL_PORT=587
SAQF_MAIL_ENCRYPTION=tls          # tls (STARTTLS) | ssl (implicit TLS, port 465) | none (internal relay only)
SAQF_MAIL_USERNAME=saqf@yu.edu.sa
SAQF_MAIL_PASSWORD=…
SAQF_MAIL_FROM=saqf@yu.edu.sa
SAQF_MAIL_FROM_NAME=SAQF Academic Quality
SAQF_BASE_URL=https://saqf.yu.edu.sa
```

SAQF e-mails only what needs a person: one digest per scheduler run listing that person's new
notifications, plus account invitations and password-reset links. People can turn digests off under
*Account & security*. Mail is queued in `mail_outbox` and retried with backoff for up to 8 attempts;
the last error is kept for IT. Password reset by e-mail is offered only when mail and `SAQF_BASE_URL`
are configured and password sign-in is allowed for that account.

`SAQF_MAIL_TRANSPORT=log` writes messages to the PHP error log instead of sending them (staging).

---

## Accounts

- *Users & access → Add a user*: one person. With SSO, no password is created; otherwise an e-mail
  invitation is sent (mail configured) or a one-time password is shown.
- *Users & access → Import users (CSV)*: columns `username, full_name, email, role, department,
  college, external_id, title` (download the template on that page). Existing usernames are updated;
  invalid rows are listed with line numbers and skipped.
- SIS feed: instructors that SAQF does not know are linked by e-mail or created as faculty in the
  department that owns the course.

Every change is in the audit log with its source (`admin`, `import`, `sis`, `sso`).

## Troubleshooting

| Symptom | Where to look |
|---|---|
| Test connections reports a problem | the message names the host and HTTP status; check URL, token and firewall rules from the SAQF server |
| No workspaces at term start | *University systems → Recent updates*: `sis.assignments` counts unknown courses / instructors; check the term's `starts_on` and the policy *Start terms automatically* |
| Grades not imported | the gradebook column names must match the specification's assessment names; see audit entries `results.columns_ignored`; the course needs an approved specification |
| SSO: "not set up in SAQF yet" | add the person (or their role claim) — see *Who gets in* |
| SSO: "issued by an unexpected identity provider" | `SAQF_OIDC_ISSUER` must equal the `issuer` in the provider's discovery document exactly |
| E-mails not arriving | `mail_outbox.last_error`; *Error log* entries after 8 failed attempts |
