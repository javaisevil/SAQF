# SAQF 2.2: what we built, in plain words

*For every teammate, technical or not. Read time: about 15 minutes.*

---

## 1. The one-minute version

**Before:** AQMS was a website where a professor filled in a long course-specification form. A colour-coded "quality gate" checked it when it was submitted, then it went to the Head of Department and sometimes to Quality.

**Now:** SAQF works more like an engine running in the background of the university:

1. It **already knows** Al Yamamah's programs, study plans, courses, credit hours, prerequisites and which department owns each course. We transcribed all 14 published YU study plans for this.
2. When the timetable assigns a professor to a course, SAQF **creates that course's quality workspace by itself** and copies in last term's approved outcomes and assessments.
3. It **checks the record continuously** with 35 rules. A mistake shows up while the professor is typing, not after submission.
4. When grades arrive it **calculates** how well students achieved each outcome (CLO) and each program outcome (PLO).
5. If an outcome misses its target, SAQF **opens an improvement action** with an owner and a deadline. Next term it **checks whether results improved**.
6. Only real decisions and real exceptions reach people. Faculty, the Head of Department, Quality, the Dean and the Vice President each get their **own list of what needs them**. Everything else stays in the background.
7. Everything is **recorded** in a tamper-evident audit log, and reports are **generated** from the data rather than written — also as Word documents in the NCAAA layout, in English or Arabic.
8. It works the way a university really works: **courses with several sections**, **existing specifications imported** in one file, **exam papers and samples collected** when results arrive, and a **complete Arabic interface** — course names, learning outcomes and assessments appear in Arabic too.
9. Every screen speaks **plain language**: results are whole numbers next to their goal ("72% · goal 70%"), each page starts with *What needs you*, course facts are sentences ("3 credit hours (about 45 hours of class time)"), and codes such as rule names or file fingerprints are replaced by words or a "✓ Verified" mark.
10. It is **ready to run for real**: university sign-in, two-step verification, HTTPS, encrypted off-site backups that are tested by restoring them, and alerts to IT when something breaks.

Our rule for every screen was: *if the university already knows it, nobody types it; if software can check it, nobody checks it by hand; if it can be calculated, nobody calculates it.*

---

## 2. Before and after, side by side

| Topic | AQMS (before) | SAQF 2.2 (now) |
|---|---|---|
| University data | 63 courses typed into a PHP file. Programs were a plain list with no link to courses | 14 YU programs, 366 courses, 735 study-plan entries and 488 prerequisite links, all connected |
| Starting a course | Professor creates the course and types code, credits, program and so on | Created automatically from the teaching assignment. Nothing to type |
| Wrong combinations | Possible, e.g. putting an undergraduate course into the MBA | Impossible: only valid programs and PLOs are ever offered |
| Each new semester | Fill the whole form again | Approved specification carried forward automatically. Only changes need attention |
| Checking errors | At submission. The document came back for fixes | Continuously, while working. Errors never reach Quality |
| Approvals | Everything went through approval | Unchanged spec: none. Small non-academic edit: automatic. Academic change: HoD sees only what changed |
| Quality (QA) role | Reviewed records one by one | Exception Center: only data conflicts, policy exceptions, risks and a random sample |
| Student results | Not supported | Imported automatically from Moodle, Blackboard or gradebook exports (simulated in the demo). Achievement calculated automatically |
| Weak outcomes | Not detected | Flagged automatically, labelled first, recurring or worsening, with early warnings mid-term |
| Improvement plans | Free-text boxes inside the form | Tracked actions (owner, deadline, status), checked against next term's results |
| Program picture | KPIs typed by hand | Calculated from course data: PLO coverage, achievement, gaps |
| Dean / leadership | A list of every form | Decisions first, then drill-down: college → department → program → course → outcome |
| Reports | A form to print | Generated live. Frozen and sealed when the term closes |
| Audit | Free-text log lines | Who, role, what, old and new value, reason, IP, automated or human, hash-chained |
| IT / maintenance | None | Admin console: Security center (19 controls with live status), health, IT alerts, users, sessions and lockouts, security events, audit search and verification, error log with reference codes, maintenance mode, integration monitor |
| Several sections of a course | Not supported | One coordinator owns the specification; each section's instructor adds results and evidence; sections are compared and a large gap is flagged |
| Existing specifications | Typed in again | Imported from one CSV file, each course checked before anything is written |
| Exam papers and samples (evidence) | Collected by hand before accreditation visits | Requested automatically when results arrive, virus-scanned, stored securely, listed in the course report |
| Word documents for NCAAA | None | Course specification and course report generated as Word files in the NCAAA layout, English or Arabic |
| Language | English only | English and Arabic (right to left), remembered per person; in Arabic, course names, learning outcomes and assessments are Arabic too |
| Wording | Codes and decimals ("SO2 69.3", "0.11 return rounds", "sha256 …") | Plain sentences and whole numbers next to their goal ("SO2 69%", "72% · goal 70%", "✓ Verified") |
| Sign-in security | Password only | University single sign-on, two-step verification (required for administrators), strong-password rules, sessions you can end remotely, re-confirmation before sensitive changes |
| Backups and alerts | None | Encrypted nightly backups (database + evidence files) copied off the server and verified; IT alerted in SAQF, by e-mail and on Teams when anything fails |

