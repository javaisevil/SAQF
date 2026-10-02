# SAQF test cases: every feature, in plain steps

Each row is one thing SAQF does, how to see it yourself in the demo, and what you should see. The last column names the automated test that checks the same thing on every change.

**Start:** `docker compose up -d --build`, open http://localhost:8080. To start again from a clean story at any time: `docker compose exec app php bin/install.php --demo --fresh`.
**Signing in:** use the one-click buttons on the sign-in page, or **Switch role** in the yellow demo bar. Every demo password is `Yamamah@2026`.
**Run every automated test:** `docker compose exec app sh bin/test_all.sh` → *Total: 392 checks passed, 0 failed.*

Test files: **A** = `tests/automation_test.php`, **P** = `tests/production_test.php`, **H** = `tests/http_smoke.php`, **S** = `tests/sso_test.php`, **F** = `tests/features_test.php`.

---

## 1. Faculty (Dr. Omar)

| # | Test case | How to show it | You should see | Test |
|---|---|---|---|---|
| 1.1 | Courses arrive by themselves | Sign in as **Faculty Member** | *My courses this term*: SWE 401, SWE 412, CIS 491, created from the SIS; nobody created them | A §3 |
| 1.2 | Errors are found while working | Open **SWE 412 → Outcomes & assessment** | Four issues: vague verb, CLO4 unmapped, CLO4 unassessed, weights 105% | A §2 |
| 1.3 | Errors clear themselves | Change *Lab assignments* 25 → 20, **Save weights**; reword CLO4 to start with "Analyze", map it, link it to the final exam | Issues disappear one by one; course becomes *Ready* | A §2 |
| 1.4 | A red record cannot be sent | Before fixing, try **Submit changes** | Refused: "Fix the items marked Must fix first" | A §2 |
| 1.5 | Reviewers see only the change | After fixing, look at the top card | "Reviewers will see only these 2 change(s)" | A §2 |
| 1.6 | Results calculated automatically | **SWE 401 → Results & achievement** | CLO1 72.4%, CLO2 72.4% (provisional), from LMS grades | A §4 |
| 1.7 | Sections compared | Same page, *Achievement by section* | Section 02 is 15.7 points below section 01; an issue is raised for the coordinator | F §1 |
| 1.8 | Improvement checked next term | **SWE 401 → Improvement** | 53.3% → 63.3%, "Performance improved following the intervention" | A §6 |
| 1.9 | Evidence requested automatically | **SWE 401 → Evidence** | Banner: "SAQF requests evidence for: Midterm exam" | F §3 |
| 1.10 | Evidence upload clears the request | Upload `docs/demo/SWE401-midterm-exam-paper.pdf` (Assessment: Midterm exam) | File listed with its fingerprint; the banner disappears | F §3 |
| 1.11 | Unsafe files refused | Upload a renamed `.exe`, a fake PDF or a Word file with macros | Refused with a clear message | F §3 |
| 1.12 | NCAAA Word report | **SWE 401 → Course report → Download Word (NCAAA layout)** | A Word file with sections A–F, results by section and the evidence list | F §4 |
| 1.13 | Arabic Word report | Same tab → **بالعربية** | The same report with Arabic headings, right to left | F §4 |

## 2. Section instructor (Dr. Sara)

| # | Test case | How to show it | You should see | Test |
|---|---|---|---|---|
| 2.1 | Section instructor works in the course | Switch to **Dr. Sara**, open **SWE 401** | The course opens (section 02), with the evidence upload available | F §1, H |
| 2.2 | Only the coordinator changes the specification | Try to edit an outcome of SWE 401 | Refused: the coordinator (Dr. Omar) owns the shared specification | H |
| 2.3 | Courses of others stay closed | Type `workspace.php?id=` of SWE 412 in the address bar | "Access denied", and the attempt is logged | H |

## 3. Head of Department

| # | Test case | How to show it | You should see | Test |
|---|---|---|---|---|
| 3.1 | Only exceptions | Sign in as **Head of Department** | Recurring CLO3 gap (SWE 401) and SWE SO2 below target; healthy courses hidden | A §6, H |
| 3.2 | Approve by difference | Open **Approvals** | Only the changed items of a specification | A §2 |
| 3.3 | PLO change shows its impact first | **Programs → SWE → PLOs**, edit SO2 | Number of courses, mappings and assessments affected, before saving | A §7 |
| 3.4 | Correct study plans only | **Study plans**, choose the MBA | Only master's courses appear | A §1 |
| 3.5 | Import existing specifications | **Import specifications**, download the template, upload it back | Valid courses imported; a course with an error is reported and nothing of it is written | F §2 |

## 4. Quality Assurance

| # | Test case | How to show it | You should see | Test |
|---|---|---|---|---|
| 4.1 | Exception Center | Sign in as **Quality Assurance** | Data conflicts (e.g. MIS 316 credit hours), policy exceptions, risks | A §8, H |
| 4.2 | Exception needs a reason | Open the CIS 491 exception, press **Approve** without a note | Refused; with a note it is approved and the issue is marked *overridden* (not deleted) | A §8 |
| 4.3 | Policies are configuration | **Quality policies**, change the CLO achievement method | Achievement recalculates; the change is in the audit log | A §9 |

## 5. Dean and University Leadership

| # | Test case | How to show it | You should see | Test |
|---|---|---|---|---|
| 5.1 | College drill-down | Sign in as **College Dean** | College → department → program → course | H |
| 5.2 | Whole institution | Sign in as **University Leadership** | Programs needing intervention, recurring issues, quality cycles | H |

