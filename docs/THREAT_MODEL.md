# Threat model

For university IT security and the judges' security questions. It lists what SAQF protects, who might attack it, where the trust boundaries are, and — threat by threat — what stops it, which file or test shows that, and what risk is **left**. It is written by the developers from the code; it is not an independent assessment, and SAQF has not had a penetration test or an independent code review.

## 1. Assets

| Asset | Why it matters |
|---|---|
| Pseudonymous student marks | privacy; a wrong mark distorts quality decisions |
| Staff accounts and their second factors | an account is the way to every action |
| The application key (`SAQF_APP_KEY`) | derives student pseudonyms and protects stored secrets; without it nothing can be matched, with it a candidate list can be tested |
| Course records, specifications, reports, approvals | the academic record; approvals must come from the right person |
| Evidence files | may contain names inside marked work |
| The audit log | the only account of who did what |
| Connector credentials (SIS/LMS tokens, SMTP password) | read access to university systems |
| Backups | a complete copy of everything |

## 2. Actors

A curious student (no SAQF account; can reach the sign-in page and the public files only) · a careless or malicious instructor · a compromised instructor account (phished password) · an administrator who misuses their role · an external attacker on the internet · a malicious or compromised SIS/LMS API · someone who gets a copy of a backup or the database · a compromised dependency or base image.

## 3. Trust boundaries and data flow

```
Browser ──HTTPS──▶ Caddy (TLS, HSTS) ──▶ Apache + PHP (SAQF) ──▶ MySQL        evidence files (outside web root)
                                                │  ▲                  ▲
                         read-only GET only     │  │ sign-in / mail   └── backups (encrypted if a passphrase is set) ──▶ second location
          SIS / LMS APIs ◀──────────────────────┘  └── identity provider (OpenID Connect), SMTP, optional alert webhook, optional ClamAV
```

Boundaries: internet ↔ Caddy · Caddy ↔ the PHP container · PHP ↔ MySQL · SAQF ↔ the university's SIS/LMS/identity provider/mail (the university's systems are treated as **untrusted input**, not as authorities for SAQF's own decisions) · the host ↔ backups.

## 4. Threats and mitigations (STRIDE)