---

## 3. The demo story (the same story seen by every role)

All people and grades are fictional. The study plans are YU's real published plans.

The cast is small on purpose: one person per role plus two colleagues, eight in all.

- **Dr. Omar (faculty) – SWE 401 Software Quality Assurance.** CLO3 ("Design a test and quality assurance plan") was met by 53% of students in Fall 2025, below the 70% goal. SAQF drafted an improvement action and Omar wrote what would change (weekly test-design labs). In Spring 2026 it rose to 63%. SAQF reports *"Results went up afterwards"*. It does not claim the labs *caused* the rise. It is still below its goal two terms running, so the Head of Department sees a **recurring gap**.
- **SWE 401 has two sections this term.** Omar coordinates and teaches section 01; **Dr. Sara** teaches section 02. Midterm results show section 02 **16 points behind** section 01 on two outcomes, so SAQF flags it for the coordinator — as a prompt to compare teaching and marking, not a judgement of a person. Because midterm results arrived, SAQF also **asks for the midterm paper**; uploading it clears the request.
- **Dr. Omar – SWE 412.** Omar is revising the course and added "CLO4: *Understand* security risks…". SAQF immediately shows four problems: *understand* is not measurable, CLO4 is not linked to a program outcome, CLO4 is not assessed, and the weights total 105%. Fixing them clears the *Checks* card ("Everything checks out"); the changes can only be sent once they are fixed. The HoD then sees just the two changes.
- **Dr. Omar – CIS 491 capstone.** One report is worth 70% of the grade, but policy allows at most 60%. Omar asks for a **policy exception** with a reason. Quality decides, and the decision is recorded.
- **Dr. Sara (faculty) – SWE 302.** Midterm grades are scheduled to arrive from the LMS. When they are published, SAQF recalculates and raises an **early warning** for a CLO trending at 60% while there is still time to act.
- **Dr. Noura (faculty, Accounting & Finance) – ACC 311 and MIS 327.** Their existing specifications reached SAQF through the **bulk import**, as a university's would on day one.
- **IT, 21 September:** the LMS stopped answering overnight. After two failed runs SAQF raised an **IT alert**; it cleared itself when the LMS came back. The history is under *IT alerts*.
- **Data problems SAQF found in YU's own published plans:** MIS 316 is listed with 3 and with 4 credit hours, and the Management and Marketing plans list 126 of 127 credits. Architecture, EMBA, LLB and LLM publish no PLOs. These go to Quality or the HoD as **data exceptions** instead of silently breaking calculations.
- **Program level:** SWE's outcome SO2 has been below its goal two terms in a row. This escalates to the **Dean**.
- **Live automation for the judges:** the IT admin's *University systems simulator* can (1) publish the SWE 302 midterm grades, (2) publish a new SIS teaching assignment (SWE 413 to a brand-new instructor, Dr. Faisal Al-Dosari), which creates the account and the workspace on the spot, and (3) start Spring 2027, which freezes last term's reports, rolls every course forward and evaluates last term's improvement actions.

