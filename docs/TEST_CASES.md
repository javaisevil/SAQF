# SAQF test cases: every feature, in plain steps

Each row is one thing SAQF does, how to see it yourself in the demo, and what you should see. The last column names the automated test that checks the same thing on every change.

**Start:** `docker compose up -d --build`, open http://localhost:8080. To start again from a clean story at any time: `docker compose exec app php bin/install.php --demo --fresh`.
**Signing in:** use the one-click buttons on the sign-in page, or **Switch role** in the yellow demo bar. Every demo password is `Yamamah@2026`.
**Run every automated test:** `docker compose exec app sh bin/test_all.sh` → *Total: 539 checks passed, 0 failed.*

Test files: **A** = `tests/automation_test.php`, **P** = `tests/production_test.php`, **H** = `tests/http_smoke.php`, **S** = `tests/sso_test.php`, **F** = `tests/features_test.php`, **W** = `tests/wording_test.php`, **R** = `tests/readiness_test.php`, **G** = `tests/signin_test.php`.

---

## 1. Faculty (Dr. Omar)

| # | Test case | How to show it | You should see | Test |
|---|---|---|---|---|
| 1.1 | Courses arrive by themselves | Sign in as **Faculty Member** | *My courses this term* ("Added for you from the university timetable"): SWE 401, SWE 412, CIS 491; nobody created them | A §3 |
| 1.1b | What SAQF did, in plain words | Same page, *Done for you this term* | Sentences such as "102 checks run on your courses" instead of a grid of codes | W §2 |
| 1.2 | Errors are found while working | Open **SWE 412 → Outcomes & assessment** | Four problems: an outcome that cannot be measured, CLO4 not linked to the program, CLO4 not assessed, weights 105% | A §2 |
| 1.3 | Errors clear themselves | Change *Lab assignments* 25 → 20, **Save weights**; reword CLO4 to start with "Analyze", link it to a program outcome and to the final exam | Problems disappear one by one until the *Checks* card says "Everything checks out" | A §2 |
| 1.4 | A red record cannot be sent | Before fixing, try **Submit changes** | Refused: "Fix the items marked Must fix first" | A §2 |
| 1.5 | Reviewers see only the change | After fixing, look at the top card | "Reviewers will see only these 2 changes, not the whole specification" | A §2 |
| 1.6 | Results calculated automatically | **SWE 401 → Results & achievement** | CLO1 "72% · goal 70%", CLO2 "72% so far · goal 70%", whole numbers, from LMS grades marked ✓ Verified | A §4, W §1 |
| 1.6b | Course facts in plain words | **SWE 401 → Overview**, *About this course* | "3 credit hours (about 45 hours of class time)", *Take first*: SWE 301, *Part of*: Software Engineering · required course · semester 8 | W §2 |
| 1.7 | Sections compared | Same page, *Achievement by section* | Section 02 is 16 points below section 01; a problem is raised for the coordinator | F §1 |
| 1.8 | Improvement checked next term | **SWE 401 → Improvement** | Fall 2025: 53% → Spring 2026: 63%, "Results went up afterwards" under *Did it help?* | A §6, W §1 |
| 1.9 | Evidence requested automatically | **SWE 401 → Evidence** | Banner: "Please add the papers for: Midterm exam" | F §3 |
| 1.10 | Evidence upload clears the request | Upload `docs/demo/SWE401-midterm-exam-paper.pdf` (For: Midterm exam) | File listed with a ✓ Unchanged mark; the banner disappears | F §3 |
| 1.11 | Unsafe files refused | Upload a renamed `.exe`, a fake PDF or a Word file with macros | Refused with a clear message | F §3 |
| 1.12 | NCAAA Word report | **SWE 401 → Course report → Word (English)** | A Word file with sections A–F, results by section and the evidence list | F §4 |
| 1.13 | Deadlines in my calendar | **My courses → Add my deadlines to my calendar** | A calendar file (Outlook, Google, Apple) with due dates and the term's end and grades-due dates; only the person's own | R |
| 1.13 | Arabic Word report | Same tab → **Word (Arabic)** | The same report right to left, with Arabic headings **and** Arabic course title, outcomes, assessments and names | F §4, W §4 |