| # | Threat | Mitigation (where) | Shown by | Residual risk |
|---|---|---|---|---|
| **S1** | Password guessing or stuffing | Argon2id, lockout per account and per address, proof-of-work robot check, password rules (`Auth.php`, `PasswordPolicy.php`, `BotGuard.php`) | `tests/signin_test.php`, `tests/http_smoke.php` | A strong attacker with many addresses is slowed, not stopped; the lockout can be used to lock a victim out for the lockout period |
| **S2** | Phished password | Second step for everyone who signs in with a password; passkeys bind the step to this site (`Mfa.php`, `WebAuthn.php`, `Passkeys.php`) | `tests/signin_test.php`, `tests/passkey_test.php`, `tests/e2e/passkeys.mjs` (manual) | E-mailed or app codes can still be phished in real time. **The WebAuthn/CBOR code is hand-written and has not been independently reviewed.** University single sign-on moves the second step to the identity provider, whose policy IT must confirm |
| **S3** | Session theft or fixation | Strict cookies (HttpOnly, SameSite=Strict, Secure over HTTPS), session ids rotated at sign-in, binding to the browser fingerprint, idle and absolute limits, sessions listed and revocable, `Clear-Site-Data` on logout (`Session.php`, `Sessions.php`, `bootstrap.php`) | `tests/signin_test.php`, `tests/hardening_test.php` | A stolen session used from the same browser fingerprint inside the time limits is accepted |
| **S4** | Forged requests (CSRF) | A token on every state-changing form and API call (`Csrf.php`, `saqf_require_post()`, `api.php`) | `tests/http_smoke.php`, `tests/usability_test.php` | none known |
| **T1** | Tampering with the audit log | Hash-chained entries, append-only triggers (`database/audit_guard.sql`), nightly verification, external **witnesses** (entry number, count and hash e-mailed or posted by `Witness.php`) | `tests/hardening_test.php` (section 12) | **Tamper-evident, not tamper-proof.** Someone with database administrator rights can rewrite history; the witness catches it only if the witness messages are kept somewhere that person cannot reach (a mailbox or channel with retention) |
| **T2** | Tampering with marks by a section instructor | A section instructor can only add marks for the sections they teach; a student already filed under another section is never overwritten (`workspace.php`, `Achievement::import`) | `tests/usability_test.php` (section 7) | The coordinator can import for every section by design |
| **T3** | Tampering via a malicious SIS/LMS API | Read-only GETs; strict normalisation of every field; mapped connectors validate the mapping and refuse next-page links to other hosts, repeated pages, oversized answers (32 MB), truncation at the page limit; https only in production (`Mapping.php`, `MappedApi.php`, `Http.php`) | `tests/mapping_test.php`, `tests/production_test.php` | A compromised API can feed wrong marks that pass validation; reconciliation against the source (docs/INTEGRATIONS.md) is a human step |
| **R1** | Denying an action | Every important action is audited with actor, time and address; approvals name who decided (`Audit.php`) | `tests/automation_test.php` | The actor is as trustworthy as the account |
| **I1** | Student identities leaked | Keyed pseudonyms on every grade path, raw-looking keys refused, no names stored, previews and logs carry no identifier (`Gradebook.php`, `Secrets.php`, `Achievement.php`) | `tests/hardening_test.php`, `tests/mapping_test.php` | Anyone holding the key and a list of candidate numbers can test pseudonyms; evidence files may contain names |
| **I2** | Evidence file names reveal students | Titles built from kind and assessment, digit runs masked, marked work stored under a neutral name (`Evidence.php`) | `tests/usability_test.php` (section 7) | A name typed into a title or written inside a file cannot be recognised |
| **I3** | Secrets in the environment, process list or images | `*_FILE` secrets (Docker/Kubernetes pattern), no secret in `docker inspect`, `.env` and `secrets/` never committed (`Config.php`, `docker-compose.prod.yml`, `bin/make_secrets.sh`) | CI job *Hardened production stack* | A person with root on the host can read the secret files |
| **I4** | A leaked backup | Encrypted with a passphrase (AES-256) when `SAQF_BACKUP_PASSPHRASE` is set; backup state reported truthfully (`docker/backup.sh`, `SecurityCenter.php`) | CI jobs *Docker stack* and *Hardened production stack* | Unencrypted if no passphrase is set; the passphrase needs its own safekeeping |
| **I5** | Cross-site scripting | Output escaped everywhere, strict CSP with no inline scripts, no third-party scripts, evidence downloaded as attachments with a sandboxing CSP, injection probes (`View.php`, `bootstrap.php`, `Evidence.php`) | `tests/injection_test.php` | `style-src` still allows inline styles; the probe checks known patterns only |
| **I6** | Information in error pages | Errors show only a reference; stack traces never carry argument values (`ErrorLog.php`, `php.ini`) | `tests/hardening_test.php` | none known |
| **D1** | Flooding forms, uploads and exports | Per-user and per-address rate limits, upload and size limits, bounded pages and page parameters (`Throttle.php`) | `tests/http_smoke.php`, `tests/injection_test.php` | There is no network-level DDoS defence: put SAQF behind the university's own protections |
| **D2** | Disk or memory exhaustion by a connector | 32 MB answer cap, 200,000-row cap, bounded paging (`Http.php`, `MappedApi.php`) | `tests/mapping_test.php` | Large legitimate gradebooks may need the caps raised |
| **E1** | A user reaches another department's or role's data | Server-side scope on every request (`Authz.php`, `Authz::offeringScope`), 403 and audit on denial | `tests/http_smoke.php` (every page for every role), `tests/usability_test.php` (closeout board) | The scope rules are the university's to confirm |
| **E2** | An administrator approves academic matters | The administrator role cannot decide approvals, specifications or exceptions; sensitive administrator actions need recent re-authentication and a reason (`Authz.php`, `admin.php`) | `tests/http_smoke.php`, `tests/signin_test.php` | An administrator can still change their own database, files and configuration |
| **E3** | A planted passkey outlives an administrator's reset | The administrator's two-step reset also revokes the person's passkeys (`Mfa::disable`) | `tests/usability_test.php` (section 7) | The attacker already had the password and a code |
| **E4** | A container breakout or a vulnerable base image | Read-only root, `no-new-privileges`, capabilities dropped, ports bound to 127.0.0.1 behind Caddy, resource limits, scheduler as `www-data` (`docker-compose.prod.yml`, `docker/entrypoint.sh`); Dependabot; informational Trivy scan in CI | CI job *Hardened production stack*, job *Image and configuration scan (informational)* | The scan is informational (does not fail the build); the base image still needs regular updates |
| **X1** | Silent failure of protections | The Security center, the preflight (`bin/preflight.php`) and IT alerts report what is *not* configured; browsers' CSP violation reports are recorded (`csp_report.php`) | `tests/readiness_test.php`, `tests/injection_test.php` | Reports are only as good as someone reading them |

## 5. Accepted for a demo, to close before a pilot

1. The demo accounts share a published password (the preflight fails while any are active).
2. Passkey/WebAuthn code is unreviewed; administrators keep the authenticator app as an additional requirement.
3. No penetration test, no independent code review.
4. Nothing has been connected to the real Edugate, LMS or identity provider; their security properties are unknown to the project.
5. Retention and deletion rules are not decided ([PRIVACY.md](PRIVACY.md)).
6. Witness messages are only useful once IT keeps them somewhere out of reach of database administrators.
7. The restore drill proves a backup can be restored into a scratch server, not recovery time or the host's hardware.

## 6. How to challenge this model

Run `php bin/preflight.php`, `sh bin/test_all.sh` and the manual browser checks in `tests/e2e/`; read [SECURITY_ASSURANCE.md](SECURITY_ASSURANCE.md) for the evidence behind each control and [SECURITY.md](../SECURITY.md) for how to report a problem.