---

## 4. How each role uses SAQF now

**Faculty member**
- Opens SAQF and sees *What needs you*, often "Nothing needs you right now".
- Each course has a workspace with seven tabs: Overview, Outcomes & assessment, Results & achievement, Evidence, Improvement, Course report, History.
- In a course with several sections, the coordinator owns the shared specification; section instructors see the course, add results for their section and upload evidence. Achievement is shown per section.
- Downloads the course specification and course report as Word documents in the NCAAA layout (English, or fully Arabic — headings and course content).
- Can type the Arabic wording of an outcome while writing it, so the Arabic interface and Arabic Word files show it.
- Types only academic content: outcomes, assessment design, interpretation of results and improvement responses. Code, credits, programs, prerequisites, PLOs, last term's structure, achievement and the report are all filled in automatically.
- Every value shows where it came from (Registrar, SIS, LMS, Inherited, Calculated, Faculty input, and so on).
- *Done for you this term* on the home page: real counts in plain sentences, such as "46 details filled in from university records", "60 items carried over from your approved specifications" and "102 checks run on your courses".

**Head of Department**
- Sees only courses that need attention, decisions waiting, recurring gaps, program PLO problems and overdue actions. Healthy courses stay out of the way.
- Approves academic changes by looking at the **difference** only.
- Assigns a course only when the SIS feed is missing one. Only their department's courses and the programs that contain them are offered.
- Changing a PLO shows the **impact first** (how many courses, mappings and assessments are affected).
- Can import the department's existing approved specifications from one CSV file.

**Quality (QA)**
- **Problems to sort out**, by type: problems in university records, exceptions to policy, academic decisions, risks, missing evidence, next steps.
- Decides **exceptions to policy** (allow or turn down, with a reason) and can set a rule aside or resolve an item. Nothing is ever silently bypassed.
- Spot-checks a **random sample** of automatically approved specifications (governance without re-checking everything).
- Owns the **Quality policies** page: the achievement method, targets, weight limits, deadlines and sampling rate. Every change is audited with a reason.
- Imports existing specifications for any department (*Import specifications*).
- Sees factual workflow numbers, each explained in a sentence, e.g. "67% of approvals needed nothing from Quality" and "Usually decided within 24 hours".
- Keeps the Arabic version complete on the **Arabic wording** page: what is still in English is listed with a box for its Arabic, or downloaded as a spreadsheet, filled in and uploaded back.

**Dean**
- College page: persistent problems, courses needing attention, improvement completion, approvals without a QA step, and departments with drill-down.
- Persistent program-level problems escalate here automatically.

**University leadership (VP Academic)**
- Programs requiring intervention, what keeps recurring, overdue improvement actions, where gaps concentrate, and quality-cycle completion per term.

**IT / System administrator**
- Runs the platform but **cannot make academic decisions** (separation of duties).
- **Security center:** every security control with its live status and a "how to fix" for anything not yet on. **IT alerts:** problems SAQF found by itself (failing connectors, missed backups, a tampered audit log, blocked malware…), also sent by e-mail and to Teams.
- Health checks, user management (unlock, reset password, reset two-step verification, end sessions, disable, change role, each with a reason — after re-confirming their own identity), security events, the full audit log (filter, CSV export, hash-chain verification), the error log (look up the reference code a user quotes), maintenance mode, and integration runs and events.