## 2. Section instructor (Dr. Sara)

| # | Test case | How to show it | You should see | Test |
|---|---|---|---|---|
| 2.1 | Section instructor works in the course | Switch to **Dr. Sara**, open **SWE 401** | The course opens (section 02), with the evidence upload available | F §1, H |
| 2.2 | Only the coordinator changes the specification | Try to edit an outcome of SWE 401 | Refused: the coordinator (Dr. Omar) owns the shared specification | H |
| 2.3 | Courses of others stay closed | Type `workspace.php?id=` of SWE 412 in the address bar | "Access denied", and the attempt is logged | H |

## 3. Head of Department

| # | Test case | How to show it | You should see | Test |
|---|---|---|---|---|
| 3.1 | Only exceptions | Sign in as **Head of Department** | *What needs you*: the repeated CLO3 gap (SWE 401) and SWE SO2 below its goal; healthy courses folded away | A §6, H |
| 3.1b | Program outcomes at a glance | Same page, right column | Chips such as "SO2 69%" with a colour legend (met the 70% goal / below the goal) | W §1 |
| 3.2 | Approve by difference | Open **Approvals** | Only the changed items of a specification | A §2 |
| 3.3 | Program outcome change shows its impact first | **Programs → SWE → Program outcomes**, *Change* SO2 | "If you change it: 5 courses are affected", before saving | A §7 |
| 3.4 | Correct study plans only | **Study plans**, choose the MBA | Only master's courses appear | A §1 |
| 3.5 | Import existing specifications | **Import specifications**, download the template, upload it back | Valid courses imported; a course with an error is reported and nothing of it is written | F §2 |

## 4. Quality Assurance

| # | Test case | How to show it | You should see | Test |
|---|---|---|---|---|
| 4.1 | Problems to sort out | Sign in as **Quality Assurance** | Problems in university records (e.g. MIS 316 credit hours), exceptions to policy, risks; every figure explained in a sentence | A §8, H, W §1 |
| 4.2 | Exception needs a reason | Open the CIS 491 exception, press **Allow the exception** without a reason | Refused; with a reason it is allowed and the problem is marked *Rule set aside* (not deleted) | A §8 |
| 4.3 | Policies are configuration | **Quality policies**, change the CLO achievement method | Achievement recalculates; the change is in the audit log | A §9 |
| 4.4 | Arabic wording | **Arabic wording** | What still shows in English, a box for the Arabic of each, and a spreadsheet to download, fill in and upload | W §4 |

## 5. Dean and University Leadership

| # | Test case | How to show it | You should see | Test |
|---|---|---|---|---|
| 5.1 | College drill-down | Sign in as **College Dean** | College → department → program → course | H |
| 5.2 | Whole university | Sign in as **University Leadership** | Programs that need help, problems that keep coming back, progress term by term | H |

## 6. Automation (IT administrator, University systems tab)

| # | Test case | How to show it | You should see | Test |
|---|---|---|---|---|
| 6.1 | LMS publishes grades | **University systems → Publish now** | SWE 302 CLO3 "60% so far" and an early warning for Dr. Sara | A §4 |
| 6.2 | SIS assigns a new instructor | **Publish assignment** | A new faculty account and the SWE 413 workspace, created by themselves | A §3 |
| 6.3 | New semester starts by itself | **Activate term** (Spring 2027) | Fall 2026 closed and its reports frozen; every course rolled forward | A §10, P §7 |
| 6.4 | Real connectors | **Test connections** | SIS: OK, LMS: OK (Moodle, Blackboard and SIS API are tested against stand-ins) | P §3–5 |
| 6.5 | One broken system does not stop the rest | (automated) | Scheduler keeps going when the LMS is down | P §6 |

## 7. Security

