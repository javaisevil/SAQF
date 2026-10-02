# SAQF 2.0: what we built, in plain words

*For every teammate, technical or not. Read time: about 15 minutes.*

---

## 1. The one-minute version

**Before:** AQMS was a website where a professor filled in a long course-specification form. A colour-coded "quality gate" checked it when it was submitted, then it went to the Head of Department and sometimes to Quality.

**Now:** SAQF works more like an engine running in the background of the university:

1. It **already knows** Al Yamamah's programs, study plans, courses, credit hours, prerequisites and which department owns each course. We transcribed all 14 published YU study plans for this.
2. When the timetable assigns a professor to a course, SAQF **creates that course's quality workspace by itself** and copies in last term's approved outcomes and assessments.
3. It **checks the record continuously** with 33 rules. A mistake shows up while the professor is typing, not after submission.
4. When grades arrive it **calculates** how well students achieved each outcome (CLO) and each program outcome (PLO).
5. If an outcome misses its target, SAQF **opens an improvement action** with an owner and a deadline. Next term it **checks whether results improved**.
6. Only real decisions and real exceptions reach people. Faculty, the Head of Department, Quality, the Dean and the Vice President each get their **own list of what needs them**. Everything else stays in the background.
7. Everything is **recorded** in a tamper-evident audit log, and reports are **generated** from the data rather than written.

Our rule for every screen was: *if the university already knows it, nobody types it; if software can check it, nobody checks it by hand; if it can be calculated, nobody calculates it.*

---

## 2. Before and after, side by side

| Topic | AQMS (before) | SAQF 2.0 (now) |
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
| IT / maintenance | None | Admin console: health, users and lockouts, security events, audit search and verification, error log with reference codes, maintenance mode, integration monitor |

---

## 3. The demo story (the same story seen by every role)

All people and grades are fictional. The study plans are YU's real published plans.

- **Dr. Omar (faculty) – SWE 401 Software Quality Assurance.** CLO3 ("Design a test and quality assurance plan") scored 53.3% in Fall 2025, below the 70% target. SAQF drafted an improvement action and Omar wrote the academic response (weekly test-design labs). In Spring 2026 it rose to 63.3%. SAQF reports *"Performance improved following the intervention"*. It does not claim the labs *caused* the rise. It is still below target two terms running, so the Head of Department sees a **recurring gap**.
- **Dr. Faisal (faculty) – SWE 412.** He is revising the course and added "CLO4: *Understand* security risks…". SAQF immediately shows four problems: *understand* is not measurable, CLO4 is not mapped to a PLO, CLO4 is not assessed, and the weights total 105%. He fixes them and the course turns **Ready**. He can only submit once they are fixed. The HoD then sees just the two changes.
- **Dr. Yousef (faculty) – CIS 491 capstone.** One report is worth 70% of the grade, but policy allows at most 60%. He asks for a **policy exception** with a reason. Quality decides, and the decision is recorded.
- **Dr. Sara (faculty) – SWE 302.** Midterm grades are scheduled to arrive from the LMS. When they are published, SAQF recalculates and raises an **early warning** for a CLO trending at 60% while there is still time to act.
- **Data problems SAQF found in YU's own published plans:** MIS 316 is listed with 3 and with 4 credit hours, and the Management and Marketing plans list 126 of 127 credits. Architecture, EMBA, LLB and LLM publish no PLOs. These go to Quality or the HoD as **data exceptions** instead of silently breaking calculations.
- **Program level:** SWE's outcome SO2 has been below target for two consecutive terms. This escalates to the **Dean**.
- **Live automation for the judges:** the IT admin's *Integration simulator* can (1) publish the SWE 302 midterm grades, (2) publish a new SIS teaching assignment (SWE 413 to Dr. Faisal), which creates the workspace on the spot, and (3) start Spring 2027, which freezes last term's reports, rolls every course forward and evaluates last term's improvement actions.

---

## 4. How each role uses SAQF now

**Faculty member**
- Opens SAQF and sees *What needs you*, often "Nothing needs you right now".
- Each course has a workspace with six tabs: Overview, Outcomes & assessment, Results & achievement, Improvement, Course report, History.
- Types only academic content: outcomes, assessment design, interpretation of results and improvement responses. Code, credits, programs, prerequisites, PLOs, last term's structure, achievement and the report are all filled in automatically.
- Every value shows where it came from (Registrar, SIS, LMS, Inherited, Calculated, Faculty input, and so on).
- *Prepared automatically* panel: factual counts such as "13 fields populated, 17 records inherited, 20 checks run".