**Everyone:** an *Account & security* page to change the password, turn on two-step verification with any authenticator app, see where they are signed in and sign out other devices, and choose English or Arabic.

**Screenshots** of each role are in [`docs/screenshots/`](screenshots/) (taken before the plain-wording update of 2.2, so some labels differ): sign-in, faculty action center, workspace with issues, change diff, improvement effectiveness, HoD department, QA Exception Center, dean, leadership, generated report, MBA study plan, admin health and audit log.

---

## 5. What technology is used, where, and why

| Technology | Where | Why we chose it |
|---|---|---|
| **PHP 8.3** (programming language) | All server logic: `src/` (engine, rules, security) and `public/` (pages) | The original AQMS was PHP. Universities can host it on ordinary web servers, and there is no build step |
| **MySQL 8** with **SQL** | `database/schema.sql`, 38 tables | Academic data is relational (programs ↔ courses ↔ outcomes ↔ assessments ↔ results). Database triggers make the audit log append-only |
| **HTML + CSS** | Page templates in `public/*.php`, styling in `public/assets/app.css` | Server-rendered pages are fast, accessible and work on phones. No heavy framework to maintain |
| **JavaScript (vanilla)** | `public/assets/app.js` (under 200 lines) | Saves edits in place without page reloads, mobile menu, confirmations, the role switcher. Kept small on purpose, and no script is ever written inside a page (so the browser can block injected code) |
| **JSON** | `data/yu/` (YU study plans), `data/demo/` (simulated SIS/LMS feeds) | A simple, readable format for the data that would come from university systems |
| **Python** | `tools/build_yu_snapshot.py` (development only) | Used to turn the YU study-plan PDFs into structured JSON. Not needed to run SAQF |
| **Docker + Apache** | `Dockerfile`, `docker-compose.yml`, `docker/` | One command starts the app and database, set up the way a server would run it |
| **Shell script** | `docker/entrypoint.sh`, `docker/backup.sh`, `docker/restore.sh`, `bin/test_all.sh` | Container start-up; encrypted, verified, off-site backups and the restore; running every test with one command |
| **Caddy** (web server) | `docker/Caddyfile`, compose profile `https` | Gives SAQF HTTPS with automatic certificates in one setting |
| **ClamAV** (antivirus, optional) | compose profile `antivirus` | Scans every uploaded evidence file |
| **OpenSSL** | backups | AES-256 encryption of backup files |

**APIs**
- **Internal API** (`public/api.php`): the pages call it to save CLOs, mappings, assessments, decisions, overrides and policies. It accepts only logged-in users, needs a CSRF token, and checks permission on every record.
- **University connections:** ready-made connectors for the SIS (scheduled CSV export or a REST API), the LMS (Moodle web services, Blackboard Learn REST, or gradebook CSV exports), university single sign-on (OpenID Connect: Microsoft 365 / Entra ID, Google, Keycloak, ADFS) and e-mail (SMTP). IT chooses them with settings; nothing in the code changes. In demo mode they read our JSON files. Setup guide: `docs/INTEGRATIONS.md`.
- **No external or paid services and no AI APIs.** SAQF only talks to the university's own systems, and student identities are pseudonymised before they are stored.

**Built-in tools we rely on:** PDO (safe database access), Argon2id `password_hash` (passwords), SHA-256 `hash` (audit chain, report seals, evidence fingerprints), AES-256-GCM `openssl_encrypt` (stored secrets), `random_bytes` (tokens). We wrote small plain-PHP pieces instead of adding libraries: the Word-document writer, the ZIP packer, the QR-code generator and the authenticator-code check (tested against the official RFC 6238 examples).

**The "smart" parts and how they really work**
- **Rules engine:** 35 plain, explainable rules, e.g. "weights must total 100%". This is deterministic software, *not* AI.
- **Mapping suggestions:** compares the wording of a CLO with each PLO using **TF-IDF text similarity**, a classic statistical method, plus the learning domain. Shown as *Suggested* with its reasoning, and only applied when the professor accepts.
- **Trends and anomalies:** simple statistics over past terms, e.g. "down about 2 points each term" or "below its goal 2 terms in a row".
- We deliberately **do not call any of this AI**.

