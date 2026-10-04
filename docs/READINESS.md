# SAQF readiness: what it is today, and what each next step needs

This page is deliberately plain about status. SAQF has **not** been connected to Al Yamamah
University's live systems, has **not** had an independent security review, and is **not** certified
by anyone. Nothing in SAQF approves an NCAAA report.

## Three different things

| | **Docker-deployable demo** (today) | **Candidate for a controlled pilot** | **Live production system** |
|---|---|---|---|
| What it is | The full application running on fictional people, simulated SIS/LMS feeds and synthetic pseudonymous results, on top of YU's *public* study plans | One department, one term, real staff accounts, real (exported) data under an agreed data-handling note, with fall-back to the current process | University-wide service operated by IT under its normal change, security and support processes |
| Data | Fictional / synthetic only (labelled DEMO on screen and in every course file package) | Real, minimal, pseudonymised student data; real staff names | Real |
| Sign-in | Demo accounts and one-click demo sign-in | University SSO (OIDC) + break-glass admin passwords | Same, with the IdP's MFA policy confirmed |
| Integrations | Simulated (`SAQF_SIS_SOURCE=demo`, `SAQF_LMS_SOURCE=demo`) | Approved export files or read-only API agreed with IT (see the integration contract in [INTEGRATIONS.md](INTEGRATIONS.md#edugate-and-lms-integration-contract-awaiting-university-it)) | Same, monitored, with reconciliation reports reviewed each term |
| Who may say it is ready | The project team | Deanship of Quality + IT security + the pilot department | The university's governance (IT, information security, Deanship of Quality) |
| Status | **Done** — `docker compose up --build`; the automated suites of `bin/test_all.sh` in CI, plus a hardened production configuration verified in CI | **Not started** — prerequisites below | **Not started** |

## Prerequisites still open before a pilot

Each item names who has to act. None of these can be completed by the development team alone.

| # | Prerequisite | Owner | How SAQF shows it |
|---|---|---|---|
| 1 | **Institutional identity:** SAQF registered in the university's identity provider (Entra ID or other OIDC), role mapping agreed, and **the IdP's MFA policy for SAQF confirmed in writing**. SAQF does not enforce MFA on federated sign-in; it can only refuse tokens that do not report it (`SAQF_OIDC_REQUIRE_MFA`) | University IT / identity team | Security center: *University single sign-on*, *Two-step verification for university sign-in* |
| 2 | **Approved integration contract** for Edugate (SIS) and the LMS: approved API or secure scheduled export, authentication method, least-privilege scopes, field mapping, anonymised staging sample, dry run, reconciliation report, IT sign-off. No Edugate adapter exists today | Registrar, LMS owner, University IT | Go-live page: *Demo data* until then |
| 3 | **Application key storage:** `SAQF_APP_KEY` generated, stored in the password vault with an offline copy, and set before the first production install | University IT | `bin/app_key.php status`, `/health.php` `app_key`, Security center *Application key* |
| 4 | **HTTPS** on the university's domain with its certificate or the bundled Caddy proxy; every outbound address `https://` | University IT | Security center: *Encrypted connections (HTTPS + HSTS)*, *Encrypted connections to university systems* |
| 5 | **Backups and a restore test:** encrypted backups copied to a share on another machine, and a restore drill performed and recorded on the university's infrastructure (CI only proves the scripts on a throw-away demo stack) | University IT | System health / Security center: *Backups* (says that a restore has not been tested from SAQF) |
| 6 | **Malware-scanning policy:** ClamAV (bundled profile) with monitored signature updates, or a documented decision that university controls cover uploads | IT security | Security center: *Evidence virus scanning* with recorded scan counts |
| 7 | **Retention, privacy and accessibility policy:** how long course files, evidence and audit logs are kept; the privacy notice for staff and students; an accessibility review of the main faculty pages | Records office, legal/privacy, accessibility lead | Not in SAQF: documented outside it |
| 8 | **Independent security review** (penetration test or equivalent) of the pilot deployment, including a review of the hand-written passkey (WebAuthn/CBOR) code. The built-in self-test is not a substitute | IT security (or an approved external reviewer) | Security evidence report lists it under *Not covered by this report*; Security center *Passkeys* |
| 9 | **Quality policy confirmation:** achievement method and thresholds, and the course file checklist | Deanship of Quality | Quality policies; the Closeout tab says "SAQF's default" until Quality changes it |
| 10 | **Production configuration and preflight:** deploy with `docker-compose.prod.yml` and secret files ([DEPLOY.md](DEPLOY.md)), and bring `php bin/preflight.php` to "ready" (no demo accounts, https, key, backups, a recorded restore drill) | University IT | Administration → Go-live → *Production preflight* |
| 11 | **Data-protection decisions:** retention, deletion and data-subject requests ([PRIVACY.md](PRIVACY.md)), and the incident-notification procedure that the incident register supports | DPO / legal | Not in SAQF |
| 12 | **Department pilot:** one department, one term, with success measures agreed in advance (time spent on course files, return rounds, approval time) and the existing process kept as fall-back | Head of Department + Deanship of Quality | — |

## What the demo does prove

- The faculty course-file workflow end to end on realistic (fictional) data, in English and Arabic.
- The security controls listed in the Security center, each with its live, recorded status.
- That the code paths for SIS/LMS files, Moodle, Blackboard, a generic SIS REST API, **a mapped JSON API of a different shape** and OIDC work **against local stand-in servers** (`tests/mock/`, including a *simulated* university API in `tests/mock/uni_api.php`). That is not the same as working against YU's systems.
- That a production-mode installation refuses demo shortcuts, plain-http connectors and a missing application key.

## What the demo does not prove

- Any live connection to Edugate, the university's LMS or its identity provider.
- That the university's real data fits the expected formats (the dry-run step of the integration contract is there to find out).
- Performance at university scale, availability, or operational support.
- Compliance with NCAAA standards, or approval of any report.
