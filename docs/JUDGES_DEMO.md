# SAQF in 7 minutes: the judges' demo

*One page to rehearse with. One faculty happy path (grades → facts → evidence → course file), one reviewer view,
one integration proof and one security check. Every number below is what a fresh demo shows; the whole path was
walked in a real browser before this page was written.*

**Say this first, and keep to it:** "Everything you will see runs on fictional people, simulated university
feeds and synthetic results, on top of Al Yamamah's public study plans. It is a hardened, Docker-deployable demo
with an integration-ready handoff. It is not connected to Edugate or the LMS yet, and it is not certified by anyone."

---

## Before the judges arrive (5 minutes, once)

1. Start SAQF: `docker compose up -d --build`, wait until `curl -s localhost:8080/health.php` shows `"status":"ok"`.
2. Reset the story so the numbers below are exactly as written (about 30 seconds; **demo database only**):
   ```bash
   docker compose exec app php bin/install.php --demo --fresh
   ```
3. Open **http://localhost:8080/tour.php** and **http://localhost:8080/login.php** in two tabs, and a terminal in the repository folder.
4. Have these synthetic files ready (no real student data in any of them):
   - `docs/demo/SWE401-final-exam-marks.csv` (29 fictional students, sections 01 and 02, student numbers `2099…`)
   - `docs/demo/SWE401 Final exam paper.pdf`, `SWE401 Final exam rubric.pdf`, `SWE401 Final exam marked samples.pdf`
5. Browser zoom at 90% on a laptop screen. No internet connection is needed.

---

## The script (7:00)

| Time | Click | Show | Say |
|---|---|---|---|
| **0:00** | Login → *Use this account* → **Faculty Member** (Dr. Omar) | *My courses*: each course card has a **course-file progress bar** ("Course file: 3 of 7 done · next: …") and *What needs you* lists only real tasks | "The course records came from the (simulated) timetable. An instructor opens SAQF and sees only what needs them." |
| **0:40** | SWE 401 → *Results & achievement* → upload `SWE401-final-exam-marks.csv` | **Check before importing**: 29 students (29 enrolled), Final exam average 66.5%, range 20–89.4%, sections 01 and 02, **"Nothing imported yet"** | "Before anything is written, SAQF shows what it understood: students, averages, replacements, warnings for a 0–1 scale or a column of zeros. Student numbers were already replaced by keyed codes: not this page, not the session, not the database ever holds one." |
| **1:20** | **Import these marks** | "29 results imported; achievement recalculated automatically" | "A person confirms; SAQF does the arithmetic." |
| **1:40** | *Course report* tab | **Facts for your reading**: "3 of 4 assessments have grades…", "CLO1: 72% of students met it (goal 70%) · 8 points down from Spring 2026", "CLO1: section 02 is 16 points behind section 01", "CLO2 … 8 points short" | "Plain facts next to the box where the instructor writes. Numbers, never meaning: why the results look like this and what to do is the instructor's academic judgement. SAQF never writes it." |
| **2:20** | *Evidence* tab → choose the **three PDFs at once** | Each file is pre-filled: *Assessment paper / brief*, *Marking rubric*, *Sample of marked student work*, all for **Final exam** | "SAQF suggests what each file is from its name, in English or Arabic, and the instructor corrects anything wrong. Titles are built from the assessment, never from the file name, because file names of marked work often carry a student's name." |
| **2:50** | **Upload** → *Course file closeout* tab | "3 files added"; the evidence item now **needs a person's review**; a new missed goal (CLO2) has an improvement record waiting for the instructor's own plan | "Grades arriving revealed a missed goal, so SAQF prepared the improvement record with the facts. The plan itself, and accepting the evidence, stay with people." |
| **3:30** | Tour → *Review the SWE 401 course file* (Head of Department) → then sidebar **Course file closeout** | The HoD can **Accept** or **Return with note**; the **closeout board** shows every department course with progress bars and an audited CSV export | "Evidence is accepted by the Head of Department or Quality, never by SAQF and never by the instructor. Reminders go out 14, 7 and 2 days before grades are due, then weekly; the HoD hears weekly about overdue files." |
| **4:15** | Terminal: `php bin/mapping_check.php docs/mappings/university-lms.simulated.json --system=lms --sample=grades=docs/mappings/samples/marks.json` | "36 rows read → 2 assessments, 12 students… Student identifiers are shown nowhere" | "Connecting a university system is configuration, not code. This is a *simulated* university API shaped nothing like ours: nested objects, coded columns, three paging styles. A mapping file describes it, and the checker shows what SAQF would read, offline. We did not see Edugate's API documentation and did not guess it; when IT provides it, they write the mapping and a service account, and test it here first." |
| **5:00** | Tour → *Go-live: ready to connect* (IT) | Every connection **Demo data**, the exact next step, templates, and the **Production preflight** with its blockers | "The preflight is honest: in this demo it blocks go-live because demo accounts still use a published password." |
| **5:30** | *Security center and self-test* → **Run security self-test**; then *Security incidents* | Controls with their recorded status (some deliberately *not configured* in the demo); passkeys; audit-chain witnesses; the incident register with a 72-hour notification clock | "IT sees what is and is not configured. The audit log is hash-chained and checkpoints are sent outside the server, so a rewrite by a database administrator becomes detectable: tamper-evident, not tamper-proof. The incident register keeps the 72-hour clock; whether to notify is the data protection officer's decision; SAQF never contacts anyone. The self-test is a self-check, not a penetration test." |
| **6:30** | **العربية** at the top (on the *Course report* tab) | The same facts in Arabic, right to left | "The whole interface is in Arabic; course names come from the catalogue." |
| **6:45** | — | — | **Closing:** "A working course-file workflow, automated everywhere except where a person must decide, with the security controls and the integration path a university needs. The automated suites in `bin/test_all.sh` and a Docker, HTTPS, backup-restore and hardened-production check run on every change. Next step: a controlled pilot with one department once IT provides access (`docs/READINESS.md`)." |