**Head of Department**
- Sees only courses that need attention, decisions waiting, recurring gaps, program PLO problems and overdue actions. Healthy courses stay out of the way.
- Approves academic changes by looking at the **difference** only.
- Assigns a course only when the SIS feed is missing one. Only their department's courses and the programs that contain them are offered.
- Changing a PLO shows the **impact first** (how many courses, mappings and assessments are affected).

**Quality (QA)**
- **Exception Center** with categories: data, policy, academic, quality risk, evidence, workflow.
- Decides **policy exceptions** (approve or reject with a reason) and can set a rule aside or resolve an item. Nothing is ever silently bypassed.
- Spot-checks a **random sample** of automatically approved specifications (governance without re-checking everything).
- Owns the **Quality policies** page: the achievement method, targets, weight limits, deadlines and sampling rate. Every change is audited with a reason.
- Sees factual workflow numbers, e.g. "89% of approvals needed no QA step" and "average 0.11 return rounds".

**Dean**
- College page: persistent problems, courses needing attention, improvement completion, approvals without a QA step, and departments with drill-down.
- Persistent program-level problems escalate here automatically.

**University leadership (VP Academic)**
- Programs requiring intervention, what keeps recurring, overdue improvement actions, where gaps concentrate, and quality-cycle completion per term.

**IT / System administrator**
- Runs the platform but **cannot make academic decisions** (separation of duties).
- Health checks, user management (unlock, reset password, disable, change role, each with a reason), security events, the full audit log (filter, CSV export, hash-chain verification), the error log (look up the reference code a user quotes), maintenance mode, and integration runs and events.

**Screenshots** of each role are in [`docs/screenshots/`](screenshots/): sign-in, faculty action center, workspace with issues, change diff, improvement effectiveness, HoD department, QA Exception Center, dean, leadership, generated report, MBA study plan, admin health and audit log.

---

## 5. What technology is used, where, and why

| Technology | Where | Why we chose it |
|---|---|---|
| **PHP 8.3** (programming language) | All server logic: `src/` (engine, rules, security) and `public/` (pages) | The original AQMS was PHP. Universities can host it on ordinary web servers, and there is no build step |
| **MySQL 8** with **SQL** | `database/schema.sql`, 38 tables | Academic data is relational (programs ↔ courses ↔ outcomes ↔ assessments ↔ results). Database triggers make the audit log append-only |
| **HTML + CSS** | Page templates in `public/*.php`, styling in `public/assets/app.css` | Server-rendered pages are fast, accessible and work on phones. No heavy framework to maintain |
| **JavaScript (vanilla)** | `public/assets/app.js` (about 130 lines) | Saves edits in place without page reloads, mobile menu, confirmations. Kept small on purpose |
| **JSON** | `data/yu/` (YU study plans), `data/demo/` (simulated SIS/LMS feeds) | A simple, readable format for the data that would come from university systems |
| **Python** | `tools/build_yu_snapshot.py` (development only) | Used to turn the YU study-plan PDFs into structured JSON. Not needed to run SAQF |
| **Docker + Apache** | `Dockerfile`, `docker-compose.yml`, `docker/` | One command starts the app and database, set up the way a server would run it |
| **Shell script** | `docker/entrypoint.sh` | Container start-up: wait for the database, install on first run, start the scheduler |

**APIs**
- **Internal API** (`public/api.php`): the pages call it to save CLOs, mappings, assessments, decisions, overrides and policies. It accepts only logged-in users, needs a CSRF token, and checks permission on every record.
- **University connections:** ready-made connectors for the SIS (scheduled CSV export or a REST API), the LMS (Moodle web services, Blackboard Learn REST, or gradebook CSV exports), university single sign-on (OpenID Connect: Microsoft 365 / Entra ID, Google, Keycloak, ADFS) and e-mail (SMTP). IT chooses them with settings; nothing in the code changes. In demo mode they read our JSON files. Setup guide: `docs/INTEGRATIONS.md`.
- **No external or paid services and no AI APIs.** SAQF only talks to the university's own systems, and student identities are pseudonymised before they are stored.

