# Privacy and data protection design

For the university's data protection officer (DPO), IT security and the Deanship of Quality. This note says what personal data SAQF holds, how student identifiers are handled, who can see what, what is kept and for how long, and which decisions belong to people. It is a description of the software as it is in this repository, written by the developers.

> **This is not legal advice and not a compliance statement.** Whether SAQF's design satisfies the Saudi Personal Data Protection Law (PDPL), the university's own policies or any other rule is for the university's DPO and legal counsel to decide. Nothing here has been reviewed by a lawyer. SAQF is a demo with an integration-ready handoff: it has not been connected to real student or staff data (the demo uses fictional people and synthetic, pseudonymous results over a snapshot of the university's public study plans).

## 1. The design rule in one paragraph

SAQF measures **courses**, not students. It needs a student's marks only to count how many students reached a learning-outcome goal. So the student's identifier is replaced by a **keyed pseudonym** the moment a gradebook is read, student names are never stored, and no screen, export or log lists students. What remains personal data are **staff** (name, university e-mail, role, sign-in activity) and the **pseudonymous marks**.

## 2. Data inventory

| Data | Where (tables) | Purpose | Who can see it | Leaves the server? |
|---|---|---|---|---|
| Staff identity: name, university e-mail, username, role, department/college, optional SIS/HR identifier, title, language | `users` | sign-in, routing of tasks, audit | the person; Heads of Department/Quality/administrators in the screens their role allows; administrators in Administration → Users | e-mail address is used for SAQF's own e-mails (sign-in codes, digests, resets) through the configured SMTP server |
| Password hash (Argon2id), two-step secret (AES-256-GCM encrypted), recovery-code hashes, passkey public keys | `users`, `mfa_recovery_codes`, `passkeys` | authentication | nobody (never displayed) | no |
| Sessions, trusted browsers, sign-in attempts, new-device records | `user_sessions`, `trusted_devices`, `login_attempts`, `rate_limits` | security, session list, brute-force protection | the person (own sessions); administrators (security events) | no |
| **Pseudonymous student marks**: `student_ref` (keyed pseudonym), assessment, percentage, optional section code | `assessment_results`, `result_batches` | CLO/PLO achievement, section comparison | instructors, Heads of Department, Quality, deans, leadership see **aggregates**; the pseudonym itself is never shown | no |
| Computed achievement per outcome (counts and percentages) | `clo_achievement`, `plo_achievement` | quality reports | as above | no |
| Course records: specifications, outcomes, assessments, narratives written by instructors, improvement actions, findings | `spec_versions`, `clos`, `assessments`, `offering_narratives`, `improvement_actions`, `findings`, … | the quality process | by role and scope | Word reports are downloaded by the person who asks |
| Evidence files (exam papers, rubrics, samples of marked work) and their index | `evidence_files` + files under `SAQF_STORAGE_DIR` (outside the web root) | course file | the course's instructors, Heads of Department, Quality; every download is audited | no. **Marked student work may carry student names inside the file**: see section 5 |
| Audit log (who did what, when, from which address) | `audit_log`, `audit_witnesses` | accountability | administrators and Quality | witness lines (entry number, count, hash) may be e-mailed or posted to a webhook configured by IT; they contain no personal data |
| Notifications and the e-mail outbox | `notifications`, `mail_outbox` | tell people when something needs them | the recipient; administrators see delivery status | e-mails go to the configured SMTP server |
| Security incident register (title, description, personal-data flag, timeline) | `security_incidents`, `incident_events` | the university's own incident process | administrators | no. **SAQF never contacts an authority or any person by itself** |
| Backups (encrypted when a passphrase is set) | files under `storage/backups` and the optional second copy | recovery | IT | to the second location IT configures |