**If time is short:** skip 2:50 and 5:00. **If there is extra time:** the *Sign in the real way* tour step
(robot check, e-mailed code, passkeys under *Account & security*) and the phone-sized window.

---

## What not to claim

| Do not say | Say instead |
|---|---|
| "Connected to / works with YU's systems" | "Connectors are tested against stand-in servers, including a simulated university API; connecting YU needs IT's approved access and API documentation." |
| "Production-ready" / "certified" | "A hardened, Docker-deployable demo; a candidate for a controlled pilot once the prerequisites are met." |
| "Penetration tested" / "proves it is secure" | "Automated probes and a self-test of some protections; no penetration test or independent review yet." |
| "Tamper-proof audit log" | "Tamper-evident, with checkpoints kept outside the server." |
| "Passkeys are fully secure" | "Passkeys add a phishing-resistant second step; our implementation is small, tested with hostile inputs and a real browser, and has not been independently reviewed." |
| "PDPL-compliant" / "NCAAA-compliant" | "Designed with data minimisation and pseudonymisation; compliance is for the university's DPO and Quality to decide." |
| "AI" | "35 explainable rules and simple statistics; no AI service is called." |

## Likely questions and short answers

See [PRESENTATION.md](PRESENTATION.md#questions-and-honest-answers).

---

## Reset and recovery

- **Numbers look different from the script** → `docker compose exec app php bin/install.php --demo --fresh` (demo database only; refused in production), then reopen the tour.
- **Wrong person signed in** → **Switch role** in the yellow demo bar.
- **The app container will not start** → `docker compose logs app | tail -30`; then `docker compose down` and `docker compose up -d --build`. Last resort, **demo only**: `docker compose down -v`.

## Fallback if the live demo cannot run

1. **No Docker:** local PHP 8.3 and MySQL 8: `php bin/install.php --demo` with `SAQF_DB_*` set, then `php -S 127.0.0.1:8080 -t public` (README, *Quick start: local PHP*).
2. **Nothing runs:** present from [`docs/screenshots/`](screenshots/) (30–35: home progress, gradebook preview, facts in English and Arabic, evidence suggestions, closeout board; 26–27: course file closeout) and the CI run on GitHub. `php bin/mapping_check.php` needs no database and no network, so step 4:15 still works. Say plainly that the live demo did not start.
