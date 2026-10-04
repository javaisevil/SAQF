# SAQF security assurance

Applies to SAQF 2.5.0 (`SAQF_VERSION` in `src/bootstrap.php`).

**What this file is.** An inventory of the security controls in this repository: what each one does, where the code
is, which test or CI job exercises it, and where its limits are. It was written from the source files,
`.github/workflows/ci.yml` and the test suites in `tests/`. It quotes no test counts or pass rates, because they change
with every commit: run the commands in [How to reproduce](#7-how-to-reproduce) or open the repository's Actions tab.

**What it is not.** SAQF is a demo with an integration-ready handoff. It has had no penetration test and no independent
security review, and nothing in this file is a certification or an approval by any body. The built-in security
self-test and security evidence report are the project checking its own work. See
[What this does not prove](#6-what-this-does-not-prove). The vulnerability reporting process is in
[`SECURITY.md`](../SECURITY.md).

## 1. How to read this document

**Production and demo are different.** Unless a paragraph says otherwise, a control is described as it behaves with
`APP_ENV=production`. `docker compose up` with the default `.env.example` values starts the demo, which is not a secure
configuration on purpose:

| In demo mode (`APP_ENV` not `production` and `SAQF_DEMO` not `false`) | What production does instead |
|---|---|
| `public/demo.php` signs anyone in as any of the fictional people, including the administrator, with no password and no second step | answers 404 and records `security.demo_refused`; `public/tour.php` redirects to the sign-in page |
| Every demo account has the same published password (`Story::PASSWORD` in `src/Demo/Story.php`) and the e-mailed or authenticator code is shown on screen | no demo accounts; `bin/preflight.php` names any active account that still uses the published password |
| Plain `http://` is accepted for connectors (the stand-in servers in `tests/mock/` need it) | `https://` only, for every outbound request |
| A development application key is generated and kept in the database | the key must come from the environment or a secret file; SAQF refuses to create one |
| `docker-compose.yml` publishes the app port on all interfaces and falls back to placeholder database passwords | `docker-compose.prod.yml` applies the hardening in [section 4.14](#414-hardened-container-deployment-https-and-outbound-connections) |

**Evidence labels.** Each control lists what exercises it:

- **CI test**: a PHP suite in `tests/` that the `tests` job of `.github/workflows/ci.yml` runs on every push and pull
  request. `§n` refers to the numbered `section('…')` headings inside that suite; suites without numbers are cited by
  the heading text.
- **CI job**: one of the Docker jobs (`docker`, `hardened`) or the informational `scan` job, described in section 2.
- **Self-check**: SAQF examines its own installation at run time (Security center, `bin/preflight.php`, the nightly
  self-test). Useful to operators; not independent evidence.
- **No test found**: implemented, and nothing automated exercises it. These are called out so that nobody assumes
  otherwise.

This document does not say whether the latest CI run passed. Check the Actions tab, or run the suites yourself.

## 2. What is verified on every change

`.github/workflows/ci.yml` runs on every push and every pull request (`on: push` and `pull_request`). A newer push on the
same branch cancels a run in progress.

| Job | What it does | What a pass shows |
|---|---|---|
| `tests`: "Tests (PHP 8.3, MySQL 8.0)" | `php -l` on every PHP file; then each suite in `tests/` against a freshly installed demo database on MySQL 8.0 (several start PHP's built-in web server); then `php bin/migrate.php` followed by `--status` | The control logic behaves as the tests assert, on fictional data, against stand-in servers from `tests/mock/` for the identity provider, SIS, LMS, ClamAV, SMTP and webhook; migrations apply |
| `docker`: "Docker stack (HTTPS, encrypted backups)" | Builds the image; starts the compose stack with the HTTPS proxy; waits for the container health check; runs `tests/http_smoke.php` against Apache; requests the sign-in page over HTTPS and checks HSTS, the `Secure` session cookie and the HTTP-to-HTTPS redirect; takes an encrypted backup and checks its status file; runs the restore drill; restores the database and evidence files into the running stack and runs `php bin/verify_audit.php` | The scripts, Apache and the proxy work together in the demo stack |
| `hardened`: "Hardened production stack" | Generates secret files with `bin/make_secrets.sh`; starts with `docker-compose.prod.yml` and the HTTPS proxy; checks that `/health.php` reports the application key as `ok`; checks on the `app` container a read-only root file system, `no-new-privileges`, all capabilities dropped, and that a write to the code directory fails; checks that neither the application key nor the database password appears in `docker inspect` of the app and db containers; checks the scheduler does not run as root; checks HTTPS, the `Secure` cookie and HSTS; runs `bin/preflight.php` in the container; takes an encrypted backup and runs the restore drill with the passphrase supplied as a secret file; checks the preflight now sees the drill | The production overlay applies what its comments say, for those items |
| `scan`: "Image and configuration scan (informational)" | Builds the image and runs Trivy over the working tree (secrets and misconfigurations) and over the image (known vulnerabilities). `continue-on-error: true` and `|| true` on each command | Findings appear in the job log for a person to read. It never blocks a change |

**Not run by CI:** the real-browser tests in `tests/e2e/` (they need Playwright; see their header comments); a real
ClamAV (the suites use `tests/mock/clamd.php`); a real identity provider, SIS, LMS or mail server; any load or
availability test; any test against the university's systems or data.

## 3. Controls at a glance

| # | Control | Exercised by | Largest stated limit |
|---|---|---|---|
| 4.1.1 | Passwords: Argon2id, password rules | CI test | Short built-in word list; no breached-password check |
| 4.1.2 | Lockout, per-address throttle, robot check | CI test (lockout, robot check); no test for the per-address throttle | A stranger can lock a known account; proof of work slows scripts, it is not a human test |
| 4.1.3 | Second step: authenticator app, e-mailed code, recovery codes, trusted browser | CI test | Wrong codes do not lock the account; e-mail fallback is only as strong as the mailbox |
| 4.1.4 | Passkeys (WebAuthn) | CI test (hostile vectors); real-browser test not in CI | **Hand-written, not independently reviewed**; weaker e-mail option stays available |
| 4.1.5 | University sign-in (OpenID Connect) | CI test against a stand-in provider | Never run against a real provider; SAQF does not enforce MFA for it |
| 4.1.6 | Password reset and invitations | CI test | Account is as safe as the mailbox |
| 4.2 | Sessions | CI test (partly) | Idle and absolute timeouts and browser binding have no test |
| 4.3 | CSRF tokens | CI test (several pages) | Not every state-changing page is tested without a token |
| 4.4 | Response headers, strict CSP | CI test; CI jobs (HSTS, `Secure` cookie) | Inline styles are allowed; four headers have no test |
| 4.5 | Browser CSP violation reports | CI test | Anyone can send a report; it is only a rate-limited log entry |
| 4.6 | Authorization, scope, step-up, access review | CI test | Enforced page by page in code; sequential ids; 404 versus 403 reveals existence |
| 4.7 | Input validation, injection and output-encoding probes | CI test | Probes of known patterns, not a scan |
| 4.8 | Evidence upload checks, optional ClamAV | CI test (stand-in scanner) | Type check is by signature, not a parse; scanning is optional |
| 4.9 | Keyed pseudonyms for student identifiers | CI test; self-check | Only as private as the application key |
| 4.10 | AES-256-GCM for stored secrets; secret files | CI test; CI job `hardened` | No key rotation; no encryption of the database or evidence at rest |
| 4.11 | Hash-chained audit log, append-only triggers, external witnesses | CI test; CI job `docker` | Tamper-evident, not tamper-proof; the chain is unkeyed |
| 4.12 | Security incident register | CI test | Records and reminds; every decision stays with people |
| 4.13 | Backups with encryption; restore drill | CI test (skipped without tools); CI jobs | Not authenticated encryption; not yet done on the university's infrastructure |
| 4.14 | Hardened container deployment, HTTPS, outbound https-only | CI jobs `docker`, `hardened`; CI test | The default compose file is the demo; images pinned by tag, not digest |
| 4.15 | Supply chain: no third-party runtime libraries, Dependabot, Trivy | CI job `scan` (informational) | Scan does not gate; the review burden moves onto this project's own code |
| 4.16 | Security center, self-test, evidence report, preflight, IT alerts | CI test; self-check | Self-checks by the same code base; a few rows are fixed statements |

## 4. The controls in detail

### 4.1 Authentication

#### 4.1.1 Passwords

- **What it does.** Hashes passwords with Argon2id (`PASSWORD_ARGON2ID`) when the PHP build has it, otherwise PHP's
  default (bcrypt); older hashes are upgraded at the next successful sign-in. The password policy requires at least
  `auth.min_password_length` characters (default 10), at most 200, letters and a digit; it refuses four identical
  characters in a row, fewer than five different characters, six-character alphabet or keyboard runs (forwards or
  backwards), passwords built mostly on a built-in list of common words (after undoing substitutions such as `@` for
  `a` and `0` for `o`), and passwords containing the person's name, username or e-mail name. The list includes the
  university's and the product's names.
- **Where.** `src/Security/Auth.php` (`hash`, `algorithm`, `attempt`), `src/Security/PasswordPolicy.php`.
- **Exercised by.** CI test `tests/features_test.php` §5: a hash starts with `$argon2id$`, five kinds of weak password
  are refused, a passphrase of unrelated words is accepted. The self-test item "Weak passwords are refused"
  (`src/Security/SelfTest.php`), which `tests/readiness_test.php` runs.
- **Limits.** PHP's default Argon2id cost parameters are used and not tuned. The word list is short and lives in the
  code; no breached-password database is consulted. If the PHP build lacks Argon2, SAQF falls back to bcrypt and the
  Security center says so; the CI Docker jobs do not check which one the image uses. Unknown users are given a hash
  check against a dummy hash to level response times; no test measures timing.

#### 4.1.2 Lockout, throttling and the robot check

- **What it does.** After `auth.max_failed_logins` consecutive wrong passwords (default 5) an account is locked for
  `auth.lockout_minutes` (default 15) and `security.account_locked` is written to the audit log. More than
  `auth.ip_max_attempts_15min` failed attempts (default 30) from one network address within 15 minutes are refused and
  raise the IT alert `security.bruteforce`. Every attempt is stored in `login_attempts` with a reason code. When password
  sign-in is restricted to administrators (`SAQF_PASSWORD_LOGIN=admins`), the answer is identical for a right password,
  a wrong one and an unknown user.
  The sign-in and password-reset forms also need a **robot check** (`BotGuard`): a server-signed puzzle (HMAC-SHA-256
  under the application key) tied to the form, valid for 15 minutes and usable once (its hash goes into
  `bot_challenges_used`). The browser must find a number whose SHA-256 hash with the puzzle starts with 15 zero bits
  (19 after three failed attempts from that address in 15 minutes); a hidden field catches simple form-filling bots.
  It is self-hosted and loads nothing from a third party.
- **Where.** `src/Security/Auth.php`, `src/Core/Policy.php` (defaults), `src/Security/BotGuard.php`,
  `public/login.php`, `public/forgot.php`.
- **Exercised by.** CI tests: `tests/http_smoke.php` ("account lockout": five wrong passwords, then the right password is
  refused); `tests/signin_test.php` §1–2 (puzzle signed, expiring, single use, forged difficulty refused, wrong form
  refused, trap field, harder after failures; a correct password without the check is refused and recorded);
  `tests/sso_test.php` §5 (identical answers when password sign-in is restricted).
- **Limits.** No test exercises the per-address throttle or its alert. Behind a reverse proxy it sees the real client
  address only if the proxy is listed in `SAQF_TRUSTED_PROXIES`. Anyone who knows a username can lock that account for the
  lockout period. The "locked" and "disabled" messages differ from the generic one, so they reveal that the account
  exists. Proof of work puts a cost on automation; it does not tell people from programs, it needs JavaScript, and
  `SAQF_BOT_CHECK=off` switches it off (the Security center then says so).

#### 4.1.3 Second step (two-step verification)

- **What it does.** The policy `auth.mfa_required` (default `all`) requires a second step after a correct password for
  everyone who signs in with a password. The choices are an authenticator-app code (TOTP as in RFC 6238: HMAC-SHA-1, six
  digits, 30-second steps, one step of drift either side, a step already used is refused); one of ten single-use recovery
  codes (stored as SHA-256 hashes); or a six-digit code e-mailed to the person (valid 10 minutes, single use, five tries,
  at most five e-mails per sign-in with 30 seconds between them; only an HMAC of the code is kept, in the server-side
  session). Administrators must use the app: no e-mailed codes and no trusted browser. A person may trust a browser for
  `auth.trusted_device_days` (default 30; an HttpOnly, SameSite=Strict cookie holds a random token and only its hash is
  stored); a password change forgets every trusted browser. A pending second step expires after five minutes and five
  wrong codes end it. Until the second step is done the session holds no signed-in user, so nothing else is reachable.
  Authenticator secrets are stored encrypted (4.10).
- **Where.** `src/Security/Mfa.php`, `src/Security/Totp.php`, `src/Security/TrustedDevices.php`,
  `src/Security/Auth.php` (`completeMfa`), `public/mfa.php`, `public/account.php`.
- **Exercised by.** CI tests: `tests/features_test.php` §5 (TOTP against the RFC 6238 test vectors; secret stored
  encrypted; a used code cannot be replayed; a recovery code works once; an administrator's password alone does not sign
  in and nothing is reachable between password and code; a wrong code is refused); `tests/signin_test.php` §3–4 and §7
  (e-mailed code flow, 30-second resend rule, wrong code recorded, trusted browser keeps only a hash, five wrong codes
  restart the sign-in, administrators get no e-mail option and no trust box, the policy switches).
- **Limits.** A wrong second-step code counts toward the per-address throttle but does not lock the account (only wrong
  passwords do), and the five-try counter lives in the PHP session, so starting the sign-in again resets it; each restart
  needs the password and a new robot-check puzzle. The e-mailed code is only as strong as the mailbox and stays available
  as a fallback even for people who have a passkey (4.1.4). A trusted browser is recognised by a browser-family-and-system
  description, not by a device key. TOTP uses SHA-1 because authenticator apps expect it. For university sign-in SAQF
  applies no second step of its own (4.1.5).

#### 4.1.4 Passkeys (WebAuthn)

- **What it does.** An additional way to pass the second step; never a replacement for the password. Registration and
  sign-in verify the ceremony type, a single-use challenge bound to the person and the purpose (five minutes), the exact
  origin and relying-party id, user presence **and** user verification (device PIN or biometric), ES256 (P-256) keys only,
  attestation `none` only, and a signature counter that must move forward when the authenticator uses one. A small strict
  CBOR decoder refuses tags, floats, indefinite lengths, duplicate map keys and deep nesting. Registering and removing a
  passkey need a recent password confirmation (`security.reauth_minutes`); registration and sign-in are rate limited per
  person; at most ten passkeys per person; additions, removals and refusals are audited. Passkeys are offered only over
  HTTPS or on `localhost`, never on an IP-address URL.
- **Where.** `src/Security/WebAuthn.php`, `src/Security/Cbor.php`, `src/Security/Passkeys.php`, `public/passkey.php`,
  `public/assets/passkey.js`.
- **Exercised by.** CI test `tests/passkey_test.php` §1–4 (CI step "Passkeys (WebAuthn) - hostile vectors and the sign-in
  flow"): malformed and truncated CBOR, wrong ceremony type, challenge, origin or relying-party id, missing presence or
  verification flags, wrong key type, a point not on the curve, forged and replayed assertions, a counter that does not
  advance, sign-in over HTTP with replay and five bad attempts, removal by another person refused.
  `tests/e2e/passkeys.mjs` runs the flow in Chromium with a virtual authenticator but is **not** run by CI.
- **Limits.** **This is hand-written code that has not been independently reviewed.** Parsing untrusted CBOR and
  verifying signatures is where subtle flaws hide; `src/Security/WebAuthn.php` and the Security center both say a
  university should have it reviewed, or replace it with a maintained library, before relying on it for administrators.
  Any authenticator is accepted (no attestation trust). A passkey does not remove the weaker option: non-administrators
  with a university e-mail can still choose "E-mail me a code instead" on the second-step page (`public/mfa.php`).
  Administrators must still enrol the authenticator app, and no test covers whether an administrator's passkey would be
  accepted instead of the app code at sign-in (`tests/passkey_test.php` signs in as a faculty account only).

#### 4.1.5 University sign-in (OpenID Connect)

- **What it does.** Authorization-code flow with PKCE (S256). `state` and `nonce` are single use, kept in
  `sso_transactions`, and the attempt is bound to the browser with an HttpOnly, SameSite=Lax cookie (`SAQF_SSO`); a
  transaction lives ten minutes. The ID token is verified: signature against the provider's published keys (RS256, RS384,
  RS512, ES256, ES384 only; `none` and HMAC algorithms are refused; the key set is refreshed once when the key id is
  unknown), issuer, audience (and `azp` when there are several), expiry (60 seconds of clock skew), an issued-at time not
  in the future, and the nonce. The discovery document must name the configured issuer. Production accepts `https://`
  endpoints only. Roles can follow a provider claim (`SAQF_OIDC_ROLE_CLAIM`, `SAQF_OIDC_ROLE_MAP`); disabled accounts are
  refused. `SAQF_PASSWORD_LOGIN=admins` leaves passwords to administrators only (break-glass). With
  `SAQF_OIDC_REQUIRE_MFA=true` SAQF refuses ID tokens whose `amr` claim does not contain `mfa`; the last observation of
  the claim is recorded and shown in the Security center.
- **Where.** `src/Security/Oidc.php`, `src/Security/Jwt.php`, `src/Security/Auth.php` (`ssoUser`), `public/sso.php`.
- **Exercised by.** CI tests: `tests/sso_test.php` (end to end against `tests/mock/idp.php`: PKCE parameters, cookie
  binding, replayed callback, a sign-in started in another browser, forged signature, another audience, cancelled
  sign-in, a person with no SAQF account, role provisioning, restricted password sign-in, sign-out ending the provider
  session); `tests/production_test.php` §11–12 (RS256 and ES256, a modified token, `alg: none`, HS256 algorithm
  confusion, unknown key id, issuer, audience, expiry and nonce checks, role mapping, account linking, disabled
  accounts); `tests/hardening_test.php` §5 (the MFA claim is read, recorded and optionally required).
- **Limits.** Only ever run against the stand-in provider in `tests/mock/idp.php`, never against Microsoft Entra ID,
  Google, Keycloak, ADFS or Okta. **SAQF does not enforce two-step verification for university sign-in**; that is the
  identity provider's policy, which university IT must confirm in writing
  ([`READINESS.md`](READINESS.md), prerequisite 1), and many providers omit the `amr` claim. The first link between a
  university account and an existing SAQF account trusts the provider's username or e-mail claim. SAML-only providers need
  an OIDC bridge.

#### 4.1.6 Password reset and invitations

- **What it does.** Tokens are 256-bit random values, stored only as SHA-256 hashes, single use, valid 30 minutes for a
  reset and 72 hours for an invitation. The request form gives the same answer whatever the account; requests are
  throttled (5 per address per 15 minutes, 3 per account per hour). Links are built from `SAQF_BASE_URL`, never from the
  request's Host header. Completing a reset ends the person's other sessions. The feature is off unless mail is
  configured and password sign-in is not switched off.
- **Where.** `src/Security/PasswordReset.php`, `public/forgot.php`, `public/reset.php`.
- **Exercised by.** CI tests: `tests/production_test.php` §10; `tests/injection_test.php` §5 (a forged Host header never
  appears in a reset link).
- **Limits.** For people who do not use university sign-in, control of the mailbox is control of the account.

### 4.2 Sessions

- **What it does.** PHP sessions named `SAQFSESSID`: cookies only, strict mode (an unknown id is not adopted), HttpOnly,
  `SameSite=Strict`, `Secure` whenever the request is HTTPS (directly or through a trusted proxy), 48-character ids at six
  bits per character. The id is regenerated at the password step of a two-step sign-in, at sign-in, on a password change
  and after a password reset. The idle limit (`session.idle_minutes`, default 30) and absolute limit
  (`session.absolute_hours`, default 8) are measured in real time, never the demo clock, and the session is bound to a
  hash of the browser's User-Agent. Each sign-in is also recorded in `user_sessions` (only a hash of a random token is
  stored), so a person can list their sessions, sign out the others or press "This wasn't me", and an administrator can
  end someone's sessions; a password change or reset ends the others. People see when and from where they last signed
  in, and are notified of a sign-in from a browser and network not seen for 90 days (`security.new_device_alert`).
  Sign-out is POST-only with the CSRF token and sends `Clear-Site-Data: "cache", "storage"`; every page is sent with
  `Cache-Control: no-store`. A page warns before the idle limit.
- **Where.** `src/Core/Session.php`, `src/Security/Sessions.php`, `src/Security/Auth.php` (`login`, `user`, `logout`),
  `docker/php.ini`, `public/logout.php`, `public/ping.php`.
- **Exercised by.** CI tests: `tests/features_test.php` §5 ("Sign out all other sessions" ends the other browser and keeps
  this one; the step-up window); `tests/signin_test.php` §5–6 ("This wasn't me", a password change forgets trusted
  browsers, the last-sign-in notice, the idle warning, the keep-alive needs the token); `tests/hardening_test.php` §8
  (`no-store`, `Clear-Site-Data`); the self-test item "Sessions are protected" (the three PHP session settings, in the web
  process). CI jobs `docker` and `hardened` check the `Secure` flag behind the HTTPS proxy.
- **Limits.** **No test found** that the idle or absolute limit ends a session, for the User-Agent binding
  (`Auth::user()`), for the `SameSite=Strict` attribute of the session cookie, or for the id regeneration at sign-in. A
  User-Agent hash is a weak binding. Behind a university proxy that is not in `SAQF_TRUSTED_PROXIES`, SAQF sees plain HTTP
  and omits the `Secure` flag and HSTS.

### 4.3 CSRF

- **What it does.** A random per-session token (32 random bytes, hex) is required on every state-changing form and API
  call, as the `_csrf` field or the `X-CSRF-Token` header, compared with `hash_equals`. `saqf_require_post()` in
  `public/_init.php` is the shared gate; `login.php`, `mfa.php`, `passkey.php`, `demo.php`, `ping.php` and `api.php` call
  `Csrf::valid()` themselves. Sign-out is POST-only. Cookies are SameSite=Strict as a second layer. The one POST endpoint
  without a token is `public/csp_report.php`, by design (4.5).
- **Where.** `src/Core/Csrf.php`, `public/_init.php`.
- **Exercised by.** CI tests: `tests/http_smoke.php` (an API call without the token is refused; an anonymous API call is
  401); `tests/hardening_test.php` §11 (an incident request without the token registers nothing);
  `tests/passkey_test.php` §4 (403 without the token); `tests/signin_test.php` §6 (the keep-alive without the token is
  refused); `tests/features_test.php` §8 (demo sign-in without the form token is refused); the self-test item "Forged
  requests are rejected".
- **Limits.** No test posts without a token to every state-changing page. A reviewer can check by reading that every page
  that handles a POST reaches one of the two gates (command in section 7). Tokens are per session, not per request.

### 4.4 Response headers and the strict Content-Security-Policy

- **What it does.** `src/bootstrap.php` sends on every web response:
  `Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self'; object-src 'none'; frame-ancestors 'none'; form-action 'self'; base-uri 'self'; report-uri csp_report.php`,
  `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: same-origin`,
  `Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()`,
  `Cross-Origin-Opener-Policy: same-origin`, `Cross-Origin-Resource-Policy: same-origin`, `Cache-Control: no-store` and
  `X-Permitted-Cross-Domain-Policies: none`; and `Strict-Transport-Security: max-age=31536000; includeSubDomains` when the
  request is HTTPS. There are no inline scripts and no inline event-handler attributes in the PHP templates; behaviour is
  attached from the files in `public/assets/` through `data-*` attributes. Evidence downloads add
  `Content-Security-Policy: default-src 'none'; sandbox`. Apache is configured with `ServerTokens Prod`, `ServerSignature
  Off`, `TraceEnable Off`, `Options -Indexes -FollowSymLinks`, no direct access to `_init.php` or to files ending `.sql`,
  `.log`, `.bak`, `.env`, `.md` or `.json` under the document root, and PHP with `expose_php = Off` and
  `display_errors = Off`. A root `.htaccess` hides `src/`, `data/`, `bin/`, `database/`, `tests/`, `docs/`, `docker/`,
  `storage/` and `backups/` for hosts that cannot set the document root to `public/`. The bundled Caddy proxy removes the
  `Server` header and caps request bodies at 32 MB.
- **Where.** `src/bootstrap.php`, `src/Quality/Evidence.php` (`send`), `docker/apache-saqf.conf`, `docker/php.ini`,
  `docker/Caddyfile`, `.htaccess`, `public/assets/`.
- **Exercised by.** CI tests: `tests/features_test.php` §5 (script-src is exactly `'self'`, `object-src 'none'`,
  `frame-ancestors 'none'`; no `<script>` without `src` and no inline `on…=` handler on a sample of pages seen by three
  roles; `Cross-Origin-Opener-Policy` and `X-Frame-Options`); `tests/injection_test.php` §3 (`nosniff` and JSON content
  type on the responses it fetches) and §6 (the `report-uri`); `tests/hardening_test.php` §8 (`Cache-Control: no-store`;
  `Clear-Site-Data` on sign-out). CI jobs `docker` and `hardened` check HSTS and the `Secure` cookie through the proxy.
- **Limits.** `style-src 'unsafe-inline'` is allowed (several templates use inline `style` attributes), so the policy does
  not stop CSS injection. `Referrer-Policy`, `Permissions-Policy`, `Cross-Origin-Resource-Policy` and
  `X-Permitted-Cross-Domain-Policies` are set in code and **no test asserts them**. The inline-script test samples pages;
  the grep in section 7 covers every PHP template. **No test found** requests the Apache-blocked paths. HSTS has no
  `preload`. The Security center's "Browser protections" row is a fixed statement, not a live check.

### 4.5 Browser CSP violation reports

- **What it does.** The policy names `report-uri csp_report.php`. That endpoint takes a POST of at most 8 KiB of JSON with
  no session and no authentication (browsers send reports unprompted), parses it strictly, and keeps only the directive
  name, the blocked address reduced to scheme and host (never a path, query or fragment) and the page path, with markup
  stripped. It is rate limited (20 per network address and 60 in total per hour) and records one entry per distinct
  violation per day as `security.csp_violation` in the audit log. It always answers 204. The Security center counts the
  cases of the last seven days.
- **Where.** `public/csp_report.php`, `src/bootstrap.php`, `src/Security/SecurityCenter.php`.
- **Exercised by.** CI test `tests/injection_test.php` §6 (accepted and recorded once; query strings, tokens and file names
  not kept; the same violation not recorded twice in a day; markup stripped; bad JSON, wrong shape, oversized body and a
  GET answered 204 and not recorded; one address capped; the Security center shows the count).
- **Limits.** Anyone can send a report, which is why it is only a rate-limited log entry and never triggers an action. A
  report shows that something was blocked, not who did it. It uses the older `report-uri` directive.

### 4.6 Authorization, scope, step-up and access review

- **What it does.** Every page declares the roles allowed to open it (`saqf_page([...])`, which calls `Auth::require`) and
  then resolves the record it was asked for through `src/Security/Authz.php`, which applies the scope: faculty see the
  offerings they teach (the coordinator edits while the term is open; a section instructor views and adds results and
  evidence); a Head of Department decides for the owning department's courses and views the courses in their programs; a
  dean sees their college; Quality and leadership have an institution-wide view; the administrator runs the system
  (users, activity log, integrations) and has no course, program or academic-decision pages. A denial answers 403 (404 for
  a record that does not exist), is written as `security.access_denied` and appears under Security events. Decisions with
  academic weight are restricted by role in the server code: specification decisions (`hod_decide`, `qa_decide` in
  `public/api.php`), exception decisions and policy edits (Quality), and accepting the evidence set of a course file (the
  Head of Department who owns the course, or Quality; the instructor cannot accept their own: `Closeout::canReview`).
  Sensitive administrator actions (creating, editing or disabling users, role changes, resetting someone's second step,
  ending sessions, maintenance mode, access-review decisions) need a sign-in or identity confirmation (password and
  authenticator code) within `security.reauth_minutes` (default 15). An **access review** requires an administrator other
  than the person concerned to confirm or remove each account's access every `security.access_review_days` (default 90);
  a role change makes the review due again at once; an overdue review raises an IT alert. Administrators can be limited
  to listed networks (`SAQF_ADMIN_ALLOWED_IPS`).
- **Where.** `src/Security/Authz.php`, `public/_init.php`, `src/Security/Auth.php` (`require`), `public/api.php`,
  `public/admin.php` (the `$sensitive` list), `src/Quality/Closeout.php` (`canReview`), `src/Security/AccessReview.php`.
- **Exercised by.** CI tests: `tests/http_smoke.php` (a list of cross-role and cross-scope page requests that must be
  denied, API changes attempted by a section instructor, another department, the wrong role and an anonymous caller,
  maintenance mode); `tests/features_test.php` §3 (evidence download allowed for the section instructor, refused for
  another department, every download audited) and §5 (the identity-confirmation window; the administrator network limit);
  `tests/closeout_test.php` §3 (neither the instructor nor a section instructor can accept evidence; the Head of
  Department can; both decisions audited); `tests/readiness_test.php` "Access review" (nobody confirms their own access; a
  role change re-opens the review; removal disables the account); `tests/hardening_test.php` §11 (only administrators open
  the incident register).
- **Limits.** Scope is enforced by code in each page and API action, not by a central policy engine or database row-level
  rules, so a page added later must remember to call it; the command in section 7 lists pages that call no guard. Record
  ids are sequential integers, and 404 versus 403 shows whether a record exists. `public/api.php` calls `Auth::user()`
  and does not apply the forced-password-change and forced-second-step-enrolment redirects that `Auth::require()` applies
  to pages. SAQF cannot tell whether role assignments are right; they come from an administrator, a CSV import, the SIS
  feed or the identity provider's role claim, and the access review is the control for that. The security thresholds are
  Quality policies that only the Quality role can edit (section 5).

### 4.7 Input validation, injection and output-encoding probes

- **What it does.** Database access goes through the `Db` wrapper: PDO prepared statements with emulation switched off and
  values passed as parameters. Page output is HTML-escaped (`View::h`); the JSON API is served as `application/json`; the
  strict CSP (4.4) means a missed escape is not an execution path. `Request::int`, `str` and `enum` bound type and length,
  and tab and operation names are matched against allow-lists. CSV exports prefix values that start with `=`, `+`, `-`,
  `@`, a tab or a carriage return with an apostrophe (`Closeout::csv`). The "next" address after sign-in must match a fixed
  pattern. Error pages show a reference code only; the trace goes to `system_errors` for IT, and PHP is set not to put
  argument values into stack traces (`zend.exception_ignore_args`).
- **Where.** `src/Core/Db.php`, `src/Web/View.php`, `src/Core/Request.php`, `src/Core/ErrorLog.php`,
  `src/Quality/Closeout.php`, `public/api.php`, `public/demo.php`.
- **Exercised by.** CI test `tests/injection_test.php` §1–5: hostile text (script and markup, quotes, template syntax) is
  stored through outcome statements, report narratives, evidence titles and file names, incident reports, names, policy
  reasons and passkey names; every page that can show it is then fetched in English and Arabic as several roles and must
  show it escaped; every query parameter of every page is set to the hostile text; SQL meta-characters, including a
  `SLEEP()` probe, are sent as identifiers and filters and must cause no server error, no schema change and no delay;
  redirect targets and response headers are checked for injection. CSV neutralisation: `tests/usability_test.php` §5 and
  `tests/closeout_test.php` §6. `tests/hardening_test.php` §1 checks that student numbers do not reach the web server's
  error output.
- **Limits.** These are probes of known patterns on the pages the test lists. They are not a scanner and not exhaustive.
  "Every query is parameterised" is a code-review claim; no test proves it for each query. The audit-log CSV export in
  `public/admin.php` has its own copy of the formula guard, which no test exercises. The suites drive PHP's built-in web
  server; the Docker job runs only `tests/http_smoke.php` against Apache.

### 4.8 Evidence uploads

- **What it does.** Uploaded course-file evidence is checked and then stored outside the web root under a random
  40-character hexadecimal name in a folder created with mode 0750, files mode 0640 (the container's evidence volume is
  owned by the web server user, mode 700). Checks: extension from a fixed list (PDF, Word, Excel, PowerPoint, PNG, JPEG,
  text; macro-enabled formats are not on it), size up to `evidence.max_mb` (default 20), the content must match the type
  (PDF, PNG and JPEG by signature, text must be valid UTF-8 without NUL bytes, Office files must be a ZIP package that
  contains `[Content_Types].xml` and the matching part and no `vbaProject.bin`), the assessment must belong to the
  course's specification, and uploads are limited to 30 per person per hour. The original name is reduced to a safe base
  name and kept only in the database; the stored MIME type comes from the fixed extension list. A SHA-256 is recorded.
  With `SAQF_CLAMAV_HOST` set, every file is streamed to a ClamAV daemon (`clamd` INSTREAM) before it is stored: an
  infected file is refused with a critical IT alert, and if the scanner is unreachable uploads are refused (fail closed)
  with an IT alert. Upload, download and removal are audited; downloads are checked against the offering's scope and sent
  as attachments with `nosniff` and `default-src 'none'; sandbox`. Removal hides the file and keeps it on disk for the
  audit trail.
- **Where.** `src/Quality/Evidence.php`, `public/workspace.php`, `public/evidence.php`, `docker/entrypoint.sh`,
  `docker/php.ini`, `docker-compose.yml` (`clamav` profile).
- **Exercised by.** CI tests: `tests/features_test.php` §3 (random name outside the web root; SHA-256 recorded; a fake PDF,
  a macro-carrying Word file and a program file refused; with the stand-in scanner a clean file is accepted and the EICAR
  test string refused with a critical alert; with the scanner down uploads pause and IT is alerted; download allowed for
  the section instructor and refused for another department; downloads audited); `tests/injection_test.php` §1 and §5
  (hostile file names; no injected header, markup or line break in the download name); `tests/usability_test.php` §6
  (several files at once; name-based suggestions a person can correct). `tests/e2e/evidence_upload.mjs` runs in a real
  browser but is not run by CI.
- **Limits.** The scanner in the suites is a stand-in (`tests/mock/clamd.php`); the `antivirus` profile is not started by
  CI and signature freshness is not checked by SAQF. Without `SAQF_CLAMAV_HOST`, files are checked by type and content
  only and the Security center says so. The content check is by signature and by names inside the package, not a full
  parse: it does not look for active content in PDFs, external links or embedded objects in Office files, or anything
  hidden in images. Nothing inspects an upload for personal data (the form asks people to remove student names).
  Evidence files are not encrypted at rest by SAQF, and removed files stay on disk (retention is an open policy item,
  [`READINESS.md`](READINESS.md) prerequisite 7).

### 4.9 Keyed pseudonyms for student identifiers

- **What it does.** Student identifiers from every grade source (Moodle, Blackboard, the LMS export folder, the mapped API
  source and the manual gradebook upload) are replaced while the data is read by `S` plus the first 15 hexadecimal
  characters of HMAC-SHA-256 over the source system name and the identifier, keyed with the application key. A key space
  is always required and there is no opt-out (`SAQF_LMS_PSEUDONYMIZE` is ignored). The import refuses a whole batch whose
  keys are digit-only (the shape of a raw student number). No student names are stored. A manual upload is shown as a
  preview that never displays a student number and writes nothing until a person confirms; the preview held in the session
  contains no student number either.
- **Where.** `src/Core/Secrets.php` (`pseudonym`), `src/Integration/Gradebook.php`, `src/Integration/MoodleLmsSource.php`,
  `src/Integration/BlackboardLmsSource.php`, `src/Integration/FileSources.php`, `src/Integration/Mapping.php`,
  `src/Quality/Achievement.php` (`import`, `looksLikeRawId`), `public/workspace.php`.
- **Exercised by.** CI test `tests/hardening_test.php` §1 (a manual upload with raw numbers shows only a preview; nothing is
  written until confirmed; the numbers appear in no table or column of the database, in the audit log, the error log, the
  session files or the web server's error output; a refused file is explained by line and assessment, never by number;
  the export folder pseudonymises even with the old opt-out set; a batch keyed by raw numbers is refused; reading a
  gradebook without a key space is impossible); `tests/mapping_test.php` §4 (mapped LMS grades are pseudonymised on the
  way in); the self-test items "Student identities are pseudonymised" and "Gradebook files never keep student numbers".
- **Limits.** Pseudonymisation is not anonymisation. Anyone who holds the application key and a list of student numbers
  can recompute the pseudonyms. Keys are per source system, so the same student seen through two systems gets two
  different keys. Free text (narratives, evidence titles) and uploaded evidence can still contain names; SAQF does not
  look. Whether this meets the university's privacy obligations is for its privacy office ([`READINESS.md`](READINESS.md)
  prerequisite 7).

### 4.10 Encryption of stored secrets, the application key and secret files

- **What it does.** The application key (`SAQF_APP_KEY`) is the root secret. In production it must come from the environment
  or from a file named by `SAQF_APP_KEY_FILE`; SAQF will not create one, the production installer refuses to run without a
  strong key (at least 32 characters, at least 10 different characters, no placeholder wording), `/health.php` answers 503
  when none exists, and `bin/app_key.php` generates one (`generate`), reports status without printing it (`status`) and
  moves a key stored in the database by an older version out of it (`export-stored`, `forget-stored`). The same key, with
  fixed labels, drives AES-256-GCM encryption of stored authenticator secrets (encryption key = HMAC-SHA-256 of the
  application key with the label `saqf:encryption:v1`; random 12-byte IV; 16-byte tag; a modified value fails to
  decrypt), the pseudonyms (4.9), the robot-check signature, the e-mailed-code HMAC and the passkey user handle. Other
  secrets (database password, SSO client secret, SIS and LMS tokens, mail password, backup passphrase, alert webhook) can
  be supplied as `<NAME>_FILE` paths (only the names in `Config::SECRET_KEYS`; small regular files only; an explicit
  environment value wins), which the production overlay uses so that secret values do not appear in `docker inspect`
  output. Reset tokens, session tokens, trusted-browser tokens and recovery codes are stored only as hashes. `.env`,
  `secrets/`, `config.local.php`, backups and evidence are in `.gitignore` and `.dockerignore`, and the image build
  deletes `config.local.php` and `.env`.
- **Where.** `src/Core/Secrets.php`, `src/Core/Config.php`, `bin/app_key.php`, `bin/make_secrets.sh`,
  `docker-compose.prod.yml`, `Dockerfile`, `.gitignore`, `.dockerignore`.
- **Exercised by.** CI tests: `tests/features_test.php` §5 (authenticator secrets stored encrypted and round-tripping);
  `tests/hardening_test.php` §2 (key from the environment; weak, short and placeholder keys refused; production without a
  key refuses to create one and the installer refuses to start; health answers 503; `bin/app_key.php` never prints the
  key) and §8 (`*_FILE` secrets, an explicit value wins, only the named settings accept a file, an oversized file is
  ignored); the self-test item "Stored secrets are encrypted and modifications detected" (flips one bit and expects a
  refusal). CI job `hardened`: secret files, `/health.php` says the key is `ok`, no secret value in `docker inspect` of
  the app and db containers.
- **Limits.** Rotating the application key is not supported in place: a new key changes every pseudonym and makes every
  stored authenticator secret unreadable ([`OPERATIONS.md`](OPERATIONS.md#application-key-saqf_app_key)). There is no vault
  or HSM integration; the key sits in the process environment or in a mounted file the web server user can read.
  `bin/make_secrets.sh` creates secret files with mode 444 inside a mode-700 folder so the container's web server user
  can read them. SAQF does not encrypt the database or the evidence files at rest; volume or host encryption is IT's. The
  demo compose file falls back to placeholder database passwords when `.env` is not edited.

### 4.11 Audit log: hash chain, append-only triggers and external witnesses

- **What it does.** Every audited action (sign-ins, denials, changes, decisions, downloads, policy edits, automatic
  actions attributed to "SAQF automation" or the integration) is a row in `audit_log` with the time, actor, action,
  object, summary, old and new values, reason, network address and request id. Each row carries
  `SHA-256(previous hash | canonical row)`; writers are serialised with a locking read of the last row so the chain stays
  linear. `Audit::verify()` recomputes the whole chain; the scheduler does so every night and raises a critical IT alert
  if it fails; `php bin/verify_audit.php` does it on demand (exit code 2 when the chain is broken). Two database triggers
  refuse `UPDATE` and `DELETE` on `audit_log` through SAQF's own database account (`bin/install.php`, or
  `database/audit_guard.sql` for a DBA); the Security center and the preflight read from the database whether they exist.
  **Witnesses** (`Witness`): each night SAQF first re-checks the stored witnesses (a critical alert if history differs),
  then takes a new checkpoint line `SAQF-WITNESS/1 <instance> <UTC time> id=<last id> entries=<count> sha256=<hash>` and
  sends it outside the server by e-mail (administrators and `SAQF_WITNESS_EMAIL`) and to the alert webhook. Anyone holding a
  line can later check it with `php bin/verify_audit.php --witness "<line>"` or on Administration → Activity log. The
  Activity log page also exports CSV (the newest 20,000 entries).
- **Where.** `src/Core/Audit.php`, `database/audit_guard.sql`, `bin/install.php`, `bin/verify_audit.php`,
  `src/Security/Witness.php`, `src/Quality/Scheduler.php`, `src/Security/SecurityCenter.php`, `public/admin.php`.
- **Exercised by.** CI tests: `tests/automation_test.php` ("Audit trail integrity": the database refuses `UPDATE` and
  `DELETE`; a forged row inserted behind SAQF's back is detected; the chain verifies again after it is removed);
  `tests/hardening_test.php` §12 (a witness line is produced and verifies; a wrong hash, a wrong entry count, a missing
  entry and a non-witness text are detected; a database administrator who drops the triggers and rewrites history from one
  entry on, recomputing every hash, **passes the ordinary chain check but is caught by a witness taken earlier**, by the
  stored witnesses, by the nightly job's critical alert and by the command line's exit code 2);
  `tests/http_smoke.php` (the chain is verified from the administration console); the self-test item "Audit log edits are
  refused by the database"; CI job `docker` (`php bin/verify_audit.php` after a real restore).
- **Limits.** **Tamper-evident, not tamper-proof.** The chain is plain SHA-256, not keyed: anyone with write access to the
  table can recompute it, which is the case `tests/hardening_test.php` §12 demonstrates. The triggers are created through
  the same database account the application uses (the compose files use one account for the app, the installer and the
  backup), so that account can also drop them; a database administrator can too. A witness proves history only up to its
  checkpoint, only if the message is actually kept by IT, and SAQF cannot see whether it is; with no e-mail or webhook
  configured the witness stays on the server and the Security center says it proves nothing. The stored copies of the
  witnesses live in the same database. Entries made after the last witness are protected only by the chain. The CSV
  export is capped, so it is not a complete off-server copy of a long log.

### 4.12 Security incident register

- **What it does.** Administrators register an incident (kind, severity, when it was detected, what happened, whether
  personal data was involved). For a personal-data incident SAQF starts a notification clock of `Incidents::NOTIFY_HOURS`
  (72) from the detection time, raises an IT alert when 24 hours or less remain and a critical alert when it has passed,
  and shows the deadline. Recording that an authority or affected people were notified requires saying who did it and how;
  closing an incident requires saying how it ended, and a personal-data incident closed with no recorded notification
  needs a longer explanation; a closed incident cannot be changed; every step is in the incident timeline and the audit
  log. **SAQF never decides whether a notification is needed and never contacts any authority or person.** The 72-hour
  figure is a setting taken from sources the authors reviewed; whether and when it applies to an incident is for the
  university's data protection officer and legal counsel.
- **Where.** `src/Security/Incidents.php`, `public/incidents.php`, `src/Quality/Scheduler.php` (`Incidents::watch`).
- **Exercised by.** CI test `tests/hardening_test.php` §11 (the page states that decisions stay with people; no other role
  can open it; a request without the token registers nothing; a 72-hour clock starts for a personal-data incident; the
  critical alert past the window; a notification without who and how is refused; closing requires an explanation; a
  closed incident is immutable; an incident without personal data starts no clock).
- **Limits.** Incidents are created only by a person; nothing opens one automatically (IT alerts are separate). There is
  no connection to any authority's system, and the e-mail and webhook delivery of the alerts is not part of that test.

### 4.13 Backups with encryption, and the restore drill

- **What it does.** `docker/backup.sh` (the compose service `backup`, nightly by default, or from cron) dumps the database
  (`mysqldump --single-transaction --routines --triggers`) and archives the evidence folder. When
  `SAQF_BACKUP_PASSPHRASE` is set each archive is encrypted (`openssl enc -aes-256-cbc -pbkdf2 -iter 200000 -salt`). Each
  archive is read back (decrypted and tested with `gzip -t`) and given a SHA-256 file; with `SAQF_BACKUP_OFFSITE_PATH` a
  second copy is written and its checksum re-checked there; files older than `SAQF_BACKUP_KEEP_DAYS` (default 14) are
  removed. The run writes `last-backup.json` with what it actually observed (status, encrypted and decrypted again,
  evidence files included, second copy checked), and records the audit-chain head (`.audithead`). A failed evidence
  archive or second copy marks the whole run failed. Files are written owner-only. SAQF judges backups only from that
  recorded status: no status file means "SAQF cannot confirm any backup exists", in production a backup that is not
  encrypted or has no verified second copy is not reported healthy, and the line always says a restore has not been tested
  from SAQF. `docker/restore.sh` checks the checksum, decrypts and restores the database and the evidence files of the
  same timestamp; then run `php bin/verify_audit.php`. The **restore drill** `docker/restore_drill.sh` (monthly from the
  backup loop by default, `SAQF_DRILL_EVERY_DAYS`, or on demand) decrypts the newest backup, loads it into a throw-away
  MySQL server with its own data folder and no network inside the backup container, and checks that the dump loads, that
  tables and audit entries exist, that the append-only triggers exist, that the audit-chain head recorded at backup time is
  present, and that every current evidence row has its file in the evidence archive. It writes `last-restore-drill.json`
  for the Security center and the preflight.
- **Where.** `docker/backup.sh`, `docker/restore.sh`, `docker/restore_drill.sh`, `docker-compose.yml` (`backup` service),
  `src/Security/SecurityCenter.php` (`backupOk`, `backupLine`, `restoreDrill`).
- **Exercised by.** CI test `tests/hardening_test.php` §4 (status judged from recorded runs; real `docker/backup.sh` runs:
  both archives encrypted and copied; a failed evidence archive recorded as FAILED) and §10 (the drill; a wrong
  passphrase fails; a backup altered after it was written is refused by its checksum). Both skip, and say so, when
  `mysqldump`, `mysqld`, `mysql` or `openssl` are missing on the machine. CI job `docker` (encrypted backup, status
  `ok` and `encrypted`, drill with `audit_head` matching, a real restore then `verify_audit.php`) and CI job `hardened`
  (passphrase supplied as a secret file; the preflight then sees the drill).
- **Limits.** `openssl enc` with AES-256-CBC gives confidentiality but no authentication. Integrity rests on decrypting
  and testing the archive and on the `.sha256` file stored beside it, so someone who can write the backup folder could
  replace both. SAQF can verify the second copy by checksum but cannot tell whether it is on another machine. Without a
  passphrase the backups are plain, and the status says so. The drill proves a restore into a scratch MySQL server on the
  same image; it does not prove recovery time, rebuilding a host, or the university's own copies. CI exercises a
  throw-away demo stack, so a restore drill on the university's infrastructure is still to be done and recorded
  ([`READINESS.md`](READINESS.md) prerequisite 5). Backups contain every record, including personal data and password
  hashes; keeping the passphrase and the application key safe and separate is the operator's work.

### 4.14 Hardened container deployment, HTTPS and outbound connections

- **What it does.** The `Dockerfile` builds on `php:8.3-apache`; Apache serves only `public/` (`APACHE_DOCUMENT_ROOT`); the
  code is owned by root and not writable by the web server; the build deletes `config.local.php` and `.env`. The database
  is not published to the host. `docker-compose.prod.yml` adds: secrets as files; production mode with demo off; a
  read-only root file system with `tmpfs` for `/tmp` and Apache's runtime folders; `no-new-privileges`; all capabilities
  dropped and only `CHOWN`, `DAC_OVERRIDE`, `FOWNER`, `SETUID`, `SETGID` and `NET_BIND_SERVICE` added back for the app
  (the same list less `NET_BIND_SERVICE` for the database; only `NET_BIND_SERVICE` for the proxy, which is also
  read-only); process, memory and log-size limits; and the app port bound to `127.0.0.1` so that only the HTTPS proxy is
  reachable from outside. The scheduler loop runs as `www-data` (`setpriv` in `docker/entrypoint.sh`). The container health
  check calls `/health.php`, which answers without a session and reports status, version, database, scheduler,
  maintenance and the application-key state (never the key). The `https` profile adds Caddy (automatic certificates for a
  public `SAQF_DOMAIN`, a local certificate authority for `localhost`, HTTP redirected to HTTPS). SAQF trusts the
  forwarded client address and protocol only from `SAQF_TRUSTED_PROXIES` (the compose network gives the proxy the fixed
  address `172.28.250.10`). Outbound requests (SIS, LMS, identity provider and its discovery endpoints, alert webhook) go
  through one client that verifies TLS certificates, does not follow redirects, caps an answer at 32 MiB and, in
  production, accepts `https://` only; the SMTP client verifies the peer and requires TLS 1.2 or later when TLS is on.
  `php bin/preflight.php` judges an installation against the production rules in any mode: blockers (for example demo
  accounts still using the published password, a base address that is not https, a missing or weak application key, plain
  http outbound addresses, missing audit triggers or a broken chain, pending migrations, administrators without a second
  step, a backup that is not complete, verified, encrypted and copied, an evidence folder that is not outside the web root)
  and warnings. Exit code 0 means no blockers, 1 at least one blocker, 2 the database could not be read. It never prints a
  secret.
- **Where.** `Dockerfile`, `docker-compose.yml`, `docker-compose.prod.yml`, `docker/entrypoint.sh`,
  `docker/apache-saqf.conf`, `docker/php.ini`, `docker/Caddyfile`, `bin/make_secrets.sh`, `bin/preflight.php`,
  `src/Security/Preflight.php`, `src/Integration/Http.php`, `src/Core/Smtp.php`, `src/Core/Request.php`,
  `public/health.php`.
- **Exercised by.** CI job `hardened` (as listed in section 2); CI job `docker` (HTTPS through the proxy: HSTS, `Secure`
  cookie, redirect). CI tests: `tests/hardening_test.php` §3 (https-only outbound in production, plain http still allowed
  in local mode, the Security center names each plain-http setting) and §9 (a demo installation is reported NOT READY with
  exit code 1; demo accounts are named; the report never prints the key or the demo password);
  `tests/features_test.php` §5 (the forwarded address is honoured only from the listed proxy) and §8 (demo shortcuts are
  404 in production).
- **Limits.** `docker-compose.yml`, which a plain `docker compose up` uses, is the demo stack: ports on all interfaces,
  demo mode, placeholder database passwords, no capability drop. Only the production overlay hardens, and the `hardened`
  job inspects the `app` container only; the database and backup containers' settings are not checked by CI, and the
  backup service has `no-new-privileges` but no capability drop and no read-only root. `DAC_OVERRIDE`, `SETUID` and
  `SETGID` are broad capabilities kept because Apache and MySQL need them. Images are referenced by tag (`php:8.3-apache`,
  `mysql:8.0`, `caddy:2`, `clamav/clamav:stable`), not by digest. There is no web application firewall and no network
  segmentation beyond Compose's default network; the host, firewall and Docker daemon are IT's. A university proxy must
  be added to `SAQF_TRUSTED_PROXIES`, otherwise every client appears to come from the proxy. `/health.php` is
  unauthenticated and shows the version.

### 4.15 Supply chain

- **What it does.** The repository has no package manifests (no `composer.json`, `package.json` or `requirements.txt`).
  The PHP application uses only PHP's own extensions (`pdo_mysql`, `mbstring`, `curl`, `openssl`); the browser code is
  local files in `public/assets/` with no external addresses, fonts or CDN; Word and ZIP files, QR codes, TOTP, ID-token
  verification and the SMTP client are written in this repository. What remains third party: PHP, Apache, MySQL, Caddy and
  ClamAV (container images) and, in CI only, GitHub Actions and Trivy. `.github/dependabot.yml` asks Dependabot for weekly
  updates of GitHub Actions and of the Docker ecosystem at the repository root (the `Dockerfile` base image). The CI
  `scan` job runs Trivy over the working tree (secrets and misconfigurations, HIGH and CRITICAL) and over the built image
  (known vulnerabilities, HIGH and CRITICAL, unfixed ones ignored). It is `continue-on-error: true` and every command ends
  in `|| true`, so it informs a person and never blocks a release.
- **Where.** `.github/dependabot.yml`, `.github/workflows/ci.yml` (`scan`), `Dockerfile`, `TECH_STACK.md`.
- **Exercised by.** CI job `scan` (informational) and the Dependabot configuration. The "no manifests" and "no external
  addresses" statements are checked with the commands in section 7.
- **Limits.** The scan is informational and its image, `aquasec/trivy:latest`, is itself unpinned; it scans the working
  tree, not the git history. `.github/dependabot.yml` lists only the `github-actions` and `docker` ecosystems, so images
  that appear only in compose files (`mysql:8.0`, `caddy:2`, `clamav/clamav:stable`) are not in its configuration. GitHub
  Actions are referenced by version tag (`@v4`, `@v2`), not by commit, and the workflow has no `permissions:` block. Having
  no third-party libraries removes dependency-update exposure but moves the review burden onto this project's own code,
  which nobody outside the project has reviewed (section 6).

### 4.16 Security center, self-test, evidence report, preflight and IT alerts

- **What it does.** Administration → Security center lists each control with its recorded status and how to fix what needs
  attention (optional controls that are not configured are shown as such). **Run security self-test** (also run every
  night; a failure raises a critical IT alert) exercises: weak passwords refused, the audit chain verifying, the database
  refusing an edit to the audit log, forged CSRF tokens refused, the authenticator-code generator against the RFC 6238
  reference value, AES-256-GCM round trip and bit flip, pseudonyms stable and not containing the identifier, the gradebook
  reader pseudonymising, and (in the web process only) the session settings. The **security evidence report**
  (`public/security_report.php`, administrators only) is a printable self-assessment read from the configuration and
  recorded results; it lists what it does not cover and carries a fingerprint that is also entered in the audit log. The
  preflight is described in 4.14. **IT alerts** are raised and resolved for failing connectors, a stalled scheduler,
  missing or failed backups, a broken audit chain, a witness mismatch, a failed self-test, blocked malware, an unreachable
  scanner, password guessing, an overdue access review and incident deadlines; they reach administrators in SAQF, by
  e-mail and, with `SAQF_ALERT_WEBHOOK`, to a Teams or Slack channel.
- **Where.** `src/Security/SecurityCenter.php`, `src/Security/SelfTest.php`, `src/Security/Preflight.php`,
  `src/Core/Alerts.php`, `src/Quality/Scheduler.php`, `public/security_report.php`, `public/admin.php`.
- **Exercised by.** CI tests: `tests/readiness_test.php` ("Security self-test" and the report page);
  `tests/hardening_test.php` §6 (the Security center and the report state facts and call themselves a self-test and a
  self-assessment, with no fixed assurance about virus scanning or off-site backups) and §9; `tests/signin_test.php` §1
  (the Security center shows the robot check and the second-step policy).
- **Limits.** These are checks made by the same code base they check: a flaw in a control can also be a flaw in its check.
  The self-test exercises a handful of protections only. A few Security center rows ("Browser protections", "Student
  privacy", "Incident register") are fixed statements, not live checks. The self-test and the report say they are not a
  penetration test, and neither is one.

## 5. Settings that change security behaviour

Set in `.env`, the process environment or `config.local.php`. Settings marked *file* also accept a `<NAME>_FILE` path
(`Config::SECRET_KEYS` in `src/Core/Config.php`).

| Setting | Effect | Read in |
|---|---|---|
| `APP_ENV`, `APP_DEBUG`, `SAQF_DEMO` | Production mode turns off demo sign-in, the tour and the simulator; debug off hides error detail | `src/Core/Config.php` |
| `SAQF_APP_KEY` (*file*) | The application key (4.10) | `src/Core/Secrets.php` |
| `SAQF_BASE_URL` | Public address: reset links, passkey origin, SSO redirect, https check | `src/Core/Mailer.php`, `src/Security/Passkeys.php`, `src/Security/Oidc.php` |
| `SAQF_TRUSTED_PROXIES`, `SAQF_TRUST_PROXY` | Which proxies may tell SAQF the client address and protocol | `src/Core/Request.php` |
| `SAQF_PASSWORD_LOGIN` | `all`, `admins` or `off` | `src/Security/Auth.php` |
| `SAQF_ADMIN_ALLOWED_IPS` | Networks from which administrators may sign in | `src/Security/Auth.php` |
| `SAQF_BOT_CHECK` | `off` removes the robot check | `src/Security/BotGuard.php` |
| `SAQF_OIDC_*` (the client secret is *file*), `SAQF_OIDC_REQUIRE_MFA` | University sign-in; refuse tokens that do not report MFA | `src/Security/Oidc.php` |
| `SAQF_CLAMAV_HOST` | Scan uploads with ClamAV, fail closed | `src/Quality/Evidence.php` |
| `SAQF_ALERT_WEBHOOK` (*file*), `SAQF_WITNESS_EMAIL`, `SAQF_MAIL_*` (the password is *file*) | Where alerts and audit-chain witnesses are sent | `src/Core/Alerts.php`, `src/Security/Witness.php`, `src/Core/Mailer.php` |
| `SAQF_SECURITY_CONTACT` | Published at `/.well-known/security.txt` when it is a `mailto:` or `https://` address | `public/security_txt.php` |
| `SAQF_BACKUP_PASSPHRASE` (*file*), `SAQF_BACKUP_OFFSITE_PATH`, `SAQF_BACKUP_KEEP_DAYS`, `SAQF_DRILL_EVERY_DAYS` | Backup encryption, second copy, retention, drill interval | `docker/backup.sh`, `docker-compose.yml` |
| `SAQF_STORAGE_DIR` | Evidence folder (must be outside `public/`) | `src/Quality/Evidence.php` |

**Policies** (Quality policies page, with an audited reason): `auth.mfa_required`, `auth.max_failed_logins`,
`auth.lockout_minutes`, `auth.ip_max_attempts_15min`, `auth.min_password_length`, `auth.trusted_device_days`,
`session.idle_minutes`, `session.absolute_hours`, `security.reauth_minutes`, `security.new_device_alert`,
`security.access_review_days`, `auth.dormant_days` and `evidence.max_mb` (defaults in `src/Core/Policy.php`). Only the
Quality role can change them (`public/policies.php`, `policy_set` in `public/api.php`); administrators and Heads of
Department see them read-only. `Policy::set` checks types and ranges but not security sense: Quality could set
`auth.mfa_required` to `off`, and the Security center would then show it as needing attention, nothing more.

## 6. What this does not prove

- **No penetration test.** Nobody outside the project has tried to attack SAQF. The self-test, the security evidence
  report and `tests/injection_test.php` are automated checks the project wrote against itself; the injection suite
  probes known patterns and says so in its own header.
- **No independent code review.** No part of the code has been reviewed by someone outside the project.
- **Hand-written passkey code.** `src/Security/WebAuthn.php` and `src/Security/Cbor.php` implement WebAuthn verification
  and CBOR parsing by hand and have not been independently reviewed. Review them, or replace them with a maintained
  library, before relying on passkeys for administrators.
- **Other hand-written protocol code.** For the same "no third-party libraries" reason, the OpenID Connect client, ID-token
  verification, TOTP, SMTP client and the Word, ZIP and QR writers are also this project's own. Only TOTP is tested against
  the standard's published vectors; the rest are tested against what the project's authors thought to test and, for
  sign-in, a stand-in provider.
- **Tamper-evident, not tamper-proof.** The audit log reveals edits and deletions when it is verified. It does not
  prevent them, the chain is unkeyed, and a database administrator can drop the triggers and rewrite history; only
  witness messages that IT keeps outside the server can show that (4.11).
- **No certification and no conformity assessment.** SAQF has not been assessed against any security or privacy standard
  or regulation, for example ISO/IEC 27001 or the Saudi Personal Data Protection Law. The incident register supports the
  university's own process; it makes no statement about the law. No NCAAA report is approved by anything in SAQF.
- **Nothing has run against the university's real systems.** The identity provider, Edugate, the LMS and the mail server
  are simulated by stand-in servers in `tests/mock/`, and the demo uses a snapshot of Yamamah University's public study
  plans with fictional people and synthetic pseudonymous results. Real data, real formats and real scale have not been
  exercised ([`INTEGRATIONS.md`](INTEGRATIONS.md#edugate-and-lms-integration-contract-awaiting-university-it)).
- **No load, availability or denial-of-service testing.** Rate limits exist on specific actions (uploads, exports,
  imports, passkeys, reports, sign-in); nothing measures how the system behaves under volume.
- **Real browsers and real scanners are mostly outside CI.** The browser tests in `tests/e2e/` are not run by CI; ClamAV
  is represented by a stand-in; the CSP is checked by reading headers, not by a browser enforcing it.
- **The surroundings are out of scope.** Operating-system patching, firewall, Docker daemon and database-server hardening,
  disk and volume encryption, where the second backup copy physically lives, the identity provider's MFA policy and the
  university's malware-scanning policy are IT's and are not demonstrated here
  ([`READINESS.md`](READINESS.md) lists the open prerequisites and who owns them).
- **A passing test shows a case that was tried.** It does not show that no other case fails.

## 7. How to reproduce

Run these on a machine with PHP 8.3 (`pdo_mysql`, `mbstring`, `curl`, `openssl`) and MySQL 8.0 (set
`log_bin_trust_function_creators=1` so the audit triggers can be created, as in the CI workflow). **Each suite reinstalls
the demo into the database named by `SAQF_DB_NAME`; use a throw-away database, never one with data you want to keep.**

```bash
export SAQF_DB_HOST=127.0.0.1 SAQF_DB_PORT=3306 SAQF_DB_NAME=saqf_scratch SAQF_DB_USER=root SAQF_DB_PASS=…

# Every suite, plus the PHP lint and the migration re-run, with a per-suite summary
sh bin/test_all.sh

# One suite (each starts its own web server where it needs one)
php bin/install.php --demo --fresh && php tests/injection_test.php
php bin/install.php --demo --fresh && php tests/passkey_test.php

# The HTTP and authorization suite needs a running server
php bin/install.php --demo --fresh
php -S 127.0.0.1:8080 -t public &
php tests/http_smoke.php http://127.0.0.1:8080
```

| Control | Suite(s) to run |
|---|---|
| Passwords, second step, sessions, evidence, secrets, headers | `tests/features_test.php`, `tests/signin_test.php`, `tests/hardening_test.php` |
| Authorization and CSRF | `tests/http_smoke.php`, `tests/closeout_test.php` |
| Passkeys | `tests/passkey_test.php` |
| University sign-in | `tests/sso_test.php`, `tests/production_test.php` |
| Injection probes and CSP reports | `tests/injection_test.php` |
| Audit log, witnesses, incident register, key handling, backups, drill, preflight | `tests/automation_test.php`, `tests/hardening_test.php` |
| Self-test, access review | `tests/readiness_test.php` |

**Docker jobs** (as in `.github/workflows/ci.yml`; use a scratch checkout, because the second block overwrites `.env`):

```bash
# docker job: demo stack with HTTPS, encrypted backup, restore drill
export SAQF_BACKUP_PASSPHRASE=a-long-passphrase-for-this-test SAQF_BACKUP_START_DELAY=99999
docker compose --profile https up -d --build
docker compose exec -T app php tests/http_smoke.php http://127.0.0.1
curl -skI https://localhost/login.php                       # look for strict-transport-security and Secure
docker compose exec -T backup sh /usr/local/bin/saqf-backup
docker compose exec -T app cat storage/backups/last-backup.json
docker compose exec -T backup sh /usr/local/bin/saqf-restore-drill
docker compose exec -T app cat storage/backups/last-restore-drill.json

# hardened job: production overlay with secret files
sh bin/make_secrets.sh
printf 'APP_ENV=production\nSAQF_DEMO=false\nSAQF_AUTO_INSTALL=production\nSAQF_BASE_URL=https://localhost\nSAQF_DOMAIN=localhost\nSAQF_BACKUP_START_DELAY=99999\n' > .env
docker compose -f docker-compose.yml -f docker-compose.prod.yml --profile https up -d --build
curl -fsS http://127.0.0.1:8080/health.php
docker inspect -f '{{.HostConfig.ReadonlyRootfs}} {{.HostConfig.SecurityOpt}} {{.HostConfig.CapDrop}}' "$(docker compose ps -q app)"
docker compose exec -T app php bin/preflight.php
```

**Operating checks** on an installed system:

```bash
php bin/preflight.php            # needs the database; exit 0 = no blockers, 1 = a blocker, 2 = database unreadable
php bin/preflight.php --json
php bin/verify_audit.php         # exit 2 = chain broken
php bin/verify_audit.php --witness "SAQF-WITNESS/1 <instance> <time> id=… entries=… sha256=…"
php bin/app_key.php status       # never prints the key
curl -sI http://127.0.0.1:8080/login.php    # response headers (section 4.4)
```

Administration → Security center → **Run security self-test** runs the self-test; the scheduler runs it nightly.

**Repository checks** that need no database (the expected output is given where it was observed when this document was
written; re-run them after any change):

```bash
# No dependency manifests (expect no output)
find . \( -name composer.json -o -name package.json -o -name requirements.txt -o -name Gemfile -o -name go.mod \) -not -path './.git/*'

# No inline scripts and no inline event-handler attributes in the page templates (expect no output from either)
grep -rnP '<script\b(?![^>]*\bsrc=)' public src --include=*.php | grep -v 'src/Web/lang'
grep -rnE '\son(click|load|error|submit|change|input)=' public src --include=*.php | grep -v 'src/Web/lang'

# Pages that call no sign-in guard (expected: csp_report, health, logout, reset, security_txt, sso, tour)
for f in public/*.php; do grep -qE 'saqf_page\(|Auth::require\(|Auth::user\(\)' "$f" || echo "$f"; done

# Pages that handle a POST without reaching a CSRF gate (expected: csp_report.php only)
grep -LE 'saqf_require_post|Csrf::valid' $(grep -lE 'REQUEST_METHOD|isPost\(\)|\$_POST' public/*.php)

# PHP syntax of every file, as the CI lint step does
find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l > /dev/null

# The informational image and configuration scan, as the scan job does
docker run --rm -v "$PWD:/src:ro" aquasec/trivy:latest fs --scanners secret,misconfig --severity HIGH,CRITICAL --skip-dirs /src/.git /src
```

A CSP report can be sent by hand to a demo installation to see it recorded (and then look for `security.csp_violation`
under Administration → Activity log):

```bash
curl -si -X POST -H 'Content-Type: application/csp-report' \
  -d '{"csp-report":{"document-uri":"http://127.0.0.1:8080/x.php","effective-directive":"script-src-elem","blocked-uri":"https://evil.example/a.js"}}' \
  http://127.0.0.1:8080/csp_report.php
```

The real-browser tests (`tests/e2e/passkeys.mjs`, `tests/e2e/evidence_upload.mjs`) need Playwright and a demo server
started with `SAQF_BOT_CHECK=off`; their header comments give the exact command.

## 8. Related documents

- [`SECURITY.md`](../SECURITY.md): how to report a vulnerability, scope, supported versions, secrets in git.
- [`READINESS.md`](READINESS.md): demo versus controlled pilot versus live system, and the open prerequisites with their owners.
- [`OPERATIONS.md`](OPERATIONS.md): the operations runbook, restore, application key, incident checklist and the go-live
  hardening checklist ([hardening checklist](OPERATIONS.md#hardening-checklist-before-go-live-production)).
- [`INTEGRATIONS.md`](INTEGRATIONS.md): connecting the university's systems, SSO setup, and the
  [Edugate and LMS integration contract](INTEGRATIONS.md#edugate-and-lms-integration-contract-awaiting-university-it).
- [`ARCHITECTURE.md`](ARCHITECTURE.md): the design, including the security model summary.
- [`TEST_CASES.md`](TEST_CASES.md): plain-language steps to see each feature working.
- [`TECH_STACK.md`](../TECH_STACK.md): what the repository and its Docker and CI configuration use.
