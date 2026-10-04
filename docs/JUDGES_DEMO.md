# SAQF in 5 minutes: the judges' demo

*One page to rehearse with. One happy path (a course file, from instructor to reviewer) and one
security/quality check. Every step is one click from the **Guided tour** (`/tour.php`).*

**Say this first, and keep to it:** "Everything you will see runs on fictional people, simulated
university feeds and synthetic results, on top of Al Yamamah's public study plans. It is a
Docker-deployable demo with an integration-ready handoff. It is not connected to Edugate or the LMS
yet, and it is not certified by anyone."

---

## Before the judges arrive (5 minutes, once)

1. Start SAQF: `docker compose up -d --build`, wait until `curl -s localhost:8080/health.php` shows `"status":"ok"`.
2. Reset the story so the numbers below are exactly as written (about 30 seconds; **demo database only**):
   ```bash
   docker compose exec app php bin/install.php --demo --fresh
   ```
3. Open two browser tabs: **http://localhost:8080/tour.php** and **http://localhost:8080/login.php**.
4. Have the sample paper ready for the live upload: [`docs/demo/SWE401-midterm-exam-paper.pdf`](demo/SWE401-midterm-exam-paper.pdf) (a synthetic paper, no student data).
5. Browser zoom at 90% on a laptop screen.

No internet connection is needed: SAQF and the simulated university systems run on the laptop.

---

## The script (5:00)

| Time | Click | Show | Say |
|---|---|---|---|
| **0:00** | Tour → *Open SWE 401 as Dr. Omar* (*SWE 401 results by section*) | Whole-number results with their goal; **section 02 is 16 points behind section 01**, flagged automatically | "The course record came from the (simulated) timetable; the grades came from the (simulated) LMS. SAQF works out, per outcome and per section, how many students met each outcome. Student numbers never reach the database: they are replaced by keyed pseudonyms on every path, including a manual upload." |
| **0:45** | *Course file closeout* tab | **2 complete, 4 missing**: each line names the owner and the next step; the checklist is marked as *SAQF's default, not yet confirmed by Quality*; DEMO label | "This is the end-of-term question every instructor gets: what is still missing from my course file? Everything here is read from records. Nothing is marked done until the records show it, and Quality decides what the checklist requires." |
| **1:30** | *Evidence* tab → upload `SWE401-midterm-exam-paper.pdf` as *Assessment paper* for *Midterm exam* | The "Please add the papers" banner clears; back on *Course file closeout*, the Midterm exam now lacks only the marked samples | "The upload is checked by type and content and stored outside the web server. In this demo there is no virus scanner configured, and the Security center says so rather than pretending." |
| **2:00** | *Course report* tab → type two sentences in *What the results mean* → Save; back to *Course file closeout* | The reflection line turns **Complete** and names Dr. Omar | "SAQF never writes this. The instructor's reading of the results, the suggestions and every improvement plan are human input; SAQF fills in the facts around them." |
| **2:30** | Tour → *Review the SWE 401 course file* (Head of Department) | *Review the evidence*: open the file, **Return with note** or **Accept the evidence** | "Evidence is accepted by a person, the Head of Department or Quality, never by SAQF, and never by the instructor who uploaded it. The decision is in the audit log; if a file changes, the review is due again." |
| **3:00** | *Download the course file package* | ZIP: Word report and specification, evidence index, grade provenance, checklist, README (DEMO, report not sealed, not an NCAAA approval) and SHA-256 checksums | "One package for reviewers, with checksums. It holds no student identities and not the evidence files themselves. The download is audited." |
| **3:30** | **Security/quality check:** Tour → *Security center and self-test* (IT) | Controls with ✓ / ! / i. In the demo some are honestly *not configured* or *development* (development application key, no virus scanner, no SSO, backups unencrypted or not yet reported). Press **Run security self-test**; open **Security evidence report** → *Not covered by this report* | "IT sees the recorded status of each control, including what is missing. The self-test exercises some protections on this installation; it is a self-check, not a penetration test. The report is a self-assessment and says what it does not cover: the identity provider's MFA, a restore test on university servers, an independent security review." |
| **4:15** | Tour → *Go-live: ready to connect* | Every connection **Demo data**, with the exact next step and templates | "Connecting YU means an approved Edugate export or API, LMS read-only access and IT sign-off. We do not have an Edugate adapter and did not guess one: there is a written contract, and a dry-run tool that previews an export's row counts and rejected rows without writing anything." |
| **4:40** | — | (stay on the page) | **Closing:** "A working, tested course-file workflow in English and Arabic: 10 automated test suites plus a Docker, HTTPS and backup-restore check run on every change. The next step is a controlled pilot with one department once IT provides access; the open prerequisites are listed in `docs/READINESS.md`." |

