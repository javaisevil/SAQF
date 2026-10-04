# SAQF deployment guide

For the university IT team that will host SAQF. It goes from an empty Linux server to a monitored installation and ends with a go-live checklist in which every row can be proven with one command or one screen.

> **Read this first**
>
> - SAQF is a Docker-deployable demo with an integration-ready handoff. This guide describes the production configuration the repository ships and how to run it. It does not turn the demo into an approved or independently reviewed system. The open prerequisites (identity-provider MFA confirmed in writing, an approved Edugate and LMS contract, retention and privacy policy, an independent security review, a department pilot) are listed in [READINESS.md](READINESS.md).
> - Nothing has been connected to YU's real Edugate, LMS or identity provider. The connectors have been exercised against local stand-in servers (`tests/mock/`) only, and the sample integrations are simulated. The bundled data is a snapshot of YU's public study plans (`data/yu/SOURCES.md`) plus fictional people and synthetic, pseudonymous results.
> - The preflight and the security self-test are self-checks. Neither is a penetration test or an independent review. The passkey code (`src/Security/WebAuthn.php`, `src/Security/Cbor.php`) is hand-written and has not been independently reviewed. The audit log is tamper-evident, not tamper-proof.
> - There is no AI or LLM API inside the product, and nothing in this guide needs a paid service. The stack is PHP, MySQL, Apache, Caddy and, optionally, ClamAV ([TECH_STACK.md](../TECH_STACK.md)).
> - Every file and command named below exists in this repository. Where a step is not covered by a test, the guide says so (section 14 collects those gaps).

## Order of work