**Skills that went into it:** software architecture, database design, rules-engine design, event-driven automation, security engineering, UX/HCI (error prevention, progressive disclosure, management by exception), academic quality knowledge (CLO/PLO, NCAAA-oriented workflows) and automated testing.

---

## 6. Made for Al Yamamah University

We went through the public study-plan PDFs on yu.edu.sa (sources listed in `data/yu/SOURCES.md`):

- **College of Engineering:** Software Engineering, Computer Network Engineering, Industrial Engineering, Architecture, and the Master in Cyber Security
- **College of Business:** Accounting, Finance, Management, Marketing, MIS, the MBA and the Executive MBA
- **College of Law:** Law (LL.B) and the Master of Business Law (LL.M)

**Relationships SAQF understands, so it never shows a wrong option:**
- **Bachelor vs Master:** master's programs only show master's courses, and bachelor plans never show graduate courses. The study-plan browser narrows *level → college → program → course type*.
- **Majors and departments:** each course belongs to one department (SWE/CIS → Computer Engineering, ACC/FIN → Accounting & Finance, …). A HoD only sees and assigns their own courses, and only programs whose plan includes that course are offered.
- **Required in one major, elective in another:** e.g. MIS 327 is required in MIS but an elective in Accounting. SAQF treats each case correctly.
- **Prerequisites per program**, credit-hour conditions (e.g. "after 90 credits") and co-requisites are kept.
- **Foundation courses** (e.g. the Pre-MBA courses) are recognised and kept out of credit totals.
- **Outcome mapping:** when a professor maps a CLO, only the PLOs of programs that actually contain the course appear. The server rejects anything else, even if someone tries.

---

## 7. Security and maintenance (simple version)

- **Sign-in:** university single sign-on in production. Passwords stored with Argon2id (the current best practice). Account **locks after 5 wrong tries** for 15 minutes, and too many attempts from one network are blocked (and IT is alerted). Passwords need at least 10 characters with letters and numbers, and common words, keyboard runs ("qwerty"), the person's name and the university's name are refused. Admin resets force a password change.
- **Two-step verification:** anyone can turn it on with Microsoft or Google Authenticator; it is **required for administrators**. A stolen password alone does not open the account. Ten one-time recovery codes cover a lost phone.
- **Sessions:** logged out after 30 minutes idle or 8 hours total. Cookies are protected (HttpOnly, SameSite=Strict, Secure on HTTPS). Everyone can see where they are signed in and sign out other devices; changing the password signs out everywhere else; a sign-in from a new device is notified.
- **Administrators** can be limited to the campus network, and must re-enter their password and code before changing accounts if they signed in more than 15 minutes ago.
- **HTTPS** is built into the Docker stack (one setting: the domain name).
- **Permissions are checked on the server for every record**, not just hidden buttons. A professor typing another course's address gets "access denied", and the attempt is logged.
- **Audit log** records who, role, action, before and after values, reason, IP, and whether it was a person or SAQF. Each entry is chained to the previous one with a SHA-256 fingerprint, and the database refuses edits or deletes. If anyone tampers with the database directly, *Verify audit chain* reports exactly where.
- **Error log:** users see "Something went wrong, reference AB12CD34". IT searches that code to see the technical details. Users never see code or stack traces.
- **Maintenance mode:** IT can pause access for everyone except admins during upgrades, with a reason.
- **Separation of duties:** IT cannot approve academic content. Quality cannot edit a professor's course. Overrides always need a reason.
- **Uploaded files** are checked against their type (macros refused), optionally virus-scanned, stored outside the web server under random names, and every download is logged.
- **Backups** are encrypted, copied to a second location, read back to prove they work, and IT is alerted if one is missing. CI restores one on every change.
- Other protections: CSRF tokens, prepared SQL statements, a strict Content-Security-Policy (no scripts inside pages at all), and demo features automatically switched off in production.
- The **Security center** shows every one of these controls with its live status.
- Step-by-step guide for IT: `docs/OPERATIONS.md`.