**Built-in tools we rely on:** PDO (safe database access), bcrypt `password_hash` (passwords), SHA-256 `hash` (audit chain and report seals), `random_bytes` (tokens).

**The "smart" parts and how they really work**
- **Rules engine:** 33 plain, explainable rules, e.g. "weights must total 100%". This is deterministic software, *not* AI.
- **Mapping suggestions:** compares the wording of a CLO with each PLO using **TF-IDF text similarity**, a classic statistical method, plus the learning domain. Shown as *Suggested* with its reasoning, and only applied when the professor accepts.
- **Trends and anomalies:** simple statistics over past terms, e.g. "dropped 12 points" or "below target 2 terms in a row".
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

- **Sign-in:** passwords stored with bcrypt. Account **locks after 5 wrong tries** for 15 minutes, and too many attempts from one network are blocked. Passwords need at least 10 characters with letters and numbers. Admin resets force a password change.
- **Sessions:** logged out after 30 minutes idle or 8 hours total. Cookies are protected (HttpOnly, SameSite=Strict, Secure on HTTPS).
- **Permissions are checked on the server for every record**, not just hidden buttons. A professor typing another course's address gets "access denied", and the attempt is logged.
- **Audit log** records who, role, action, before and after values, reason, IP, and whether it was a person or SAQF. Each entry is chained to the previous one with a SHA-256 fingerprint, and the database refuses edits or deletes. If anyone tampers with the database directly, *Verify audit chain* reports exactly where.
- **Error log:** users see "Something went wrong, reference AB12CD34". IT searches that code to see the technical details. Users never see code or stack traces.
- **Maintenance mode:** IT can pause access for everyone except admins during upgrades, with a reason.
- **Separation of duties:** IT cannot approve academic content. Quality cannot edit a professor's course. Overrides always need a reason.
- Other protections: CSRF tokens, prepared SQL statements, a strict Content-Security-Policy, and demo features automatically switched off in production.
- Step-by-step guide for IT: `docs/OPERATIONS.md`.

---

## 8. Video promise check

Our current 2-minute video script compared with what exists now:

| Video claim | Status now |
|---|---|
| Course data is filled from the study plan (code, hours, level, type) with the source documented | ✅ Goes further: the course is created from the teaching assignment and every value shows its source |
| Quality gate checks measurable verbs, PLO mapping and 100% weights | ✅ Part of 33 continuous rules (`CLO_VAGUE_VERB`, `CLO_UNMAPPED`, `ASSESSMENT_WEIGHT_TOTAL`) |
| "Found four errors; fixed them; the record turned green" | ✅ SWE 412 demo has exactly four issues → *Ready*. ⚠️ The shot showing **40% → 100%** should change: SAQF now shows explainable states (*Action required → Ready*) instead of a percentage score |
| Green approved automatically after HoD with a QA sample; amber to QA; red never sent | ✅ Policy-controlled. A record with blockers cannot be submitted |
| Every transition recorded: who, when, outcome | ✅ Hash-chained audit log, plus old/new values and the reason |
| *Vision:* connect to the SIS so the course arrives ready and inherits last term | ✅ SIS connectors (export folder or API) are built and tested; the term starts by itself on its start date. The demo uses a **simulated** feed. Connecting YU's SIS needs YU IT to provide the export or API access |
| *Vision:* grades → automatic CLO achievement | ✅ Moodle, Blackboard and gradebook-export connectors are built and tested; the demo uses a **simulated** LMS feed. Method configurable |
| *Vision:* below target → improvement plan with owner and date | ✅ Drafted automatically. The professor writes the academic response |
| *Vision:* compare the next term's results | ✅ "Performance improved / similar / declined following the intervention" |
| Data → engine → roles | ✅ That is the architecture |
| "Before the final we will build achievement and improvement tracking, then pilot and measure return rounds and approval time" | ✅ Built. The measurements exist (average return rounds, median hours to decision, % approvals with no QA step). The **pilot with a real department is still to do** |

**Suggested video edits:** replace the 40%→100% shot with *Action required → Ready*. Move "achievement calculation and improvement tracking" from *plan* to *working now*. Keep the pilot as the next step.

---

## 9. How this maps to the FARQ judging criteria