| # | Test case | How to show it | You should see | Test |
|---|---|---|---|---|
| 7.1 | Robot check | Open the sign-in page and click into the form | The box ticks itself: "Checking that you are human…", then "Verified: you are human" | G §1–2 |
| 7.1b | Two-step code for everyone | On the sign-in page press **Use this account → Faculty Member**, then **Sign in** | The code screen: "We sent a 6-digit code to f•••@…"; the demo mailbox shows the e-mail; the code signs in | G §3 |
| 7.1c | Trusted browser | Tick **Don't ask again on this browser**; sign out and in again | The password is enough on this browser; another browser still needs a code | G §3 |
| 7.1d | Administrators use an app | Sign in as `it.admin` | Asked for the authenticator code (shown under the form in demo mode only); no e-mail option, no "trust this browser" | F §5, S, G §4 |
| 7.2 | Wrong code refused | Enter `000000` | "That code is not right"; after 5 wrong codes the sign-in starts again | F §5, G §3 |
| 7.3 | Anyone can add an authenticator app | **Account & security → Add an authenticator app** | A QR code to scan, then 10 one-time recovery codes | F §5 |
| 7.4 | Strong passwords | **Account & security → Change password**, try `Password2026` | Refused (common word); a passphrase is accepted | F §5 |
| 7.5 | Sign out other devices | **Account & security → Sign out all other sessions** | The other browser is signed out | F §5 |
| 7.6 | Account lockout | Enter a wrong password 5 times | The account locks for 15 minutes | H |
| 7.7 | Re-confirm before sensitive changes | As administrator after 15 minutes, open **Users & access** | Asked for password and code before changing accounts | F §5 |
| 7.8 | Security center | **System administration → Security center** | 22 controls with their live status and how to fix the rest | F §5 |
| 7.9 | Tamper-evident activity log | **System health → Check the activity log** | "All … audit entries verified; the hash chain is intact" | A §11, H |
| 7.10 | Virus scanning | (automated, with a stand-in ClamAV) | The EICAR test virus is refused and IT is alerted; if the scanner is down, uploads pause | F §3 |
| 7.11 | Demo shortcuts do not exist in production | (automated, production mode) | One-click sign-in answers *Not found*; no demo hints | F §8 |
| 7.12 | Single sign-on attacks refused | (automated) | Forged, expired, replayed and wrong-audience tokens refused | P §11, S |
| 7.13 | Live security self-test | **Security center → Run security self-test** | "8 of 8 passed": weak passwords refused, forged request refused, audit log edit refused by the database, encryption tamper-proof, student identities one-way | R |
| 7.14 | Security evidence report | **Security center → Security evidence report** | Printable page: controls, self-test, audit chain, how the system stays secure, fingerprint; generating it is entered in the activity log | R |
| 7.15 | Access review | **System administration → Access review** | Three accounts due; tick and confirm; own row says another administrator reviews you; a role change makes the review due again; removing access disables and signs the person out | R |
| 7.16 | Nightly proof and alerts | (automated) | The self-test runs nightly; a failure or an overdue review raises an IT alert (visible under IT alerts) | — |
| 7.17 | Security checkup | **Account & security** | "Security checkup": two-step verification, recovery codes, e-mail, password age, failed attempts, each with a ✓ or ! | G §5 |
| 7.18 | "This wasn't me" | **Account & security → Recent sign-in activity → This wasn't me** | Every other session signed out, every trusted browser forgotten, IT alerted | G §5 |
| 7.19 | Session warning | Leave a page open for 28 minutes | "You will be signed out soon" with a countdown; **Stay signed in** keeps the session | G §6 |
| 7.20 | Password comfort | Any password field | **Show** reveals the password, a Caps Lock warning, and a strength meter with tips on new passwords | G §6 (browser) |
| 7.21 | Help and shortcuts | Press **?** (or the ? button at the top) | Keyboard shortcuts: / search, g h home, g n notifications, g a account; the Help page answers each role's common questions | G §6 |

## 8. IT operations