---

## 8. Video promise check

Our current 2-minute video script compared with what exists now:

| Video claim | Status now |
|---|---|
| Course data is filled from the study plan (code, hours, level, type) with the source documented | ✅ Goes further: the course is created from the teaching assignment and every value shows its source |
| Quality gate checks measurable verbs, PLO mapping and 100% weights | ✅ Part of 35 continuous rules (`CLO_VAGUE_VERB`, `CLO_UNMAPPED`, `ASSESSMENT_WEIGHT_TOTAL`) |
| "Found four errors; fixed them; the record turned green" | ✅ SWE 412 demo has exactly four problems → "Everything checks out". ⚠️ The shot showing **40% → 100%** should change: SAQF now shows explainable states in plain words (*Needs you → All good*) instead of a percentage score |
| Green approved automatically after HoD with a QA sample; amber to QA; red never sent | ✅ Policy-controlled. A record with blockers cannot be submitted |
| Every transition recorded: who, when, outcome | ✅ Hash-chained audit log, plus old/new values and the reason |
| *Vision:* connect to the SIS so the course arrives ready and inherits last term | ✅ SIS connectors (export folder or API) are built and tested; the term starts by itself on its start date. The demo uses a **simulated** feed. Connecting YU's SIS needs YU IT to provide the export or API access |
| *Vision:* grades → automatic CLO achievement | ✅ Moodle, Blackboard and gradebook-export connectors are built and tested; the demo uses a **simulated** LMS feed. Method configurable |
| *Vision:* below target → improvement plan with owner and date | ✅ Drafted automatically. The professor writes what will change |
| *Vision:* compare the next term's results | ✅ "Results went up / stayed about the same / went down afterwards" |
| Data → engine → roles | ✅ That is the architecture |
| "Before the final we will build achievement and improvement tracking, then pilot and measure return rounds and approval time" | ✅ Built. The measurements exist (changes approved the first time, usual time to a decision, % approvals that needed nothing from Quality). The **pilot with a real department is still to do** |

**Suggested video edits:** replace the 40%→100% shot with *Needs you → All good*. Move "achievement calculation and improvement tracking" from *plan* to *working now*. Keep the pilot as the next step.

---

## 9. How this maps to the FARQ judging criteria

| Criterion (weight) | Our evidence |
|---|---|
| Idea & evolution (41%) | Clear evolution from a digital form (AQMS) to automating the quality cycle (SAQF). Before/after documented in `docs/AUDIT_BEFORE.md`. Differentiation: exception-based QA and the change-based workflow, not a file repository |
| Solution & prototype (27%) | A complete, deployable solution across 6 roles, in English and Arabic, shown in 5 minutes through a guided tour (`docs/JUDGES_DEMO.md`). Event-driven automation is shown live through the simulator |
| Feasibility & execution (17%) | Standard PHP/MySQL that any university can host. Working connectors for the SIS, Moodle/Blackboard, university SSO and e-mail. Docker stack with HTTPS, encrypted off-site backups with a tested restore, health checks and IT alerts; two-step verification and a Security center; six automated test suites (423 checks) in CI; database upgrades; an operations runbook and an IT integration guide. Next step: pilot with one department |
| Impact & sustainability (10%) | Factual automation counts: in the demo scenario SAQF populated 5,991 fields, ran 7,948 checks, inherited 308 records, made 152 calculations and auto-cleared 139 issues, while only 12 issues ever needed a person above faculty level. Policies are configurable, so other universities could adopt it |
| Presentation (5%) | The 5-minute script and Q&A sheet (`docs/JUDGES_DEMO.md`), the guided tour in the app, a plain test-case list (`docs/TEST_CASES.md`), screenshots in `docs/screenshots/` and an updated video plan (section 8) |