Not stored: student names, student e-mail addresses, national ID numbers, phone numbers, photographs, free-text feedback about individual students, any biometric template (a passkey stores only a public key; the biometric stays on the person's device).

## 3. Student identifiers

* **Every grade source is pseudonymised on arrival**: the manual gradebook upload (`src/Integration/Gradebook.php`), the LMS export folder, the Moodle and Blackboard connectors and the mapped-API connector (`src/Integration/Mapping.php`) all call `Secrets::pseudonym()`. There is no switch to turn it off (`SAQF_LMS_PSEUDONYMIZE=false` is ignored and reported).
* The pseudonym is an HMAC of the identifier under the **application key** (`SAQF_APP_KEY`). It is stable (the same student gets the same pseudonym every term, which is what makes term-to-term comparison possible) and one-way (it cannot be reversed without guessing identifiers and the key).
* `Achievement::import()` refuses any key that looks like a raw student number (digits only), so a connector that forgets to pseudonymise cannot store one by accident.
* The upload preview shows only pseudonym-based statistics (counts, averages, ranges); it never shows a student number, and the preview held in the session contains no raw identifier.
* **What a leaked database reveals:** marks under pseudonyms, with no names and no way to recover identifiers without the application key. **What a leaked database plus the key reveals:** nothing directly, but anyone holding both and a list of candidate student numbers could test which pseudonym belongs to whom. Keep `SAQF_APP_KEY` in a secret store, separate from database backups ([OPERATIONS.md](OPERATIONS.md#application-key-saqf_app_key)).
* **Losing the key** makes the existing pseudonyms unmatchable with new uploads (history stays, but a student's marks in a new term would no longer link to the old ones). Rotation is not supported in place.

## 4. Who sees what

Access is by role and scope (`src/Security/Authz.php`): instructors see their own courses; a Head of Department sees the department's courses; Quality sees all; deans and leadership see aggregates for their college or the university; administrators run the system but cannot approve academic changes. All of this is enforced on the server for every request, not only hidden in menus, and denied attempts are audited. Reports and exports contain course-level figures; the course file package contains no student identities that SAQF can recognise.

## 5. Evidence files and file names

Evidence files are stored outside the web root under random names, checked by type and content, optionally scanned with ClamAV, and every upload, download and removal is audited. SAQF cannot look inside a PDF to find a student's name. Therefore:

* Instructors are told to remove student names from samples before uploading.
* File names and titles are treated as untrusted personal data: long digit runs (6 or more) are masked, a suggested title is built from the kind and the assessment (never from the file name), and samples of marked student work are stored under a neutral name made from the title, so a student's name or number in a file name does not reach the database, the audit log, alerts or the course file package. A name typed by a person into a title cannot be recognised.
* The content of an uploaded file is the instructor's responsibility; the university's policy decides whether marked work with names may be kept.

## 6. Retention

SAQF deletes only short-lived technical records automatically (rate-limit windows after a day, used robot-check puzzles, single-sign-on handshakes after a day). **Everything else is kept until someone removes it:** users, results, specifications, evidence, notifications, audit entries and incident records. There is no built-in retention schedule, no scheduled purge of old terms and no "delete this person" function; disabling an account keeps its history (the audit log must remain complete). The audit log is append-only by design.

Decisions the university has to take before real data is loaded: how long result pseudonyms and evidence are kept after a term closes, when staff records and audit entries may be archived, and how a data-subject request is answered (section 8). Until then the safe default is to keep nothing that is not needed.

## 7. Breach handling: the incident register

Administrators can register a security incident (Administration → Security incidents). If personal data is involved, SAQF starts a **72-hour clock** from when the university became aware, shows the time left, raises an IT alert when it is nearly or fully used, and records who decided what and when. The 72 hours reflect the notification period described in the PDPL sources the developers read; **whether notification is required for a given incident, and the notification itself, are decisions for the DPO or legal counsel.** The register supports the university's process and does not replace it.

## 8. Data-subject requests

SAQF has no self-service export or erasure. Technically: a staff member's data is in `users`, `user_sessions`, `trusted_devices`, `passkeys`, `notifications` and in the audit log (which records actions, not content). Student marks cannot be linked to a person without the identifier list held by the university's own systems, so a request about a student is answered from the SIS/LMS; SAQF could locate a student's pseudonymous rows only if the university supplies the identifier and the key is available. Erasure of an audit entry is deliberately not possible.

## 9. Outbound data

SAQF sends data only to systems the university configures: SMTP (e-mail), an optional alert webhook (IT alerts and audit witness lines), the identity provider (sign-in) and, read-only, the SIS and LMS APIs it reads from. There is no analytics, no advertising, no external fonts or scripts, no AI/LLM service and no other third party; pages load nothing from outside the server.

## 10. Open items for the DPO

1. Retention periods and a purge procedure (section 6).
2. Whether marked student work may be stored and in what form (section 5).
3. Who may see course-level results by name of instructor (the screens show instructor names next to course results by design).
4. The data-subject request procedure and who answers it (section 8).
5. A review of this design against the PDPL and the university's policies, and a written decision on the incident-notification procedure (section 7).