**If time is short:** skip 2:00 (reflection) and 4:15 (Go-live). **If there is extra time:** the
*Sign in the real way* tour step (robot check and e-mailed two-step code) and the Arabic interface
(**العربية**, right to left, also on a phone-sized window).

---

## What not to claim

| Do not say | Say instead |
|---|---|
| "Connected to / works with YU's systems" | "Connectors are tested against stand-in servers; connecting YU needs IT's approved access." |
| "Production-ready" / "Not a prototype" | "Docker-deployable demo; candidate for a controlled pilot once the prerequisites are met." |
| "Virus-scanned", "encrypted off-site backups" | "Scanning and encrypted second copies are available when configured; the Security center shows whether they are." |
| "SAQF attacks itself" / "proves it is secure" | "A self-test of some protections; not a penetration test." |
| "Tamper-proof audit log" | "Tamper-evident: edits and deletions are detected when the chain is verified." |
| "Two-step verification for everyone" (with SSO) | "For password sign-in. With university SSO, MFA is the identity provider's policy, which IT confirms." |
| "NCAAA-compliant" / "approved report" | "Supports NCAAA-oriented workflows; nothing in SAQF approves a report." |

---

## Likely questions and short answers

| Question | Answer |
|---|---|
| *Is this AI?* | No. The checks are 35 plain, explainable rules. Mapping suggestions are classic text similarity and are applied only when a professor accepts them. No AI service is called. |
| *How does it connect to YU's systems?* | Through an approved Edugate export or API and LMS read-only access, under the contract in `docs/INTEGRATIONS.md`. Moodle and Blackboard connectors exist; an Edugate adapter would be written once its API is documented. Nothing is connected today. |
| *Student privacy?* | Student identifiers are replaced by keyed pseudonyms while grades are read, on every path; digit-only identifiers are refused at import; no student names are kept. The key must come from the university's vault in production. |
| *What if someone edits the database directly?* | Triggers refuse edits through SAQF's account, and the hash chain shows any edit or deletion when verified (nightly). A database administrator could still change rows, which is why the log is called tamper-evident, not tamper-proof. |
| *Is it NCAAA-compliant?* | It supports NCAAA-oriented workflows and exports NCAAA-layout documents. It is not a certification, and SAQF approves nothing. YU's Deanship of Quality confirms the methodology and the course file checklist. |
| *Is the Arabic machine-translated?* | No online service is used. The interface wording is a fixed dictionary written for SAQF (the new 2.5 screens should still be reviewed by the Deanship); course names come from the catalogue; course content is typed in Arabic by people. Some detailed status sentences are still English. |
| *What is left before a pilot?* | Identity provider registration with its MFA policy confirmed, the approved integration contract, key storage, HTTPS, a recorded backup/restore test, a malware-scanning decision, retention/accessibility policy, an independent security review, and a department pilot (`docs/READINESS.md`). |

---

## Reset and recovery

- **Numbers look different from the script** → `docker compose exec app php bin/install.php --demo --fresh` (demo database only; refused in production), then reopen the tour.
- **Wrong person signed in** → **Switch role** in the yellow demo bar.
- **Lost** → the **Guided tour** link is in the yellow demo bar.
- **The app container will not start** → `docker compose logs app | tail -30`; then `docker compose down` and `docker compose up -d --build`. As a last resort for the **demo only**: `docker compose down -v` (deletes the demo database and volumes) and start again.

## Fallback if the live demo cannot run

1. **No Docker on the presenting laptop:** local PHP 8.3 and MySQL 8: `php bin/install.php --demo` with `SAQF_DB_*` set, then `php -S 127.0.0.1:8080 -t public` (README, *Quick start: local PHP*).
2. **Nothing runs:** present from the screenshots in [`docs/screenshots/`](screenshots/) (26–27 show the course file closeout in English and in Arabic on a phone-sized screen) and the CI run on GitHub (test suites and the Docker/HTTPS/backup-restore job), and say plainly that the live demo did not start.