> These counts come from the demo replay (fictional data). Do not present them as measured time savings at YU.

---

## 10. The 5-minute live demo

The full script with timings, what to say, what to highlight and a Q&A sheet is in **[`JUDGES_DEMO.md`](JUDGES_DEMO.md)**. In the app, the **Guided tour** (`/tour.php`, linked from the sign-in page and the demo bar) has one button per step that signs in as the right person and opens the right page:

1. **Sign-in page in Arabic** (0:00) — and back to English.
2. **Dr. Omar, SWE 412** (0:30): four problems found by SAQF; change *Lab assignments* 25 → 20 and *Save weights*; that problem clears itself.
3. **Dr. Omar, SWE 401** (1:15): section 02 is 16 points behind; *Improvement* 53% → 63%, "Results went up afterwards"; *Evidence*: upload the midterm paper and the request clears; download the Word report in English or Arabic.
4. **Head of Department** (2:15): only what needs a decision.
5. **Quality** (2:45): allow the CIS 491 exception with a reason; *Import specifications*; *Arabic wording*.
6. **IT** (3:30): *Publish now* (grades → early warning), *Publish assignment* (new instructor → account + workspace), *Security center*.
7. **Vice President** (4:30): the whole university. Close with "not a prototype: 423 automated checks, HTTPS, encrypted backups, SSO, two-step verification, Arabic throughout".

---

## 11. What was tested

Everything runs with one command: `sh bin/test_all.sh` (or `docker compose exec app sh bin/test_all.sh`) — **423 checks, 0 failures**. The plain-language list of what each feature does and how to see it is [`TEST_CASES.md`](TEST_CASES.md).

