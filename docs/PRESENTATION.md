# Presenting SAQF

For whoever presents SAQF to judges or to the university. The click-by-click demo is in
[JUDGES_DEMO.md](JUDGES_DEMO.md); this page is the story, the stack in one honest line per item, the boundaries
to keep, and the answers to the questions you will get.

## The one-sentence pitch

**SAQF turns the course file, the most repetitive quality task a faculty member has, into a guided, mostly
automatic process: SAQF fills in, calculates, checks, reminds and packages; people only read, decide and approve.**

## The problem (say it in faculty terms)

Every term each instructor rebuilds the same course file: specification, grades per learning outcome, exam papers
and marked samples, an interpretation of the results, improvement plans. It is assembled by hand at the deadline,
returned for missing pieces, and checked again by the Head of Department and the Quality unit. Most of that work
is copying and arithmetic, and the rest is chasing.

## What SAQF automates, and what it deliberately does not

| SAQF does it (automatic) | A person does it (by design) |
|---|---|
| creates the course workspace from the timetable and carries the approved specification forward | changes the specification; the Head of Department / Quality approves changes |
| imports grades (LMS, export folder or an upload it previews first), pseudonymises students, calculates CLO/PLO achievement per section | confirms an uploaded gradebook before it is written |
| runs 35 checks on every record and routes only real problems to the right person | decides exceptions to policy |
| writes the factual parts of the report and lists **facts** next to it | writes *what the results mean* and suggestions for next time |
| detects missed goals and prepares an improvement record with the evidence | writes the improvement plan: what changes, who, by when |
| asks for evidence when grades arrive, suggests what each file is, checks and stores it | corrects the suggestion; the Head of Department or Quality **accepts** the evidence |
| tracks the course file, reminds before the deadline, shows the board, builds the package with checksums | Quality decides what the checklist requires |
| reports its own security status, keeps the audit log and its witnesses, keeps the incident clock | IT and the data protection officer decide and act |

## The stack, one honest line each

| Item | What to say |
|---|---|
| PHP 8.3, plain, no framework, no third-party runtime libraries | "Any university web team can host and read it; there is nothing to patch but PHP itself." |
| MySQL 8 | schema plus versioned migrations; append-only triggers on the audit log |
| Apache (php:8.3-apache image) | serves `public/` only; files outside the web root |
| Caddy 2 | the HTTPS proxy with automatic certificates (optional profile) |
| Docker Compose | the demo stack, and a hardened production overlay: secret files, read-only containers, dropped capabilities, ports on 127.0.0.1, limits |
| GitHub Actions | every suite on MySQL, the Docker/HTTPS/backup-restore check, the hardened production stack, an informational Trivy scan; Dependabot for actions and images |
| ClamAV (optional) | virus scanning of evidence when configured; the Security center says when it is not |
| Server-rendered HTML, one CSS file, a little vanilla JavaScript | strict Content-Security-Policy with no inline scripts; nothing loaded from outside the server; Arabic right-to-left |
| Security building blocks | Argon2id, AES-256-GCM, keyed HMAC pseudonyms, TOTP, passkeys (WebAuthn, hand-written, unreviewed), hash-chained audit log with witnesses |
| Integration | read-only connectors: SIS files or API, Moodle, Blackboard, LMS files, and **any JSON API through a mapping file**; OpenID Connect sign-in; SMTP |
| Not used | no AI or LLM service, no paid service, no analytics, no external fonts or scripts |

## Why this should score well (and how to show it)

Hackathon judging commonly weighs innovation, technical implementation, impact, the demo itself and completeness
([Reskilll](https://blogs.reskilll.com/what-hackathon-judges-look-for-complete-judging-criteria-breakdown-2026/)).
Map the demo to that:

- **Working demo, no crashes:** the 7-minute path in [JUDGES_DEMO.md](JUDGES_DEMO.md) was walked in a real browser; reset takes 30 seconds; the fallback needs no server.
- **Technical depth:** integration by configuration, pseudonymisation on every grade path, tamper-evidence with external witnesses, a hardened container setup verified in CI ([OWASP Docker Security Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Docker_Security_Cheat_Sheet.html) practices: read-only file system, dropped capabilities, `no-new-privileges`, resource limits).
- **Impact:** say what each faculty member stops doing (rows above). Do not invent time savings; offer to measure them in the pilot.
- **Completeness and honesty:** the [readiness page](READINESS.md) lists exactly what is left; judges trust a team that knows its limits.

## Say this / never say this

| Say | Never say |
|---|---|
| "Demo data: a snapshot of YU's public study plans plus fictional people and synthetic results." | "Real university data." |
| "Integration-ready: tested against stand-in servers and a simulated API; connecting YU needs IT's access." | "Connected to Edugate / the LMS." |
| "A hardened demo, a candidate for a pilot." | "Certified", "production-proven", "NCAAA-approved". |
| "Tamper-evident audit log with checkpoints outside the server." | "Tamper-proof", "impossible to change". |
| "A self-test and automated probes." | "Penetration tested", "unhackable". |
| "Passkeys implemented and tested; not independently reviewed." | "Military-grade" anything. |

## Questions and honest answers

| Question | Answer |
|---|---|
| *How would it connect to Edugate?* | Through an approved, read-only API or a scheduled export with a service account. If the API speaks JSON over HTTPS, IT writes a mapping file and checks it offline with `bin/mapping_check.php`; no code change. We have not seen Edugate's API documentation, so nothing about it was guessed. |
| *And if their API is not JSON?* | A scheduled CSV export works today (`SAQF_SIS_SOURCE=file`); anything else needs a small adapter implementing one interface (`src/Integration/Sources.php`). |
| *How are students protected?* | Student numbers become keyed pseudonyms the moment a gradebook is read, on every path, and raw-looking keys are refused. No names are stored. The key comes from the university's vault in production. |
| *What if the server is breached?* | The attacker gets pseudonymous marks without names; secrets are encrypted; backups are encrypted when a passphrase is set. With the application key as well, they could test candidate student numbers, which is why the key is kept out of the database and backups. See [THREAT_MODEL.md](THREAT_MODEL.md). |
| *How do you know the audit log was not edited?* | Each entry is chained to the previous one by hash, triggers refuse edits through SAQF's account, the chain is verified nightly, and a checkpoint is e-mailed or posted outside the server. A database administrator could still rewrite rows, but not the checkpoints others already received. |
| *Who approves what?* | Specification changes: the Head of Department, with Quality for anything flagged. Evidence: the Head of Department or Quality. Exceptions: Quality. Administrators run the system and cannot approve academic matters. |
| *Is it AI?* | No. 35 explainable rules and simple statistics; mapping suggestions are text similarity and are applied only when a professor accepts them. |
| *Is the Arabic machine-translated?* | No online service is used: a fixed dictionary written for SAQF, course names from the catalogue, course content typed in Arabic by people. The Deanship of Quality should still review the academic terms. |
| *Is it accessible?* | Checked with axe-core (WCAG 2.2 A/AA rules) in English, on a phone-sized screen and in Arabic; the issues found were fixed. A formal accessibility review is still on the readiness list. |
| *What is not finished?* | Identity-provider registration and its MFA policy, the approved integration contract, key storage, HTTPS on the university domain, a restore test on university servers, malware-scanning and retention decisions, an independent security review and a department pilot ([READINESS.md](READINESS.md)). |

## If something fails on stage

Follow the fallback in [JUDGES_DEMO.md](JUDGES_DEMO.md#fallback-if-the-live-demo-cannot-run). The mapping check
runs without a database or network, and the screenshots in `docs/screenshots/` (30–35) cover the faculty path.
