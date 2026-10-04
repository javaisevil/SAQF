# SAQF in 5 minutes: the judges' demo

*One page to rehearse with. Every step is one click from the **Guided tour** (`/tour.php`): each button signs in as the right person and opens the right page, so there is no typing of usernames or passwords during the 5 minutes.*

---

## Before the judges arrive (2 minutes, once)

1. Start SAQF: `docker compose up -d --build`, then open **http://localhost:8080** (the sign-in page appears).
2. Reset the story so every number below is exactly as written (about 10 seconds):
   ```bash
   docker compose exec app php bin/install.php --demo --fresh
   ```
3. Open two browser tabs: **http://localhost:8080/tour.php** (the guided tour) and the sign-in page.
4. Have the sample paper ready for the live upload: [`docs/demo/SWE401-midterm-exam-paper.pdf`](demo/SWE401-midterm-exam-paper.pdf).
5. Browser zoom at 90% on a laptop screen shows each page without scrolling.

No internet connection is needed: SAQF and the simulated university systems all run on the laptop.

---

## The script (5:00)

| Time | Click (on the tour page) | Show | Say (one or two sentences) |
|---|---|---|---|
| **0:00** | *Sign in the real way* (sign-in page) | Press **Use this account → Faculty Member**: the form fills in and the **robot check** ticks itself ("Verified: you are human"). Press **Sign in**: the **two-step code** screen appears; the **demo mailbox** shows the e-mail Dr. Omar receives. Type the code, tick **Don't ask again on this browser**, sign in: "Welcome back, your last sign-in was…" | "This is the sign-in of a real public system: a robot check that stops password-guessing scripts without tracking anyone, then a one-time code by e-mail, nothing to install. Administrators must use an authenticator app. Every sign-in is recorded and the person sees it. Arabic and English throughout." |
| **0:30** | *Open SWE 412 as Dr. Omar* | Four problems already found, each in a plain sentence: an outcome that cannot be measured, one not linked to the program, one not assessed, weights = 105%. Change **Lab assignments** from 25 to **20**, press **Save weights** | "Nobody typed this course: it came from the university timetable and carried over last term's specification. SAQF checks 35 rules while the professor works. Watch: fix the weight and that problem clears itself — an incomplete record can never be sent to a reviewer." |
| **1:15** | *SWE 401 results by section* | Every result is a whole number with its goal ("72% · goal 70%"). The course meets its goal, but **section 02 is 16 points behind section 01**, flagged automatically. Then the **Improvement** tab: 53% → 63%, "Results went up afterwards" | "Grades arrive from the LMS and SAQF works out, per outcome and per section, how many students met each outcome. It spotted the gap between two sections on its own, and it checks whether last year's improvement actually helped." |
| **1:45** | *Evidence and Word report* | Blue banner: "Please add the papers for: Midterm exam". Upload `SWE401-midterm-exam-paper.pdf` → the request disappears and the file shows "✓ Unchanged". Then **Course report** tab → **Word (English)** or **Word (Arabic)** | "Accreditation reviewers ask for the exam behind the numbers. SAQF asks for it at the right moment, scans it, stores it securely, and writes the NCAAA-layout course report itself — in English or fully in Arabic, course content included." |
| **2:15** | *Open as the Head of Department* | *What needs you*: the repeated CLO3 gap and SWE SO2 below its goal. Program outcome chips read "SO2 69%" with a colour legend. Healthy courses are folded away | "The Head of Department sees only what needs a decision. Approvals show just the change, never the whole form again." |
| **2:45** | *CIS 491 exception (Quality)* | **Allow the exception** or **Turn down** for the 70% capstone report — a reason is required | "Quality runs by exception. Every exception needs a reason and lands in a tamper-evident activity log." |
| **3:05** | *Import specifications*, then *Arabic wording* | The import page and its CSV template; the Arabic wording page with what is still in English | "A university does not start from zero: existing approved specifications come in with one file, checked before anything is written. Course names arrive in Arabic from the Registrar; anything still in English is listed here to fill in, or as a spreadsheet." |
| **3:30** | *University systems simulator* | Press **Publish now** (LMS releases SWE 302 midterm grades) → early warning raised. Press **Publish assignment** (SIS assigns SWE 413 to a brand-new instructor) → account and workspace created | "This is the automation live. The LMS publishes grades: SAQF recalculates and warns while there is still time to act. The SIS assigns a new instructor: the account and the workspace appear by themselves." |
| **4:00** | *Security center* | 22 security controls with live status. Press **Run security self-test**: each protection is tried for real (weak passwords, forged requests, an attempt to edit the audit log, encryption, student identities). Open **Security evidence report** (printable, with a fingerprint). Then **Access review**: three people are due; tick them and confirm, noting that nobody can review their own access | "IT sees every control and its real status, and can *prove* it: SAQF attacks itself every night and raises an alert if any protection stops working. The report is what we hand the university's security committee. Access is re-confirmed by a second administrator every 90 days." |
| **4:20** | *Go-live* (admin tab) | Every connection marked **Demo data** with its exact next step; **Check the catalogue** passes (14 programs, 366 courses); the **templates for IT** download | "This is how it connects to Al Yamamah's real systems: IT gets the access, replaces these files with the Registrar's and SIS's own export (same layout) and fills in a settings file. A faulty export is refused as a whole, so the live data can never be half-loaded." |
| **4:35** | *Open as the Vice President* | Programs that need help, problems that keep coming back, progress term by term across colleges | "Leadership sees the whole university in one screen, and can click through to any course." |
| **4:45** | — | (stay on the page) | **Closing:** "Not a prototype: 539 automated checks run on every change, it ships as a Docker stack with HTTPS, encrypted and verified backups with a tested restore, single sign-on, two-step verification and Arabic. Ready for a pilot department next semester." |