| Step | Section |
|---|---|
| Prepare the host, the DNS name and a second location for backups | [2. Prerequisites](#2-prerequisites) |
| Fetch a pinned version and create the secret files | [3. Get the code](#3-get-the-code-and-pin-a-version), [4. Secrets](#4-secrets) |
| Write the settings and start the stack | [5. Configure](#5-configure), [7. First start](#7-first-start-and-the-first-administrator) |
| Run the preflight, fix blockers | [8. Preflight and the Go-live tab](#8-preflight-and-the-go-live-tab) |
| Connect SIS, LMS, sign-in and e-mail (on staging data first) | [9. Connect the university's systems](#9-connect-the-universitys-systems) |
| Prove backups and restores | [10. Backups, off-site copy and the restore drill](#10-backups-off-site-copy-and-the-restore-drill) |
| Wire monitoring | [12. Monitoring](#12-monitoring) |
| Sign off | [15. Go-live checklist](#15-go-live-checklist) |

## Conventions

All commands run in the repository folder on the Docker host. Set this once in every shell you use for SAQF:

```sh
export DC="docker compose -f docker-compose.yml -f docker-compose.prod.yml --profile https"
```

`$DC` is the exact form the CI job `hardened` uses (`.github/workflows/ci.yml`). If you add a local override file (section 5.3), append `-f docker-compose.override.yml`; if you run ClamAV, append `--profile antivirus`. Placeholder names such as `saqf.example.edu` and `/mnt/saqf-offsite` stand for your own.

## 1. What you will run

```
Browser --443--> proxy (Caddy) --> app (Apache + PHP 8.3) --> db (MySQL 8.0)
                                    |  scheduler loop (bin/tick.php)   ^
            host only: 127.0.0.1:8080                                    |
                                                  backup (docker/backup.sh --loop)
                                                  ./backups, off-site path, evidence volume
```

| Service | Image | Reachable from | Holds | Role |
|---|---|---|---|---|
| `proxy` (profile `https`) | `caddy:2` | everywhere, ports 80 and 443 (`SAQF_HTTP_PORT`, `SAQF_HTTPS_PORT`) | volumes `saqf_caddy_data`, `saqf_caddy_config` | Ends TLS, redirects HTTP to HTTPS, forwards to `app:80` (`docker/Caddyfile`; request bodies up to 32 MB) |
| `app` | built from `Dockerfile` (`php:8.3-apache`) | the host only (`127.0.0.1:8080`) and the compose network | volume `saqf_evidence`; read-only mounts of `./storage/inbox` and of the backup status | Serves `public/` only, runs the scheduler loop, installs and migrates at start (`docker/entrypoint.sh`) |
| `db` | `mysql:8.0` | the compose network only (no published port) | volume `saqf_mysql` | The only database; UTF-8 (`utf8mb4`); started with `--log-bin-trust-function-creators=1` so the audit-log triggers can be created |
| `backup` | `mysql:8.0` running `docker/backup.sh --loop` | nothing connects to it | `./backups`, the off-site path, `saqf_evidence`, `saqf_backup_status` | Nightly verified backups and the monthly restore drill (section 10) |
| `clamav` (profile `antivirus`) | `clamav/clamav:stable` | the compose network | none | Optional virus scanning of evidence uploads |

The compose network is `172.28.250.0/24`; the proxy has the fixed address `172.28.250.10`, and `SAQF_TRUSTED_PROXIES` defaults to that address, so only the bundled proxy may tell SAQF a visitor's address (`src/Core/Request.php`). If that range clashes with your network, change the subnet, the proxy's `ipv4_address` and `SAQF_TRUSTED_PROXIES` together in `docker-compose.yml` and `.env`. Containers use the Riyadh time zone (`TZ`, `APP_TIMEZONE`), so backup file names are in Riyadh time.

## 2. Prerequisites

| Need | Detail |
|---|---|
| Linux server with Docker Engine and Docker Compose v2 | `docker compose version` must work. The overlay uses the `!override` YAML tag, which older Compose releases reject; `$DC config > /dev/null` tells you at once. Docker must start at boot: every service has `restart: unless-stopped`. |
| Memory and disk | The overlay's memory limits add up to 3.75 GiB (app 1, db 2, backup 0.5, proxy 0.25) before the operating system and before ClamAV, which the overlay does not limit. Disk is needed for the MySQL volume, the evidence volume, `./backups` (14 days of archives by default) and the off-site share. SAQF has not been load-tested at university scale ([READINESS.md](READINESS.md)): size from your own measurements. |
| DNS name | A name such as `saqf.example.edu` pointing at the server. Caddy obtains and renews a certificate by itself only when the name points at this server and ports 80 and 443 are reachable (`docker/Caddyfile`). For an internal-only name, use the university's own certificate (section 5.4). |
| Second backup location | A mounted network share, NAS or second disk **on another machine**, mounted on the Docker host (for example `/mnt/saqf-offsite`). SAQF can verify the copy; it cannot tell where the share physically is. |
| Password vault | For the application key, the backup passphrase and the database passwords. Two of these cannot be recreated (section 4). |
| Accurate server clock | Authenticator codes (RFC 6238, `src/Security/Totp.php`) and the "backup is older than 26 hours" check depend on it. |
| Outbound HTTPS | From the server to the identity provider, the SIS and LMS addresses, the SMTP server and the alert webhook. Production refuses plain `http://` for these (`src/Integration/Http.php`). |
| People | Two named administrators (the preflight warns about a single one), a security-contact mailbox, an IT change log, and the university's data-protection contact for the incident register (section 12.5). |
| From other offices (not needed to start) | Identity-provider app registration, SMTP account, SIS and LMS access: see [INTEGRATIONS.md](INTEGRATIONS.md). |

## 3. Get the code and pin a version

```sh
git clone <the URL of your copy of this repository> saqf
cd saqf
git rev-parse HEAD        # write this commit hash in the change log
```

At the time of writing the repository has no release tags, so deploy a commit hash, not a branch name. Before deploying a commit, open its CI run (GitHub Actions, workflow *CI*) and read it: job `tests` runs the PHP test suites on MySQL 8.0, `docker` runs the stack with HTTPS, an encrypted backup, the restore drill and a real restore, and `hardened` runs the production overlay of this guide. Job `scan` only reports image and configuration findings and never blocks.

To run the suites yourself, use a **throw-away machine or database, never the production one**: each suite reinstalls the demo into the database named by `SAQF_DB_NAME`.

```sh
sh bin/test_all.sh        # prints its own summary; the count changes as tests are added
```

## 4. Secrets

```sh
sh bin/make_secrets.sh    # writes ./secrets/saqf_app_key, saqf_db_pass, saqf_db_root_pass, saqf_backup_passphrase
```

Each file holds 64 random hex characters (`openssl rand -hex 32`). The script never overwrites a file that exists (it prints `kept`), the folder is mode 700 and the files mode 444 so that the web-server user inside the container can read them. `secrets/` is in `.gitignore` and `.dockerignore`, so it is never committed and never baked into the image.

**Put a copy of every file in the university password vault now.**

| File | Read by | If it is lost |
|---|---|---|
| `saqf_app_key` | `app` (`SAQF_APP_KEY_FILE`) | Student pseudonyms can no longer be extended and stored authenticator secrets cannot be read. Rotation in place is not supported ([OPERATIONS.md](OPERATIONS.md#application-key-saqf_app_key)). Also keep a sealed offline copy, separate from the database backups. |
| `saqf_backup_passphrase` | `backup` (`SAQF_BACKUP_PASSPHRASE_FILE`) | Encrypted backups cannot be restored. |
| `saqf_db_pass` | `app`, `db`, `backup` | The database keeps the password it was created with, so a regenerated file stops matching it. |
| `saqf_db_root_pass` | `db` (and its health check) | Same as above, for the MySQL administrator. |

Rules that follow from this:

- Never delete a secret file and run the script again on an installation that has data. Put the vaulted files back instead.
- The host administrator can read `./secrets` by design, so limit who may administer this host and who may use Docker on it.
- Other secrets (identity-provider client secret, SMTP password, SIS and LMS tokens, the alert webhook) can also come from files: every name in `Config::SECRET_KEYS` (`src/Core/Config.php`) accepts `<NAME>_FILE`, and a value in the environment wins over the file. The overlay wires only the files above. For the others, either keep the value in `.env` (it is then visible in `docker inspect` of the app container) or mount a file with your own override (section 5.3).

## 5. Configure

### 5.1 `.env`

```sh
cp .env.example .env
chmod 600 .env
```

Minimum for production:

```
SAQF_DOMAIN=saqf.example.edu
SAQF_BASE_URL=https://saqf.example.edu
SAQF_BACKUP_OFFSITE_PATH=/mnt/saqf-offsite
SAQF_ADMIN_ALLOWED_IPS=10.0.0.0/8
SAQF_ALERT_WEBHOOK=https://example.webhook.office.com/...
```

| Variable | Default | Meaning |
|---|---|---|
| `SAQF_DOMAIN` | `localhost` | Name Caddy serves. Left at `localhost`, Caddy uses its own local certificate authority, which browsers do not trust. |
| `SAQF_BASE_URL` | empty | Public `https://` address used in e-mail links, password-reset links and the sign-in redirect. The preflight blocks if it is not `https://`. |
| `SAQF_LOCAL_PORT` | `8080` | Host-only port of the app (overlay). `SAQF_PORT` from `.env.example` has no effect with the overlay. |
| `SAQF_HTTP_PORT`, `SAQF_HTTPS_PORT` | `80`, `443` | Ports the proxy publishes. |
| `SAQF_TRUSTED_PROXIES` | `172.28.250.10` | The only addresses whose `X-Forwarded-For` and `X-Forwarded-Proto` SAQF believes. |
| `SAQF_DB_NAME`, `SAQF_DB_USER` | `saqf`, `saqf` | Database and user. Their passwords are the secret files, not `.env`. |
| `SAQF_BACKUP_OFFSITE_PATH` | empty | Host path of the second copy (section 10). Required to clear the preflight's backup blocker. |
| `SAQF_BACKUP_KEEP_DAYS`, `SAQF_BACKUP_INTERVAL_HOURS`, `SAQF_BACKUP_START_DELAY` | `14`, `24`, `900` | Retention, hours between backups, seconds before the first backup after the backup service starts. |
| `SAQF_ADMIN_ALLOWED_IPS` | empty | Administrators may sign in only from these addresses or ranges (campus network, VPN). |
| `SAQF_ALERT_WEBHOOK` | empty | Teams or Slack incoming webhook for IT alerts and audit witnesses. |
| `SAQF_CLAMAV_HOST` | empty | `clamav:3310` when you run the `antivirus` profile. |
| `SAQF_SIS_*`, `SAQF_LMS_*`, `SAQF_MOODLE_*`, `SAQF_BLACKBOARD_*`, `SAQF_OIDC_*`, `SAQF_PASSWORD_LOGIN`, `SAQF_MAIL_*` | empty | University systems: [INTEGRATIONS.md](INTEGRATIONS.md). |

The overlay forces `APP_ENV=production`, `APP_DEBUG=false`, `SAQF_DEMO=false` and `SAQF_AUTO_INSTALL=production`, and blanks the plain variables for the application key, the database passwords and the backup passphrase so the files are used. Anything you put in `.env` for those is ignored.

### 5.2 Variables the compose file passes

`.env` only feeds the compose files: a variable reaches the app container only if `docker-compose.yml` lists it under `environment:`. Every setting the code reads through `Config::get` or `Config::bool` is listed there, including the security contact (`SAQF_SECURITY_CONTACT`), the MFA requirement for university sign-in (`SAQF_OIDC_REQUIRE_MFA`), extra audit-witness recipients (`SAQF_WITNESS_EMAIL`), the robot check (`SAQF_BOT_CHECK`) and the mapped connectors (`SAQF_SIS_MAPPING`, `SAQF_SIS_CLIENT_ID`, `SAQF_SIS_CLIENT_SECRET`, `SAQF_LMS_MAPPING`, `SAQF_LMS_URL`, `SAQF_LMS_TOKEN`, `SAQF_LMS_CLIENT_ID`, `SAQF_LMS_CLIENT_SECRET`). The backup service also receives `SAQF_DRILL_EVERY_DAYS` (default 30; 0 = never). The only ones left out are fixed inside the container (`SAQF_SIS_DIR`, `SAQF_LMS_DIR`: the read-only `./storage/inbox` mount) or are for development (`SAQF_DB_WAIT`, `SAQF_I18N_REPORT`).

Mapping files for the mapped connectors go in `./config/mappings` on the host, which is mounted read-only into the app container; name them as `SAQF_SIS_MAPPING=config/mappings/sis.json` (see `config/mappings/README.md`).

To re-check after an upgrade, compare the names the code reads with the compose file:

```sh
grep -rhoE "Config::(get|bool)\('SAQF_[A-Z0-9_]+" src public bin | sed -E "s/.*'//" | sort -u > /tmp/read.txt
grep -oE "^ +SAQF_[A-Z0-9_]+" docker-compose.yml | tr -d ' ' | sort -u > /tmp/passed.txt
comm -23 /tmp/read.txt /tmp/passed.txt
```

### 5.3 Local override file

Use an override file to give more secrets as files (so they do not appear in `docker inspect`). Put it in `docker-compose.override.yml` (it is in `.gitignore`). Compose reads it automatically only when no `-f` flag is given, so name it in `$DC`:

```sh
export DC="docker compose -f docker-compose.yml -f docker-compose.prod.yml -f docker-compose.override.yml --profile https"
```

```yaml
services:
  app:
    environment:
      # a secret from a file instead of .env (any name in Config::SECRET_KEYS accepts <NAME>_FILE)
      SAQF_OIDC_CLIENT_SECRET_FILE: /run/secrets/saqf_oidc_client_secret
    secrets: [saqf_oidc_client_secret]
secrets:
  saqf_oidc_client_secret:
    file: ./secrets/saqf_oidc_client_secret
```

Create such a file the way `bin/make_secrets.sh` does (`umask 077`, `chmod 444`) and leave the plain variable empty in `.env` (an environment value wins over the file). Check the result with `$DC config` before starting, then confirm the app sees the setting in the Security center or Go-live page. Every later `up`, `run` or `build` must include the override file in `$DC`, otherwise the container is recreated without these variables.

### 5.4 Certificates

The default is Caddy's automatic certificate for `SAQF_DOMAIN`. To use a certificate issued by the university, `docker/Caddyfile` explains the change: mount the files into the `proxy` service and replace the `tls` line. That change is not covered by any test here, so check it with the HTTPS rows of the go-live checklist. SAQF sends `Strict-Transport-Security: max-age=31536000; includeSubDomains` whenever it sees HTTPS (`src/bootstrap.php`): once people have visited, browsers refuse plain HTTP for that name for a year, so make sure HTTPS works before real users arrive.

## 6. The production overlay line by line

`docker-compose.prod.yml` is merged over `docker-compose.yml`. Its header promises that this document explains each line; here they are. The last column says whether the CI job `hardened` (`.github/workflows/ci.yml`) runs a check for it against a throw-away stack on `localhost`.

| Setting | Service | What it does | CI check |
|---|---|---|---|
| `APP_ENV: production`, `APP_DEBUG: "false"`, `SAQF_DEMO: "false"` | app | Production mode: no demo accounts, no one-click demo sign-in, no `install --fresh`, no error details shown to users | The preflight runs in the container and must report no account using the published demo password |
| `SAQF_AUTO_INSTALL: production` | app | On the first start with an empty database, `bin/install.php` creates the production schema (never the demo scenario) and one `admin` account; later starts skip it (`--skip-if-installed`) | The stack starts and reports healthy |
| `SAQF_APP_KEY_FILE`, `SAQF_DB_PASS_FILE` and the empty plain variables | app | Values are read from `/run/secrets/...`, not from the process environment. The plain variables are blanked because an environment value would win over a file (`src/Core/Config.php`) | Health reports `app_key: ok` from a file; no secret value appears in `docker inspect` of app and db |
| `MYSQL_PASSWORD_FILE`, `MYSQL_ROOT_PASSWORD_FILE` | db | The MySQL image reads its passwords from the secret files | Same as above |
| `SAQF_DB_PASS_FILE`, `SAQF_BACKUP_PASSPHRASE_FILE` | backup | The backup service reads the database password and encrypts every backup with the passphrase from the file | An encrypted backup is written and reported as `"encrypted":true` |
| `ports: !override` to `127.0.0.1:${SAQF_LOCAL_PORT:-8080}:80` | app | Replaces the base file's all-interfaces `${SAQF_PORT:-8080}:80`. Apache is then reachable only from the host; the proxy is the only published web entry | Not checked (CI calls the port from the host itself) |
| `read_only: true` and `tmpfs` (`/tmp` 64 MB, `/var/run/apache2` and `/var/lock/apache2` 1 MB) | app | The root file system cannot be written, so the code in the image cannot be altered at run time. Only the tmpfs paths and the evidence volume accept writes; anything PHP stages in `/tmp` (uploads in progress) is in memory and lost when the container is recreated | Yes: read-only root confirmed and a write attempt fails |
| `read_only: true`, `tmpfs: /tmp` 16 MB | proxy | Same for the proxy | No |
| `security_opt: no-new-privileges:true` | app, db, backup, proxy | Processes cannot gain privileges through setuid programs | app only |
| `cap_drop: [ALL]` with `cap_add` | app (`CHOWN`, `DAC_OVERRIDE`, `FOWNER`, `SETUID`, `SETGID`, `NET_BIND_SERVICE`), db (the same without `NET_BIND_SERVICE`), proxy (`NET_BIND_SERVICE`) | Every Linux capability is dropped except those Apache, MySQL and Caddy need (the overlay's own header comment). `backup` keeps Docker's default capabilities | app only |
| `pids_limit`, `mem_limit` | app 256 and 1 GiB, db 512 and 2 GiB, backup 128 and 512 MiB, proxy 128 and 256 MiB | One runaway process or memory leak cannot take the whole host down. Raise the numbers if your own measurements need it | No |
| `logging: json-file, max-size 10m, max-file 5` | app, db, backup, proxy | Docker rotates each container's log (up to 50 MB per container) | No |
| `db.healthcheck` | db | Replaces the base check so it reads the root password from the secret file | The stack starts only if it works |
| top-level `secrets:` | all | The four files in `./secrets` | See above |

What the overlay does **not** do:

- It does not make the host, the firewall, Docker itself or the identity provider safe. Those stay IT's.
- `db` and `backup` are not read-only, and `backup` keeps its default capabilities. `clamav` is not touched at all (no limits, no hardening).
- Images float: `mysql:8.0`, `caddy:2`, `php:8.3-apache`, `clamav/clamav:stable` are tags, not digests. Pin digests if your change process needs reproducible builds.
- Only the checks named in the last column are automated. The limits, the log rotation and the port binding are verified by the go-live checklist, not by CI.

Check the overlay on your server (these are the commands the CI job uses):

```sh
app=$($DC ps -q app)
docker inspect -f '{{.HostConfig.ReadonlyRootfs}}' "$app"      # true
docker inspect -f '{{.HostConfig.SecurityOpt}}' "$app"         # contains no-new-privileges
docker inspect -f '{{.HostConfig.CapDrop}}' "$app"             # contains ALL
$DC exec -T app sh -c 'touch /var/www/saqf/should-fail'        # must fail
key=$(cat secrets/saqf_app_key); dbp=$(cat secrets/saqf_db_pass)
docker inspect "$app" "$($DC ps -q db)" > /tmp/inspect.json
grep -F -e "$key" -e "$dbp" /tmp/inspect.json                  # must print nothing
rm -f /tmp/inspect.json
```

## 7. First start and the first administrator

1. **Check the files.** `ls -l secrets` shows the four files; `$DC config > /dev/null && echo accepted` prints `accepted`.
2. **Optional, before the first start:** put the Registrar's catalogue export into `storage/inbox/catalog/` (or set `SAQF_INSTITUTION_DIR`). SAQF checks it completely before use and falls back to the bundled snapshot of YU's public study plans otherwise (`src/Integration/SeededSources.php`). The files must be readable by the web-server user inside the container.
3. **Start.**
   ```sh
   $DC up -d --build
   ```
   On the first start `docker/entrypoint.sh` waits for MySQL (`SAQF_DB_WAIT`, 90 seconds), runs `php bin/install.php --skip-if-installed` (schema, audit-log triggers, migrations, default policies, catalogue and term sync, the `admin` account), applies `php bin/migrate.php`, fixes the ownership of the evidence folder and starts the scheduler loop and Apache.
4. **Wait until healthy.** `$DC ps` shows `app` as `healthy` (the Dockerfile health check calls `/health.php` every 30 seconds, after a 60 second start period).
5. **Check the probe.**
   ```sh
   curl -fsS http://127.0.0.1:8080/health.php
   ```
   Expect `"status":"ok"`, `"database":"ok"` and `"app_key":"ok"`. `"scheduler"` may say `never run` for the first minutes, then `ok`.
6. **Read the one-time administrator password.**
   ```sh
   $DC logs app | grep -A2 "Administrator account"
   ```
   It prints the user name `admin` and a random password, once. The line stays in Docker's log until the container is recreated; it is useless after the forced change in the next step, but do not paste logs into tickets.
7. **Sign in** at `https://saqf.example.edu/login.php` as `admin`. SAQF forces, in this order, a new password and then the setup of an **authenticator app** (QR code, plus recovery codes shown once: put them in the vault). Administrators must use the app: e-mailed codes and passkeys do not count for the administrator role (`src/Security/Mfa.php`).
8. **Create a second administrator** and add an e-mail address to both (*Administration → Users & access*). Alerts and audit witnesses are e-mailed to administrators' addresses, and the access review needs a second person (the only administrator cannot review themselves).
9. **Make the first backup now** instead of waiting for the 15-minute start delay, then check it:
   ```sh
   $DC exec -T backup sh /usr/local/bin/saqf-backup
   $DC exec -T app cat storage/backups/last-backup.json
   ```
10. **Run the preflight** (section 8).

If the first start fails:

| Symptom | Look at | Cause |
|---|---|---|
| `$DC config` rejects the file (`!override`, `ports`) | `docker compose version` | Compose too old for the overlay |
| `app` restarts in a loop | `$DC logs app` | "SAQF_APP_KEY is required in production and ..." (secret file missing, empty or weak), or "Database server not reachable after waiting" |
| No certificate, or the browser rejects it | `$DC logs proxy` | The name does not resolve to this server, ports 80 and 443 are blocked, or `SAQF_DOMAIN` is still `localhost` |
| Proxy answers 502 | `$DC ps` | `app` is still starting or unhealthy |
| `/health.php` answers 503 | the JSON body | `"database":"unreachable"`, or `"status":"not ready"` (no application key) |
| "WARNING: database migrations did not run" in the app log | `$DC logs app` | Run `$DC exec -T app php bin/migrate.php` and read the error. The container still starts; the preflight then blocks on migrations |

## 8. Preflight and the Go-live tab

```sh
$DC exec -T app php bin/preflight.php          # readable report
$DC exec -T app php bin/preflight.php --json   # the same for a pipeline
```

It judges this installation against the production rules whatever mode it runs in (so it also works on staging and shows what would block). Exit code 0 means no blockers, 1 at least one blocker, 2 the database cannot be read. It never prints a secret. The last line says either "No blockers: these known pre-conditions are met. This is not a security approval." or "NOT READY for real users or real data."

The same list appears in the app: *Administration → Go-live*, card **Production preflight**. Above it, the card **Ready to connect to the university** shows each connection as *Demo data*, *Configured*, *Incomplete* or *Off* with the exact next step; "Configured" means only that the settings are present, and a connection counts as working after *Test SIS and LMS connections* succeeds. The tab also checks the Registrar catalogue and offers the templates for IT (catalogue ZIP, `terms.csv`, `assignments.csv`, a gradebook example, a settings file).

**Blockers** (`src/Security/Preflight.php`):

| Check as shown | Passes when | Usual fix |
|---|---|---|
| Production mode, debug off | `APP_ENV=production`, `APP_DEBUG` off | Set by the overlay |
| No demo accounts or demo shortcuts | No active account uses the published demo password and demo mode is off | Install with `SAQF_AUTO_INSTALL=production` on an empty database |
| Public address is https:// | `SAQF_BASE_URL` starts with `https://` | `.env` |
| Application key from the environment | A strong key is present (from the file under the overlay) | Section 4 |
| Outbound connections use https:// | Every configured SIS, LMS, identity-provider and webhook address is `https://` | Use `https://` addresses |
| Audit log protected by append-only triggers | Both triggers exist on `audit_log` | `database/audit_guard.sql` with a privileged account |
| Audit chain verifies | The hash chain is intact | [OPERATIONS.md incident checklist](OPERATIONS.md#incident-checklist-suspected-tampering-or-account-compromise) |
| Database migrations applied | None pending | `php bin/migrate.php` |
| Password-using administrators have two-step verification | Each local administrator has the app set up | Step 7 above |
| Complete, verified, encrypted backup with a second copy | `last-backup.json` is recent (under 26 hours), `ok`, read back, includes the evidence files when evidence exists, encrypted, copied off-site | Set `SAQF_BACKUP_OFFSITE_PATH`, run the backup (step 9) |
| Evidence store outside the web root and writable | The evidence folder is not under `public/` and is writable | The `saqf_evidence` volume does this |

**Warnings**, to fix or to accept in writing: a second administrator exists; two-step verification required for everyone using a password; university single sign-on configured; password sign-in restricted when SSO is on; a restore drill passed in the last 100 days; the scheduler ran in the last 20 minutes; IT alerts reach people outside SAQF; audit-chain witnesses leave the server; evidence uploads are malware-scanned; the access review is current; the security self-test passes; secret files are not world-readable; a security contact is published. Three more lines are information only: secrets supplied from files, and whether the SIS and the LMS are not the demo feed.

On day one, expect two blockers to be open: the administrator's two-step setup until step 7, and the backup until the first complete backup with an off-site copy exists (steps 8 and 9 and `SAQF_BACKUP_OFFSITE_PATH`). Several warnings also stay open until you configure SSO, alerting and run the first drill. The nightly scheduler runs the self-test, or press *Security center → Run security self-test*.

A clean preflight means none of these known pre-conditions is missing. It does not mean the installation has been reviewed or approved.

## 9. Connect the university's systems

Do this on staging data first, then in production. [INTEGRATIONS.md](INTEGRATIONS.md) holds the file formats, API contracts and provider-specific steps; this table only adds what is specific to the Docker stack.

| System | Setting | Details | Stack-specific note |
|---|---|---|---|
| Registrar catalogue | `storage/inbox/catalog/` or `SAQF_INSTITUTION_DIR` | [Registrar catalogue](INTEGRATIONS.md#registrar-catalogue) | Check with *Go-live → Check the catalogue in use* or `$DC exec -T app php bin/pack.php validate` |
| SIS (calendar, teaching assignments) | `SAQF_SIS_SOURCE`: `file`, `rest`, `mapped`, `none` | [SIS](INTEGRATIONS.md#sis) | Unset means `file` in production: until `terms.csv` and `assignments.csv` are in `storage/inbox/sis/`, *Test connections* reports it as not ready (`src/Integration/FileSources.php`). `./storage/inbox` is mounted read-only into the container |
| LMS (grades) | `SAQF_LMS_SOURCE`: `moodle`, `blackboard`, `mapped`, `file`, `none` | [LMS](INTEGRATIONS.md#lms) | `SAQF_LMS_COURSE_KEY` must match the LMS course IDs. Mapping files go in `./config/mappings` (section 5.2) |
| Edugate and the university LMS | the integration contract | [Edugate and LMS integration contract](INTEGRATIONS.md#edugate-and-lms-integration-contract-awaiting-university-it) | No Edugate adapter exists. The contract (read-only scope, delivery, field mapping, anonymised staging sample, dry run, reconciliation, IT sign-off) is awaiting university IT |
| Sign-in | `SAQF_OIDC_*`, `SAQF_PASSWORD_LOGIN=admins` | [University sign-in](INTEGRATIONS.md#university-sign-in-openid-connect) | Keep password sign-in for administrators as the break-glass route. Multi-factor sign-in for SSO is the identity provider's policy: get it confirmed in writing. Set `SAQF_OIDC_REQUIRE_MFA=true` if the identity provider reports MFA in `amr` |
| E-mail | `SAQF_MAIL_*` | [E-mail](INTEGRATIONS.md#e-mail) | Put the SMTP password in `.env` or a `_FILE` secret (section 4) |
| Alerts | `SAQF_ALERT_WEBHOOK` | [IT alerts to Teams or Slack](INTEGRATIONS.md#it-alerts-to-teams-or-slack) | Must be `https://` in production |
| Virus scanning | `--profile antivirus`, `SAQF_CLAMAV_HOST=clamav:3310` | [Virus scanning](INTEGRATIONS.md#virus-scanning) | Add the profile to `$DC` for `up`; uploads pause while the scanner is unreachable, and SAQF does not check the scanner's signature updates |

Before a feed goes live, run the read-only dry run on the anonymised staging sample (it writes nothing and prints no student identifier):

```sh
$DC exec -T app php bin/dry_run.php sis /var/www/saqf/storage/inbox/sis
$DC exec -T app php bin/dry_run.php lms /var/www/saqf/storage/inbox/lms <term>
```

Exit code 0 means every row is clean, 1 that some rows need attention, 2 that the export cannot be read. For a mapped API, `bin/mapping_check.php` validates a mapping file and runs it on saved sample responses, with no network and no database. Finish with *Go-live → Test SIS and LMS connections*.

## 10. Backups, off-site copy and the restore drill

### 10.1 What runs

The `backup` service runs `docker/backup.sh --loop`: it waits `SAQF_BACKUP_START_DELAY` seconds (900), then repeats every `SAQF_BACKUP_INTERVAL_HOURS` (24) hours, counted from the start, not at a fixed clock time. Each run:

1. dumps the database with its triggers and routines (`saqf-YYYYmmdd-HHMMSS.sql.gz`) and archives the evidence volume (`saqf-files-....tar.gz`);
2. under the overlay, encrypts each file with AES-256 and PBKDF2 using the passphrase from `secrets/saqf_backup_passphrase` (`.enc`);
3. reads every file back (decrypting it) and writes a `.sha256` checksum beside it;
4. when `SAQF_BACKUP_OFFSITE_PATH` is set, copies the files to it and re-checks the checksum there;
5. deletes files older than `SAQF_BACKUP_KEEP_DAYS` (14) in both places;
6. writes `last-backup.json` for SAQF with the state of each part. A failed evidence archive or a failed off-site copy fails the whole run.

SAQF reads that file: *Administration → System health → Backups* and the Security center show it, and the preflight and the IT alerts depend on it (missing, failed, older than 26 hours, evidence missing, not encrypted, or no verified second copy).

### 10.2 The off-site copy

Mount the share on the Docker host first and check that it is really another machine:

```sh
findmnt /mnt/saqf-offsite       # the SOURCE column names the NAS, share or other disk
```

A path that is not mounted is not an error to Docker: the files would land on the local disk and still be reported as copied. SAQF verifies that the copy exists and matches its checksum; it cannot see where the share lives. Verify the copy from the share side:

```sh
cd /mnt/saqf-offsite && for f in saqf-*.sha256; do sha256sum -c "$f"; done
```

### 10.3 What is not in the backup

The backup holds the database and the evidence files. Keep the rest elsewhere: `.env`, `secrets/` (in the vault), the application key (vault plus offline copy), your override file, the `storage/inbox` export folders, the Caddy certificate volumes, and a record of the deployed commit.

### 10.4 The restore drill

`docker/restore_drill.sh` proves that the newest backup can be restored without touching the live database. It decrypts and unpacks the backup, loads it into a throw-away MySQL server started inside the backup container (own data folder, no network), and checks that the archive reads, the dump loads with its triggers, the audit-chain entry recorded at backup time is present, the append-only triggers exist, and every current evidence row has its file in the evidence archive. It writes `last-restore-drill.json`, which the Security center and the preflight read.

```sh
$DC exec -T backup sh /usr/local/bin/saqf-restore-drill
$DC exec -T app cat storage/backups/last-restore-drill.json     # expect "ok":true and "audit_head":"match"
```

The backup loop also runs the drill by itself when the last one is older than 30 days. The preflight warns when no drill passed in the last 100 days. The drill runs inside the backup container, which the overlay limits to 512 MiB and 128 processes; CI shows that this fits the demo data, so confirm it with your own data.

What the drill does **not** prove (it says so itself): the recovery time on real hardware, that the live server could be rebuilt from scratch, or the full hash-chain verification (run `php bin/verify_audit.php` after a real restore). It is not a substitute for restoring onto a separate staging server and recording the result in the IT change log.

### 10.5 A real restore

This replaces the data in the live database with the backup's. Keep a fresh backup of the current state if you can, and use the overlay form of the command so the passphrase file is mounted:

```sh
ls -1 backups | grep 'sql.gz'                  # choose the file
$DC stop app
$DC run --rm --entrypoint sh backup /usr/local/bin/saqf-restore saqf-20261002-023000.sql.gz.enc
$DC start app
$DC exec -T app php bin/verify_audit.php       # exit code 0 = chain intact
```

The restore script checks the checksum, decrypts, loads the database, and restores the evidence archive with the same timestamp. A broken chain after a restore means the dump is incomplete or was altered. The script and the follow-up check run in CI job `docker` on a throw-away demo stack; your own backups, share and hardware are not covered.

On a **new server** the order is: put the vaulted `secrets/saqf_app_key` and `secrets/saqf_backup_passphrase` (and the database files) in place **before** running `bin/make_secrets.sh`, which keeps files that exist; copy the backup files into `./backups`; `$DC up -d db`; run the restore command above; then `$DC up -d --build`. The installer skips a database that already has tables. This sequence is derived from the scripts and has not been run end to end by CI, so rehearse it on staging.

## 11. Upgrades and rollback

SAQF's schema changes are forward-only SQL files in `database/migrations/`. `bin/migrate.php` applies the pending ones in name order and records each in `schema_migrations`, and the container runs it on every start. There are no down-migrations, and SAQF has not been tested running older code on a newer schema.

**Upgrade**

1. Read what will change:
   ```sh
   git fetch
   git log --oneline HEAD..origin/main
   git diff --stat HEAD..origin/main -- database/migrations docker-compose.yml docker-compose.prod.yml Dockerfile docker .env.example
   ```
   New files under `database/migrations/` mean a schema change; a changed `.env.example` can mean a new setting.
2. Turn on maintenance mode: *Administration → System health → Maintenance mode* (a reason is required and audited). It blocks every non-administrator.
3. Take a fresh backup and check it (`$DC exec -T backup sh /usr/local/bin/saqf-backup`, then `last-backup.json` shows `"status":"ok"`). Write down the current commit (`git rev-parse HEAD`).
4. Check out the new commit and rebuild:
   ```sh
   git checkout <new commit>
   $DC build --pull app           # also takes new base-image fixes; omit --pull to keep the cached base
   $DC up -d --build
   ```
   The app container is recreated, so the scheduler loop restarts and people may have to sign in again.
5. Read the result: `$DC logs --tail 60 app` shows `Applied ...` lines or "Database schema is up to date". Then:
   ```sh
   $DC exec -T app php bin/migrate.php --status     # "Database schema is up to date."
   $DC exec -T app php bin/preflight.php
   $DC exec -T app php bin/verify_audit.php
   ```
6. Turn maintenance mode off the same way.

Migrations run statement by statement and are recorded only after all of a file's statements succeeded. If one fails half-way, do not run it again: restore the pre-upgrade backup (below). A failed migration does not stop the container from starting (`docker/entrypoint.sh` only warns), so always read the log and the preflight's "Database migrations applied" line.

To pick up fixes for `mysql:8.0` or `caddy:2`, run `$DC pull db proxy` and `$DC up -d` in a maintenance window, after a backup.

**Rollback**

- If the upgrade had no new file under `database/migrations/` (`git diff --name-status <old>..<new> -- database/migrations` prints nothing), check out the previous commit and run `$DC up -d --build`.
- If it had migrations, restore the pre-upgrade backup (section 10.5) with the previous commit checked out. Data entered after that backup is lost, so keep maintenance mode on while you decide.
- Afterwards run `bin/verify_audit.php` and the preflight, and write the incident in the IT change log.

## 12. Monitoring

### 12.1 Health probe

`GET /health.php` needs no sign-in and shows nothing sensitive:

```json
{"status":"ok","version":"...","database":"ok","scheduler":"ok","maintenance":false,"app_key":"ok"}
```

| Field | Values | Meaning |
|---|---|---|
| HTTP status | 200 or 503 | 503 when the database is unreachable (`"status":"unavailable"`) or, in production, no application key exists (`"status":"not ready"`) |
| `scheduler` | `ok`, `stale`, `never run` | Last tick within 20 minutes, older, or never. **A stalled scheduler still answers 200**, so a monitor that only checks the status code will miss it |
| `app_key` | `ok`, `development`, `not ready (...)` | Where the key comes from; never the key |
| `maintenance` | true or false | Maintenance mode is on |

A minimal external check: `curl -fsS https://saqf.example.edu/health.php | grep -q '"scheduler":"ok"'` (a non-zero exit means alert). The container's own health check uses the same URL, and Docker marks the container `unhealthy` on a 503.

### 12.2 Scheduler

The app container runs `php bin/tick.php` every 5 minutes as the web-server user (`docker/entrypoint.sh`). One scheduler runs at a time (database lock). It does the SIS sync and automatic term start, the LMS imports, the daily catalogue re-sync and rule re-checks, the nightly audit verification, witness, self-test and access-review alert, e-mail digests and the mail queue. Each run prints a line to the container log (`$DC logs app`); exit code 2 means a step failed and the *Error log* has the detail. In production an IT alert opens if it has not run for 30 minutes.

### 12.3 IT alerts

SAQF raises an alert itself, shows it under *Administration → IT alerts*, e-mails administrators, posts it to `SAQF_ALERT_WEBHOOK`, repeats it every 6 hours while open, and clears it when the check passes again. The kinds (failing connectors, failed or incomplete backup, broken audit chain, stalled scheduler, mail failure, unreachable scanner, blocked malware, password guessing, failed self-test, overdue access review) are listed in [OPERATIONS.md](OPERATIONS.md#it-alerts). What you must supply is the human side: administrator accounts with working e-mail addresses, a webhook channel that someone reads, and an owner for each alert. SAQF ships no paging or on-call integration. It has no separate "send a test alert" button; *Take a checkpoint now* (12.4) uses the same e-mail and webhook channels and shows whether it left the server.

### 12.4 Audit witnesses

The hash chain shows that a row was edited or deleted. Someone with database administrator rights could still rewrite the whole chain consistently. So every night the scheduler records a **witness** (last entry number, entry count and hash of the last entry) and sends it outside the server, by e-mail to administrators (plus `SAQF_WITNESS_EMAIL`) and to the webhook. The scheduler also re-checks the stored witnesses first and raises a critical alert if history differs.

- Keep the messages: a mailbox rule or a channel with retention. SAQF cannot see whether they are kept, and witnesses only protect history up to their checkpoint. They do not prevent tampering.
- Verify a witness later: `php bin/verify_audit.php --witness "SAQF-WITNESS/1 ..."` (exit 0 unchanged, 2 history differs), or *Administration → Activity log → Witnessed checkpoints*.
- Nightly chain check: `php bin/verify_audit.php` exits 0 for an intact chain and 2 for tampering. Each run adds an `audit.verified` entry to the log.
- If you need evidence that survives loss of the server, also export the log (*Activity log → Export CSV*) to a location outside it.

### 12.5 Incident register

*Administration → Security incidents* (`/incidents.php`) is a register with a 72-hour notification clock for incidents that involve personal data, reminders and an audit trail. Whether and when a notification duty applies is for the university's data-protection contact or legal counsel to decide. SAQF never contacts an authority or any person by itself.

### 12.6 Logs, host and reboots

- `$DC logs app`, `logs db`, `logs backup`, `logs proxy`. Docker keeps up to 50 MB per container (10 MB times 5 files); ship them elsewhere if you need longer retention.
- *System health* has no disk-space check. Monitor free space for the Docker data folder, `./backups` and the off-site share with your usual host monitoring, and watch the Docker service itself.
- After a host reboot the containers return when the Docker service starts. Check `$DC ps` and `/health.php`. The backup service waits its start delay again before its first backup.
- Weekly: *Security center* and *Security events*. Every 90 days (policy `security.access_review_days`): *Access review*, done by a second administrator.

## 13. Without Docker

The README lists Apache or Nginx, PHP 8.3 and MySQL 8 as requirements, so a hand-built server is possible. It is not exercised by CI: the test suites use PHP's built-in server and the HTTP checks run against the Docker image's Apache. Treat this path as documented but unproven, and test it on staging. This repository ships no Nginx configuration and no test for it. The table lists what the Docker stack does for you and what you then do by hand.

| Topic | In the Docker stack | By hand |
|---|---|---|
| PHP | `php:8.3-apache` plus `pdo_mysql` (`Dockerfile`) | PHP 8.3 with `pdo_mysql`, `mbstring`, `curl`, `openssl`; check with `php -m` |
| PHP settings | `docker/php.ini` | Apply the same settings: `expose_php` off, `display_errors` off, `log_errors` on, `zend.exception_ignore_args` on, strict session cookies, upload limits. Two lines are Docker-specific: `error_log = /proc/self/fd/2` (point it at a file) and `opcache.validate_timestamps = 0` (then reload PHP or Apache after every code change) |
| Web server | `docker/apache-saqf.conf` | Virtual host with the document root set to `public/` (never the repository root). Copy the directives of `docker/apache-saqf.conf`: no directory listings, `AllowOverride None`, deny `_init.php` and files ending `.sql .log .bak .env .md .json`, hide the server version, disable `TRACE`, and the `/.well-known/security.txt` rewrite to `security_txt.php` (needs `mod_rewrite`). The root `.htaccess` is only a safety net for hosts that cannot change the document root |
| HTTPS | Caddy | A TLS virtual host with the university's certificate and an HTTP to HTTPS redirect. SAQF sends HSTS and `Secure` cookies when it sees HTTPS. If TLS ends at a load balancer, put its address in `SAQF_TRUSTED_PROXIES` so SAQF trusts its `X-Forwarded-Proto` and `X-Forwarded-For` |
| MySQL | `mysql:8.0` with `utf8mb4` and `utf8mb4_unicode_ci` | MySQL 8.0 with the same character set and collation, `log_bin_trust_function_creators=1` (or install the triggers from `database/audit_guard.sql` with a privileged account), a `saqf` user with all privileges on the `saqf` database only (`TRIGGER` is required), reachable only from the web host |
| Secrets | Secret files | `config.local.php` (copy of `config.example.php`, mode 600, owned by the web-server user, never committed) or `<NAME>_FILE` variables in the web server's and cron's environment. Environment values win over the file. Generate the key first: `php bin/app_key.php generate` |
| First install | Automatic | As the web-server user with `APP_ENV=production`: `php bin/install.php` (prints the one-time `admin` password) |
| Migrations | Every container start | `php bin/migrate.php` after every code update |
| Evidence store | `saqf_evidence` volume | A folder outside the document root, owned by the web-server user, mode 700, set with `SAQF_STORAGE_DIR` |
| Scheduler | Container loop | Cron, as in [OPERATIONS.md](OPERATIONS.md#daily-operation) ("Example `/etc/cron.d/saqf`"): `bin/tick.php` every 5 minutes |
| Backups | `backup` service | Cron job for `docker/backup.sh`; it needs `mysqldump`, `mysql`, `openssl`, `tar`, `gzip` and `sha256sum`. Set `SAQF_DB_*`, `SAQF_BACKUP_DIR`, `SAQF_BACKUP_FILES_DIR` (the evidence folder), `SAQF_BACKUP_PASSPHRASE` or `_FILE`, `SAQF_BACKUP_OFFSITE_DIR` and set `SAQF_BACKUP_STATUS_DIR` to the folder SAQF reads (`SAQF_BACKUP_MONITOR_DIR`, default `storage/backups` in the install folder), otherwise SAQF reports that no backup has reported |
| Restore and drill | `docker/restore.sh`, `docker/restore_drill.sh` | `restore.sh` is plain `sh` and takes the same variables. The drill starts a scratch `mysqld`, so the MySQL server binary must be installed; it was written for and tested in the backup container only |
| Hardening | Read-only file system, dropped capabilities, resource limits, log rotation | None ships. Use the operating system's means (service sandboxing, mandatory access control, `logrotate`, a firewall) |
| Firewall | Only the proxy publishes ports | Open 80 and 443 only; MySQL reachable from the web host only |
| Checks | `$DC exec -T app php bin/preflight.php` | `php bin/preflight.php` as the web-server user from the install folder |
| Upgrades | Section 11 | `git checkout`, `php bin/migrate.php`, reload PHP; take a backup first |

## 14. Limits and what stays with people

**Not covered by any test or CI job in this repository**: certificate issuance for your real domain, a university-issued certificate in Caddy, the off-site share, ClamAV, SMTP, your identity provider, Moodle, Blackboard, the SIS or Edugate, upgrades and rollbacks on real data, a rebuild on a new server, the non-Docker path, resource limits, log rotation, port binding, and behaviour at university scale. **Open by design**: the application key cannot be rotated in place; a tested procedure for rotating database passwords is not provided; SAQF is a single-host deployment with no clustering or failover, so recovery means restoring from backup; retention, privacy and accessibility policies and the independent security review live outside SAQF ([READINESS.md](READINESS.md), prerequisites 7 and 8).

**What stays with people**, whatever the deployment: SAQF never writes an instructor's reading of results, never accepts evidence by itself (a Head of Department or Quality reviewer does), never approves specifications by itself, and never contacts authorities. Administrators cannot make academic decisions or change course content. The Deanship of Quality must confirm the policies under *Quality policies*, including the course file checklist, which the Closeout tab labels as SAQF's default until Quality changes it.

**Sign-offs to collect before go-live**, none of which SAQF can show: the identity provider's MFA policy for SAQF, in writing; the integration contract signed off by the Registrar, the LMS owner and IT; approved retention, privacy and accessibility policies; the independent security review of this deployment (the built-in self-test is not a substitute); the agreed department pilot. See [READINESS.md](READINESS.md) for owners.

## 15. Go-live checklist

`$DC` is defined under [Conventions](#conventions). Replace `saqf.example.edu` and the share path with your own. "Screen" means the page named in *Administration*.

| # | Check | How to verify | Pass when |
|---|---|---|---|
| 1 | The deployed version is the one you tested | `git rev-parse HEAD`; the CI run of that commit on GitHub (Actions, workflow *CI*) | The hash equals the change-log entry; jobs `tests`, `docker` and `hardened` are green |
| 2 | Compose accepts both files | `$DC config > /dev/null && echo accepted` | Prints `accepted` |
| 3 | Services are healthy | `$DC ps` | `app` and `db` show `healthy`; `backup` and `proxy` are running and not restarting |
| 4 | Production mode, demo off | `$DC exec -T app php bin/preflight.php`; screen *System health* | Lines "Production mode, debug off" and "No demo accounts or demo shortcuts" are `ok` |
| 5 | Hardening is applied | The commands at the end of section 6 | Read-only root `true`; `no-new-privileges`; `ALL` dropped; the write attempt fails; `grep` prints nothing |
| 6 | Limits and log rotation exist | `docker inspect -f '{{.HostConfig.Memory}} {{.HostConfig.LogConfig}}' "$($DC ps -q app)"` | `1073741824` and `json-file` with `max-size:10m` and `max-file:5` |
| 7 | The app port is not exposed | `$DC port app 80`, then `ss -ltn` on the host | `127.0.0.1:8080`; the only listeners on all interfaces are 80, 443 and your remote-access port |
| 8 | The database is not published | `$DC ps db` | The ports column shows no `->` mapping |
| 9 | HTTPS with a trusted certificate | `curl -sI https://saqf.example.edu/login.php` (no `-k`) | The request succeeds; the headers include `strict-transport-security` and a `set-cookie: SAQFSESSID=...` that carries `secure` |
| 10 | HTTP redirects to HTTPS | `curl -sI http://saqf.example.edu/login.php` | A `location: https://...` header |
| 11 | The application key is in place | `$DC exec -T app php bin/app_key.php status`; the vault | `Status: ready`; the vault owner confirms the entries and the offline copy |
| 12 | The health probe is green | `curl -fsS https://saqf.example.edu/health.php` | `"status":"ok"`, `"database":"ok"`, `"scheduler":"ok"`, `"app_key":"ok"` |
| 13 | Administrators use two-step verification | Preflight line; screen *Security center* | "Password-using administrators have two-step verification" is `ok` |
| 14 | A second administrator exists, with e-mail | Screen *Users & access*; screen *Access review* | Two administrators with addresses; preflight "A second administrator exists" is `ok` |
| 15 | University sign-in works | Sign in with a pilot account through the university button; screen *Security center* | "University single sign-on" is in place; "Password sign-in restricted" is in place; the identity provider's MFA confirmation is on file |
| 16 | The security contact is published | `curl -s https://saqf.example.edu/.well-known/security.txt` | A `Contact:` line (set `SAQF_SECURITY_CONTACT`) |
| 17 | Administrator network restriction (if chosen) | Screen *Security center*; try an administrator sign-in from an unlisted network | The row names your ranges; the sign-in is refused |
| 18 | A complete backup exists | `$DC exec -T backup sh /usr/local/bin/saqf-backup`; `$DC exec -T app cat storage/backups/last-backup.json` | Exit code 0; `"status":"ok"`, `"verified":true`, `"encrypted":true`, `"offsite":true`, `"files":true` |
| 19 | The off-site copy is on another machine and intact | `findmnt /mnt/saqf-offsite`; `cd /mnt/saqf-offsite && for f in saqf-*.sha256; do sha256sum -c "$f"; done` | The source is not a local disk of this server; every file reports `OK` |
| 20 | The restore drill passes | `$DC exec -T backup sh /usr/local/bin/saqf-restore-drill` | Ends with `ok=true`; `last-restore-drill.json` has `"ok":true` and `"audit_head":"match"`; the preflight "A restore drill passed in the last 100 days" is `ok` |
| 21 | A real restore was rehearsed on staging | On staging, `php bin/verify_audit.php; echo $?` after section 10.5; the IT change log | Exit code 0; a log entry with date, backup file and person |
| 22 | The audit chain and its triggers are intact | `$DC exec -T app php bin/verify_audit.php; echo $?`; preflight line | Exit code 0; "Audit log protected by append-only triggers" is `ok` |
| 23 | Alerts and witnesses leave the server | Screen *Activity log*, press *Take a checkpoint now* | The checkpoint shows `sent by e-mail, webhook` (as configured) and the message arrives in the mailbox and channel; a retention rule exists |
| 24 | Outbound connections use HTTPS | Preflight line; screen *Security center* | "Outbound connections use https://" is `ok`; "Encrypted connections to university systems" is in place |
| 25 | SIS, LMS and catalogue are connected or consciously off | Screen *Go-live*: *Test SIS and LMS connections*, *Check the catalogue in use*; `$DC exec -T app php bin/dry_run.php sis <folder>` on the staging sample | Each system shows what you intend (live, or off by decision); the tests succeed; the dry run exits 0 |
| 26 | E-mail works | Screen *System health*, row *E-mail*; the checkpoint message from row 23 | `OK`, no messages waiting; a real message was received |
| 27 | The virus-scanning decision is made | Screen *Security center*, row *Evidence virus scanning* | In place with a scanner, or a written decision by IT security that other controls cover uploads |
| 28 | The scheduler runs | `curl -fsS https://saqf.example.edu/health.php`; `$DC logs --tail 20 app` | `"scheduler":"ok"`; recent `tick ok` lines |
| 29 | The preflight has no blockers | `$DC exec -T app php bin/preflight.php; echo "exit=$?"`; screen *Go-live* | `exit=0`; every remaining `WARN` line has a written decision |
| 30 | The self-test passes | Screen *Security center*, *Run security self-test* | All self-checks pass (it is a self-test, not a penetration test) |
| 31 | The access review is current | Screen *Access review*, done by a second administrator | Every account shows *Current* |
| 32 | Quality has confirmed the policies | Screen *Quality policies*; a course's *Closeout* tab | The Deanship of Quality's decisions are recorded; the Closeout tab no longer calls the checklist SAQF's default |