## 6. Automation (IT administrator, Integrations tab)

| # | Test case | How to show it | You should see | Test |
|---|---|---|---|---|
| 6.1 | LMS publishes grades | **Integrations → Publish now** | SWE 302 CLO3 60% (provisional) and an early warning for Dr. Sara | A §4 |
| 6.2 | SIS assigns a new instructor | **Publish assignment** | A new faculty account and the SWE 413 workspace, created by themselves | A §3 |
| 6.3 | New semester starts by itself | **Activate term** (Spring 2027) | Fall 2026 closed and its reports frozen; every course rolled forward | A §10, P §7 |
| 6.4 | Real connectors | **Test connections** | SIS: OK, LMS: OK (Moodle, Blackboard and SIS API are tested against stand-ins) | P §3–5 |
| 6.5 | One broken system does not stop the rest | (automated) | Scheduler keeps going when the LMS is down | P §6 |

## 7. Security

| # | Test case | How to show it | You should see | Test |
|---|---|---|---|---|
| 7.1 | Two-step verification for administrators | On the sign-in page type `it.admin` / `Yamamah@2026` | SAQF asks for the authenticator code (shown under the form in demo mode only) | F §5, S |
| 7.2 | Wrong code refused | Enter `000000` | "That code is not right" | F §5 |
| 7.3 | Anyone can turn on two-step verification | **Account & security → Set up two-step verification** | A QR code to scan, then 10 one-time recovery codes | F §5 |
| 7.4 | Strong passwords | **Account & security → Change password**, try `Password2026` | Refused (common word); a passphrase is accepted | F §5 |
| 7.5 | Sign out other devices | **Account & security → Sign out all other sessions** | The other browser is signed out | F §5 |
| 7.6 | Account lockout | Enter a wrong password 5 times | The account locks for 15 minutes | H |
| 7.7 | Re-confirm before sensitive changes | As administrator after 15 minutes, open **Users & access** | Asked for password and code before changing accounts | F §5 |
| 7.8 | Security center | **System administration → Security center** | 19 controls with their live status and how to fix the rest | F §5 |
| 7.9 | Tamper-evident audit log | **Audit log → Verify audit chain** | "All entries verified; the hash chain is intact" | A §11, H |
| 7.10 | Virus scanning | (automated, with a stand-in ClamAV) | The EICAR test virus is refused and IT is alerted; if the scanner is down, uploads pause | F §3 |
| 7.11 | Demo shortcuts do not exist in production | (automated, production mode) | One-click sign-in answers *Not found*; no demo hints | F §8 |
| 7.12 | Single sign-on attacks refused | (automated) | Forged, expired, replayed and wrong-audience tokens refused | P §11, S |

## 8. IT operations

| # | Test case | How to show it | You should see | Test |
|---|---|---|---|---|
| 8.1 | Health at a glance | **System health** | Database, scheduler, audit chain, backups, evidence store, IT alerts | H |
| 8.2 | IT alerts | **IT alerts** tab | The LMS outage of 21 Sep: raised after two failed runs, cleared automatically | F §6 |
| 8.3 | Alerts reach Teams/Slack | Set `SAQF_ALERT_WEBHOOK` | Each new alert posts a message | F §6 |
| 8.4 | Backups monitored | (automated) | A failed or old backup raises a critical alert; the next good one clears it | F §6 |
| 8.5 | Encrypted backup and restore | `docker compose exec backup sh /usr/local/bin/saqf-backup` then `docker/restore.sh` | Encrypted files in `./backups`, restored database with an intact audit chain | CI (Docker job) |
| 8.6 | HTTPS | `docker compose --profile https up -d`, open https://localhost | Secure cookie, HSTS, HTTP redirected to HTTPS | CI (Docker job) |
| 8.7 | Maintenance mode | **System health → Maintenance mode → Turn on** | Everyone except administrators sees a maintenance page | H |

## 9. Arabic interface

| # | Test case | How to show it | You should see | Test |
|---|---|---|---|---|
| 9.1 | Switch language | Press **العربية** at the top | The whole interface in Arabic, right to left; **English** switches back | F §7 |
| 9.2 | Remembered | Sign out and in again on another browser | Still in Arabic (saved on the account) | F §7 |
| 9.3 | Findings in Arabic | **SWE 401 → Overview** in Arabic | "SWE 401 CLO1: الشعبة 02 أقل بـ 15.7 نقطة من الشعبة 01" | F §7 |

---

## Last full run

```
SAQF test suites (PHP 8.3, MySQL 8.0)
  ok   Syntax check of every PHP file
  ok   Automation scenarios (the quality loop end to end)           58 passed  0 failed
  ok   Connectors, semester cycle, accounts, e-mail, SSO tokens     96 passed  0 failed
  ok   Every page for every role, authorization, CSRF, lockout     118 passed  0 failed
  ok   University sign-in (OpenID Connect) end to end               29 passed  0 failed
  ok   Sections, import, evidence, Word, 2-step, alerts, Arabic     91 passed  0 failed
  ok   Database migrations are idempotent (upgrade path)
Total: 392 checks passed, 0 failed.
```

The same suites pass inside the Docker image (Apache, PHP 8.3, MySQL 8.0), and GitHub runs them on every push, together with HTTPS, encrypted-backup and restore checks of the Docker stack.