| # | Test case | How to show it | You should see | Test |
|---|---|---|---|---|
| 8.1 | Health at a glance | **System health** | Database, scheduler, audit chain, backups, evidence store, IT alerts | H |
| 8.2 | IT alerts | **IT alerts** tab | The LMS outage of 21 Sep: raised after two failed runs, cleared automatically | F §6 |
| 8.3 | Alerts reach Teams/Slack | Set `SAQF_ALERT_WEBHOOK` | Each new alert posts a message | F §6 |
| 8.4 | Backups monitored | (automated) | A failed or old backup raises a critical alert; the next good one clears it | F §6 |
| 8.5 | Encrypted backup and restore | `docker compose exec backup sh /usr/local/bin/saqf-backup` then `docker/restore.sh` | Encrypted files in `./backups`, restored database with an intact audit chain | CI (Docker job) |
| 8.6 | HTTPS | `docker compose --profile https up -d`, open https://localhost | Secure cookie, HSTS, HTTP redirected to HTTPS | CI (Docker job) |
| 8.7 | Maintenance mode | **System health → Maintenance mode → Turn on** | Everyone except IT sees a maintenance page | H |
| 8.8 | Go-live status | **System administration → Go-live** | Each system shows Demo data or Live, what is missing and the next step; no secret values shown | R |
| 8.9 | Catalogue check | **Go-live → Check the catalogue in use** | "Passed · 14 programs · 366 courses"; a note that four programs have no published outcomes | R |
| 8.10 | A faulty export is refused | (automated) Registrar export with an unknown department and text credit hours | Listed in plain words; the sync refuses it as a whole, nothing changes, the run is recorded as failed | R |
| 8.11 | Templates for IT | **Go-live → Templates for IT** | Catalogue ZIP, `terms.csv`, `assignments.csv`, gradebook example and a settings file download; the CSVs read back through the real connectors as the same terms and assignments | R |

## 9. Arabic interface

| # | Test case | How to show it | You should see | Test |
|---|---|---|---|---|
| 9.1 | Switch language | Press **العربية** at the top | The whole interface in Arabic, right to left; **English** switches back | F §7 |
| 9.2 | Remembered | Sign out and in again on another browser | Still in Arabic (saved on the account) | F §7 |
| 9.3 | Findings in Arabic | **SWE 401 → Overview** in Arabic | "SWE 401 CLO1: الشعبة 02 أقل بـ 16 نقطة من الشعبة 01" | F §7 |
| 9.4 | Course content in Arabic | Same page | The course title (ضمان جودة البرمجيات), learning outcomes, description, assessments and names in Arabic | W §4 |
| 9.5 | Faculty type the Arabic | **SWE 412 → Outcomes & assessment**, edit an outcome, fill *Arabic wording* | The Arabic interface and the Arabic Word file show it | W §4 |
| 9.6 | Arabic search | Type ضمان جودة in the search box | SWE 401 is found | W §4 |
| 9.7 | Names from the Registrar | (automated) | 366 course titles, programs, departments, program outcomes and plan groups arrive in Arabic with the catalogue; a person's correction is never overwritten | W §3 |

---

## Last full run

```
SAQF test suites (PHP 8.3, MySQL 8.0)
  ok   Syntax check of every PHP file
  ok   Automation scenarios (the quality loop end to end)           58 passed  0 failed
  ok   Connectors, semester cycle, accounts, e-mail, SSO tokens     96 passed  0 failed
  ok   Every page for every role, authorization, CSRF, lockout     126 passed  0 failed
  ok   University sign-in (OpenID Connect) end to end               29 passed  0 failed
  ok   Sections, import, evidence, Word, 2-step, alerts, Arabic     91 passed  0 failed
  ok   Plain wording, whole numbers, Arabic course content          31 passed  0 failed
  ok   Go-live readiness, data pack, access review, security proof   58 passed  0 failed
  ok   Robot check, two-step codes, trusted browsers, session, help   50 passed  0 failed
  ok   Database migrations are idempotent (upgrade path)
Total: 539 checks passed, 0 failed.
```

The same suites pass inside the Docker image (Apache, PHP 8.3, MySQL 8.0), and GitHub runs them on every push, together with HTTPS, encrypted-backup and restore checks of the Docker stack.