**If time is short**, skip 3:05 (import) and 4:35 (Vice President); for the 0:00 step, the tour's buttons sign in instantly instead (demo shortcut). As Dr. Omar, **Add my deadlines to my calendar** (My courses) downloads a calendar file for Outlook, Google or Apple Calendar, and pressing **/** anywhere jumps to search. **If there is extra time**, show the administrator's real sign-in (below).

---

## What to highlight: "fully ready, not a prototype"

Point at these while talking; each takes under 10 seconds to show.

| Proof | Where to show it |
|---|---|
| Works with the university's real systems (SIS, Moodle/Blackboard, Microsoft 365 sign-in, e-mail) — configuration only, no code changes | *System administration → University systems* (connector list and **Test connections**) |
| Runs itself: terms start on their SIS start date, grades import, rules re-check, e-mails go out | *System health*: scheduler heartbeat "last run just now" |
| Real sign-in security | Robot check (self-hosted proof of work + hidden trap, harder after failures); two-step code for everyone (e-mail or app; app only for administrators); trusted browsers for 30 days; last-sign-in notice; *Account & security*: security checkup, sign-in history, "This wasn't me"; session-expiry warning with *Stay signed in* |
| Real security, not a login form | *Security center*: 22 controls and a live self-test; printable security evidence report; periodic access review; two-step verification for administrators; strong-password rules; sessions you can end remotely (*Account & security*) |
| Ready to connect, not a prototype | *Go-live*: demo or live per system, exact next steps, templates in the real layouts, catalogue checked before use |
| Tamper-evident records | *System health → Check the activity log*: "All … audit entries verified; the hash chain is intact" |
| Disaster-ready | *System health → Backups*: encrypted, verified, copied off-site; the restore is tested in CI |
| Arabic interface, including course content | **العربية** button at the top of every page: course titles, learning outcomes, assessments and names appear in Arabic too, and so does the Arabic Word report |
| Plain language | Whole-number results with their goal, "What needs you" lists, course facts in sentences, no codes to decode |
| Tested | 539 automated checks in 8 suites + Docker tests on every change ([`docs/TEST_CASES.md`](TEST_CASES.md)) |

---

## Optional: the administrator's real sign-in (30 seconds)

On the sign-in page type `it.admin` / `Yamamah@2026` → SAQF asks for the **authenticator code**. In demo mode the code the phone would show is printed under the form; type it in. (In production the code exists only on the person's phone.) This shows that a stolen password alone does not open the administrator account.

---

## Likely questions and short answers

| Question | Answer |
|---|---|
| *Is this AI?* | No. The checks are 35 plain, explainable rules. The mapping suggestions are classic text similarity and are only applied when a professor accepts them. |
| *How does it connect to YU's systems?* | Ready connectors: SIS (nightly CSV export or REST API), Moodle or Blackboard (web services), Microsoft 365 / any OpenID Connect sign-in, SMTP e-mail. YU IT provides access; nothing in the code changes. Guide: `docs/INTEGRATIONS.md`. |
| *What happens if the LMS is down?* | That step is retried on the next run; after two failed runs IT gets an alert (in SAQF, by e-mail and on Teams), and it clears itself when the LMS answers. |
| *Student privacy?* | Student IDs are replaced by keyed pseudonyms before storage; no student names are kept. Uploaded evidence should use pseudonymous samples (the page says so). |
| *What if someone edits the database directly?* | The audit log is append-only (database triggers) and hash-chained; the nightly check finds any edit and raises a critical alert. |
| *Multiple sections of one course?* | Supported: one coordinator owns the shared specification, section instructors contribute results and evidence, achievement is compared per section. |
| *Is it NCAAA-compliant?* | It supports NCAAA-oriented workflows and exports NCAAA-layout documents. It is not a certification; YU's Deanship of Quality confirms the methodology (configurable under *Quality policies*). |
| *Is the Arabic machine-translated?* | No, and nothing is sent to an online service. The interface wording is a fixed dictionary written for SAQF; course and program names arrive in Arabic with the Registrar's catalogue; course content (outcomes, assessments) is typed in Arabic by the instructor or by Quality on the *Arabic wording* page, one by one or as a spreadsheet. |
| *Cost and hosting?* | Plain PHP + MySQL, no licences, no paid or external services. One Docker command on a university server. |
| *What is left before go-live?* | University access (SIS export, LMS token, sign-in app registration, mailbox), the Deanship's approval of the policies, and a pilot with one department. |

---

## If something goes wrong

- **A page shows old data** → reset: `docker compose exec app php bin/install.php --demo --fresh`, then reopen the tour.
- **Wrong person signed in** → use **Switch role** in the yellow demo bar at the top of every page.
- **Lost** → the **Guided tour** link is in the yellow demo bar.