| Criterion (weight) | Our evidence |
|---|---|
| Idea & evolution (41%) | Clear evolution from a digital form (AQMS) to automating the quality cycle (SAQF). Before/after documented in `docs/AUDIT_BEFORE.md`. Differentiation: exception-based QA and the change-based workflow, not a file repository |
| Solution & prototype (27%) | A working end-to-end demo across 6 roles. Event-driven automation can be shown live through the simulator |
| Feasibility & execution (17%) | Standard PHP/MySQL that any university can host. Working connectors for the SIS, Moodle/Blackboard, university SSO and e-mail. Docker stack with backups and health checks, four automated test suites in CI, database upgrades, an operations runbook and an IT integration guide. Next step: pilot with one department |
| Impact & sustainability (10%) | Factual automation counts: in the demo scenario SAQF populated 5,991 fields, ran 7,797 checks, inherited 308 records, made 152 calculations and auto-cleared 138 issues, while only 12 issues needed a person. Policies are configurable, so other universities could adopt it |
| Presentation (5%) | Screenshots in `docs/screenshots/` and an updated video plan (section 8) |

> These counts come from the demo replay (fictional data). Do not present them as measured time savings at YU.

---

## 10. Three-minute live demo path

1. **Sign in as `f.faisal`** (password `Yamamah@2026`). The SWE 412 workspace was prepared automatically. Show the four issues, fix one, and watch it disappear.
2. **`f.omar`** → SWE 401 → *Improvement*: Fall 2025 action, 53.3% → 63.3%, "improved following the intervention".
3. **`hod.ced`**: only exceptions, the recurring CLO3 gap and SO2. Open *Study plans* and choose MBA to show that only master's courses appear.
4. **`qa.director`**: Exception Center. Approve or reject the CIS 491 70% capstone exception with a reason.
5. **`dean.coe`** → drill down from college to department to course.
6. **`it.admin`** → *Integrations* → **Publish now** (SWE 302 midterm) → early warning appears for `f.sara`. Then *Audit log* → **Verify audit chain**.

---

## 11. What was tested

- **57 automation scenario checks** (`tests/automation_test.php`), including: assignment creates a workspace, editing an outcome re-validates everything, grades trigger achievement, a missed target triggers a finding and a draft action, recurring gaps escalate, the semester rollover inherits structure, improvement effectiveness is evaluated, a PLO change shows its impact, the override lifecycle works, data conflicts are resolved, and a forged audit entry is detected.
- **101 page and security checks** (`tests/http_smoke.php`), including: every page for every role, plus about 20 deliberate break-in attempts (professor opening another professor's course, a HoD from another department, faculty calling QA actions, missing CSRF token, anonymous access). Also account lockout, error-log lookup, audit verification from the console, and maintenance mode.
- **96 production-capability checks** (`tests/production_test.php`): the SIS and LMS connectors against stand-ins for Moodle, Blackboard and a SIS API, the export folders, the automatic semester start, new-instructor accounts, e-mail delivery (including Arabic text), password reset, and sign-in token security (forged, expired, replayed and wrong-application tokens are refused).
- **28 end-to-end sign-in checks** (`tests/sso_test.php`): the full university sign-in through a stand-in identity provider, including the attacks it must refuse.
- Visual review of every page at desktop and phone widths.
- **Where it was tested:** real **MySQL 8.0** and the **Docker** stack (Apache, MySQL, backups) — all four suites pass in both. A backup was restored and its audit chain verified. Every push to GitHub runs the suites again (`.github/workflows/ci.yml`). Running the Docker stack under Apache uncovered one page (generated reports) that answered a refused request with the wrong status code; it is fixed and covered by the tests.

---

## 12. Demo mode versus a real deployment

In **demo mode** (the default for `docker compose up`):
- **People, teaching assignments and student results** are fictional, delivered through simulated SIS and LMS feeds, and the IT admin's *Integration simulator* plays the university systems.
- The clock is anchored to Fall 2026, week 6, so the story looks the same whenever it is installed.

In **production mode** (`APP_ENV=production`) the same engine runs on real data:
- the SIS connector delivers the calendar and teaching assignments; terms start on their start date by themselves;
- the LMS connector imports grades as they are published;
- people sign in with their university account, and new instructors get accounts from the SIS feed;
- notifications reach people by e-mail; backups, health checks and database upgrades are automatic.

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
5. **Hosting:** a server with Docker (or PHP + MySQL), HTTPS and an address such as `saqf.yu.edu.sa`.
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
- **NCAAA:** the Saudi National Center for Academic Accreditation and Assessment.
