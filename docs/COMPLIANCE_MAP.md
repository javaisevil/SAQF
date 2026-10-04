# Control mapping: OWASP, NCA ECC and PDPL themes

This is a **mapping prepared by the developers**, not an audit, a certification or a legal opinion. It shows which SAQF control speaks to which area of three frameworks a Saudi university is likely to be asked about, how far it goes, and what stays the university's job. SAQF is a demo with an integration-ready handoff; none of the frameworks has been assessed against it by anyone independent. Control **numbers** of the NCA controls are deliberately not quoted (the developers could not confirm them in this session): only domain and sub-domain **names** are used, and they should be checked against the official text.

Sources used for the names: the OWASP Application Security Verification Standard and OWASP Top 10 (owasp.org), the OWASP Docker Security Cheat Sheet (<https://cheatsheetseries.owasp.org/cheatsheets/Docker_Security_Cheat_Sheet.html>), and the National Cybersecurity Authority's Essential Cybersecurity Controls (ECC-1:2018) (<https://nca.gov.sa/ecc-en.pdf>), whose five main domains are Cybersecurity Governance, Cybersecurity Defense, Cybersecurity Resilience, Third-party and Cloud Computing Cybersecurity, and Industrial Control Systems Cybersecurity. The PDPL themes below come from the public descriptions the developers read; the Law and its Implementing Regulations are the authority.

**Coverage key:** **Covered** = a control exists in the code or the shipped configuration and a test or CI job exercises it · **Partial** = part of the area is covered · **University** = the university's own process or system has to provide it · **Not covered**.

## 1. OWASP ASVS (by chapter)

| ASVS chapter | SAQF control | Evidence | Coverage |
|---|---|---|---|
| Authentication | Argon2id hashing, lockout, per-address throttle, robot check, password rules, second step for everyone using a password, TOTP, e-mailed codes, passkeys, trusted browsers | `Auth.php`, `Mfa.php`, `Passkeys.php`, `BotGuard.php`, `tests/signin_test.php`, `tests/passkey_test.php` | Covered (passkey code unreviewed) |
| Session management | strict cookies, rotation at sign-in, idle/absolute limits, listed and revocable sessions, `Clear-Site-Data` | `Session.php`, `Sessions.php`, `tests/signin_test.php` | Covered |
| Access control | server-side role and scope checks on every request, denial audited, CSRF tokens | `Authz.php`, `tests/http_smoke.php`, `tests/usability_test.php` | Covered |
| Validation, sanitization and encoding | output escaping, strict CSP, validated uploads, parameterised SQL, injection probes | `View.php`, `Evidence.php`, `tests/injection_test.php` | Covered (probes check known patterns only) |
| Stored cryptography | AES-256-GCM for stored secrets, keyed HMAC pseudonyms, Argon2id, encrypted backups | `Secrets.php`, `docker/backup.sh` | Covered; key rotation not supported in place |
| Error handling and logging | references instead of details, stack traces without arguments, hash-chained audit log with witnesses | `ErrorLog.php`, `Audit.php`, `Witness.php`, `tests/hardening_test.php` | Covered (tamper-evident, not tamper-proof) |
| Data protection | pseudonymised student data, no names, `Cache-Control: no-store`, masked file names | `Gradebook.php`, `bootstrap.php`, `Evidence.php` | Covered; retention is a university decision |
| Communications | HTTPS through Caddy, HSTS, https-only connectors in production | `docker/Caddyfile`, `Http.php`, CI job *Docker stack* | Covered when the proxy is used; plain HTTP is for local demo only |
| Malicious code | no third-party runtime libraries, Dependabot, informational Trivy scan, ClamAV optional for uploads | `.github/dependabot.yml`, `.github/workflows/ci.yml` | Partial (scan is informational; ClamAV is optional) |
| Business logic | human review boundaries (SAQF never writes the reading of results, never accepts evidence, never approves specifications) | `Closeout.php`, `Authz.php`, `tests/closeout_test.php` | Covered |
| Files and resources | type and content checks, size limits, files outside the web root, random names, attachment downloads | `Evidence.php`, `tests/features_test.php` | Covered |
| API and web services | connectors are read-only; mapped APIs validated; size, row and page caps; no redirects followed | `Mapping.php`, `MappedApi.php`, `tests/mapping_test.php` | Covered |
| Configuration | read-only containers, dropped capabilities, secret files, preflight, production refuses insecure settings | `docker-compose.prod.yml`, `Preflight.php`, CI job *Hardened production stack* | Covered |
| Architecture and threat modelling | [THREAT_MODEL.md](THREAT_MODEL.md) | — | Partial (not independently reviewed) |

## 2. OWASP Top 10 (2021 categories)

| Category | SAQF |
|---|---|
| Broken Access Control | server-side scope on every request; tests for every page and role |
| Cryptographic Failures | HTTPS (proxy), HSTS, encrypted secrets and backups, no secrets in images |
| Injection | parameterised SQL, escaped output, injection probes |
| Insecure Design | threat model, human-review boundaries, read-only connectors |
| Security Misconfiguration | hardened compose, preflight, Security center, headers |
| Vulnerable and Outdated Components | no runtime libraries; Dependabot; informational image scan; base image must be kept current |
| Identification and Authentication Failures | see ASVS authentication |
| Software and Data Integrity Failures | hash-chained audit log, checksums on evidence and packages, no third-party scripts |
| Security Logging and Monitoring Failures | audit log, IT alerts, CSP violation reports, witnesses |
| Server-Side Request Forgery | outbound calls only to addresses the administrator configures; https only in production; no redirects; next-page links to other hosts refused |

## 3. NCA Essential Cybersecurity Controls (domain and sub-domain names only)

| Domain / sub-domain | SAQF contribution | Coverage |
|---|---|---|
| Cybersecurity Governance | policies, roles and review boundaries inside SAQF (access review, audited policy changes); the university's governance is its own | University |
| Defense: Identity and Access Management | second step for everyone on password sign-in, passkeys, least-privilege roles, access review, sessions, lockout, re-authentication for sensitive actions | Covered (identity-provider MFA for single sign-on must be confirmed by IT) |
| Defense: Information System Protection / Network Security | hardened containers, ports on 127.0.0.1 behind a TLS proxy, `SAQF_ADMIN_ALLOWED_IPS`; the university's network is its own | Partial |
| Defense: Data and Information Protection | pseudonymisation, no names, encrypted secrets and backups, role-scoped views | Covered; classification and retention are the university's |
| Defense: Cryptography | AES-256-GCM, Argon2id, HMAC, TLS (proxy) | Covered; key management beyond `SAQF_APP_KEY` is the university's |
| Defense: Backup and Recovery Management | encrypted backups, second copy, restore drill, truthful backup status | Partial (recovery time and the host are not tested) |
| Defense: Vulnerabilities Management | Dependabot, informational Trivy scan, preflight | Partial |
| Defense: Penetration Testing | the self-test is a self-check, **not** a penetration test | Not covered |
| Defense: Event Logs and Monitoring | hash-chained audit log, IT alerts, e-mail/webhook, witnesses | Covered |
| Defense: Incident and Threat Management | incident register with a notification clock; decisions stay with people | Partial (the university's process is its own) |
| Defense: Web Application Security | strict CSP, CSRF, escaping, upload checks, response headers, injection probes | Covered |
| Cybersecurity Resilience | backups, restore drill, health probe; business continuity is the university's | Partial |
| Third-party and Cloud Computing | self-hosted: no third-party service is needed or used | Covered by design |
| Industrial Control Systems | not applicable | — |

## 4. Saudi PDPL themes (themes only; the Law decides)

| Theme | SAQF | Coverage |
|---|---|---|
| Purpose and lawfulness | the purpose (course quality measurement) is narrow; the legal basis is the university's to establish | University |
| Data minimisation | no student names or contact data; keyed pseudonyms; no analytics or third parties | Covered |
| Accuracy | marks come from the university's LMS or from the instructor; corrections replace earlier marks, announced in the preview | Partial |
| Storage limitation | no automatic retention schedule; only short-lived technical records are purged | **Not covered** (decision needed, see [PRIVACY.md](PRIVACY.md)) |
| Security safeguards | see the sections above | Covered / Partial |
| Breach notification | incident register with a 72-hour clock as an aid; notification decisions and contact with authorities stay with the DPO or legal counsel; SAQF never contacts anyone | Partial (aid only) |
| Data-subject rights | no self-service export or erasure; the procedure is the university's | University |
| Cross-border transfer | none by SAQF (self-hosted); depends on where the university hosts it and which mail/webhook services it configures | University |

## 5. How to use this

Give the DPO [PRIVACY.md](PRIVACY.md), IT security [THREAT_MODEL.md](THREAT_MODEL.md) and [SECURITY_ASSURANCE.md](SECURITY_ASSURANCE.md), and let them mark each row. Anything marked *Partial* or *University* is a work item before a pilot, not a claim.
