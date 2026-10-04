# Security policy

## Reporting a vulnerability

Please **do not open a public issue** for a security problem. Use GitHub's private vulnerability reporting
for this repository (Security tab → *Report a vulnerability*), which the maintainers must have enabled, or
contact the project maintainers directly. An installation that has configured `SAQF_SECURITY_CONTACT`
publishes its own contact at `/.well-known/security.txt`.

Please include the version (shown in the page footer and at `/health.php`), what you did, what you expected
and what happened. Use synthetic data only; do not test against a university's live system without its
written permission.

## Scope and honesty

SAQF is a Docker-deployable demo with a hardened production configuration. It has **not** had an independent
penetration test or security review, and it is not certified by anyone. Its built-in self-test is a
self-check. What the project does verify on every change is listed in [`docs/SECURITY_ASSURANCE.md`](docs/SECURITY_ASSURANCE.md)
together with what it does not cover.

## What is in scope

The application code (`src/`, `public/`, `bin/`), the container and compose files (`Dockerfile`,
`docker-compose*.yml`, `docker/`) and the CI configuration. Out of scope: the university's identity provider,
network, hosting and operating system.

## Supported versions

Only the latest release on `main`. Security fixes are made there.

## Secrets

Never commit `.env`, `secrets/`, `config.local.php`, database dumps or uploaded evidence (they are in
`.gitignore`). If one is committed by mistake, treat the secret as compromised and rotate it; for the
application key, see `docs/OPERATIONS.md` ("Application key") before changing anything.