- **58 automation scenario checks** (`tests/automation_test.php`), including: assignment creates a workspace, editing an outcome re-validates everything, grades trigger achievement, a missed target triggers a finding and a draft action, recurring gaps escalate, the semester rollover inherits structure, improvement effectiveness is evaluated, a PLO change shows its impact, the override lifecycle works, data conflicts are resolved, and a forged audit entry is detected.
- **118 page and security checks** (`tests/http_smoke.php`), including: every page for every role, plus over 20 deliberate break-in attempts (professor opening another professor's course, a section instructor editing the coordinator's specification, a HoD from another department, faculty calling QA actions, missing CSRF token, anonymous access). Also account lockout, error-log lookup, audit verification from the console, and maintenance mode.
- **96 production-capability checks** (`tests/production_test.php`): the SIS and LMS connectors against stand-ins for Moodle, Blackboard and a SIS API, the export folders, the automatic semester start, new-instructor accounts, e-mail delivery (including Arabic text), password reset, and sign-in token security (forged, expired, replayed and wrong-application tokens are refused).
- **29 end-to-end sign-in checks** (`tests/sso_test.php`): the full university sign-in through a stand-in identity provider, including the attacks it must refuse and the administrator's two-step sign-in.
- **91 checks of the new features** (`tests/features_test.php`): sections, the specification import, evidence uploads (including a stand-in virus scanner and the standard EICAR test virus), Word exports, two-step verification (against the official RFC test codes), password rules, sessions, re-confirmation, network rules, IT alerts with a stand-in Teams webhook, backup monitoring, the Arabic interface, and proof that the demo shortcuts do not exist in production mode.
- **31 plain-wording and Arabic-content checks** (`tests/wording_test.php`): results as whole numbers with no decimals or hashes on screen, course facts in sentences, "What needs you" without jargon, Arabic course names from the Registrar (and a person's correction surviving the next sync), Arabic course content, the instructor typing the Arabic of an outcome, Arabic search, the *Arabic wording* page and spreadsheet (and that IT may word names but not course content), and the Arabic Word report.
- Visual review of every page at desktop and phone widths, in English and in Arabic; an Arabic crawl of 117 screens finds no interface text left in English (codes such as SWE 401 or ISO 25010 stay as they are).
- **Where it was tested:** real **MySQL 8.0** and the **Docker** stack (Apache, MySQL, the HTTPS proxy, backups) — all six suites pass in both. An encrypted backup was restored and its audit chain verified, and CI repeats that on every push to GitHub (`.github/workflows/ci.yml`), together with HTTPS checks. Running the Docker stack under Apache uncovered one page (generated reports) that answered a refused request with the wrong status code; it is fixed and covered by the tests.

---

## 12. Demo mode versus a real deployment

In **demo mode** (the default for `docker compose up`):
- **People, teaching assignments and student results** are fictional, delivered through simulated SIS and LMS feeds, and the IT admin's *University systems simulator* plays the university systems.
- The clock is anchored to Fall 2026, week 6, so the story looks the same whenever it is installed.

In **production mode** (`APP_ENV=production`) the same engine runs on real data:
- the SIS connector delivers the calendar and teaching assignments; terms start on their start date by themselves;
- the LMS connector imports grades as they are published;
- people sign in with their university account, and new instructors get accounts from the SIS feed;
- notifications reach people by e-mail; backups (encrypted, copied off the server), health checks, IT alerts and database upgrades are automatic; HTTPS is one setting.

Still true in both modes:
- The **Registrar data** shipped with SAQF is a structured snapshot of YU's public study plans. The Registrar can replace it with its own export in the same format.
- The **achievement method and targets** (70%) are configurable defaults. YU's Deanship of Quality must confirm the real methodology.
- SAQF **supports NCAAA-oriented workflows**. It is not certified as NCAAA-compliant.

## 13. What YU provides to go live

Everything on SAQF's side is built and tested. What only the university can provide:

1. **SIS:** a nightly export of terms and teaching assignments (two CSV files), or API access.
2. **LMS:** a Moodle web-service token or a Blackboard REST application, and course IDs that follow one pattern (e.g. `2026-1-SWE401`).
3. **Sign-in:** an app registration in YU's identity provider (e.g. Microsoft 365), optionally with SAQF roles.
4. **E-mail:** a mailbox or relay SAQF can send from.
5. **Hosting:** a server with Docker (or PHP + MySQL), an address such as `saqf.yu.edu.sa` (SAQF obtains the HTTPS certificate itself, or uses the university's), and a second location for backup copies.
6. **Decisions:** the Deanship of Quality confirms the policies; Heads of Department enter PLOs for the four programs without published ones (Architecture, EMBA, LLB, LLM).

Step-by-step instructions for IT: `docs/INTEGRATIONS.md` and `docs/OPERATIONS.md`.

---

## Glossary

- **CLO:** Course Learning Outcome, what students should be able to do after a course.
- **PLO / SO:** Program Learning Outcome (Student Outcome), what graduates of a program should be able to do.
- **Mapping:** which PLOs a CLO contributes to.
- **Achievement:** the share of students who reached the expected level on a CLO's assessments.
- **Specification:** the approved course document (outcomes, assessments, topics, resources).
- **Finding / exception:** something a rule detected. *Blockers* stop submission. *Warnings* and *exceptions* go to the right person.
- **Override:** an authorised, reasoned decision to set a rule aside for one case.
- **Snapshot:** a frozen, sealed copy of a report at the end of a term.
- **Section:** one class group of a course; a course can have several sections taught by different instructors, coordinated by one of them.
- **Evidence:** the assessment itself (exam paper, rubric) and samples of marked student work, kept in the course file for accreditation reviewers.
- **Two-step verification (MFA):** signing in needs the password *and* a 6-digit code from an authenticator app on the person's phone.
- **NCAAA:** the Saudi National Center for Academic Accreditation and Assessment.
