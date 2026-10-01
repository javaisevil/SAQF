# SAQF architecture

This document is for developers and university IT. For a non-technical overview read [TEAM_REPORT.md](TEAM_REPORT.md).

## 1. Principles that shaped the design

1. **If the university already knows it, nobody types it.** Course data, plans, prerequisites, PLOs, teaching assignments and results come through adapters, and every displayed value carries a provenance label (Registrar, SIS, LMS, Inherited, Calculated, Derived, Policy default, Faculty input, Suggested, Overridden).
2. **If software can validate it, no person checks it.** One central rules engine (`src/Quality/Rules.php`) owns every deterministic rule. Pages never re-implement checks.
3. **If software can calculate it, nobody calculates it.** Achievement, PLO contribution, contact hours, plan totals, effectiveness and recurrence are all derived.
4. **Problems come to people.** Rules produce *findings* with an owner role. Findings route to Action Centers, so nobody searches for them.
5. **Humans decide academic matters.** New or changed outcomes, PLO changes, improvement content and policy exceptions stay human decisions, and each is recorded with its reason.

## 2. Layers and code map

| Layer | Code | Notes |
|---|---|---|
| Integration adapters | `src/Integration/Sources.php` (interfaces), `SeededSources.php` (prototype implementations), `Sync.php` | `InstitutionSource`, `SisSource`, `LmsSource`. The prototype reads `data/yu/*.json` and `data/demo/*`. Production swaps in real implementations. |
| Data model | `database/schema.sql` (38 tables) | institution → colleges → departments → programs → study_plan_entries ↔ courses → course_offerings (per term) → spec_versions → clos / assessments / clo_plo / assessment_clo → result_batches / assessment_results → clo_achievement / plo_achievement → improvement_actions |
| Rules engine | `Rules.php`, `Findings.php` | 33 rules in 7 categories. Findings are fingerprinted, deduplicated, re-opened if a problem returns, and **auto-resolved** when the rule passes again. |
| Event bus | `src/Core/Events.php`, wiring in `src/Quality/Engine.php` | Events: `spec.changed`, `spec.approved`, `results.imported`, `achievement.computed`, `improvement.changed`, `plo.changed`, `offering.assigned`. Every event and its outcome is stored in `events` (visible to IT under Integrations). |
| Calculation | `Achievement.php` | Results → weighted per-student CLO score → % reaching the threshold (or mean) → CLO achievement → PLO = mean of contributing CLOs. *Provisional* until every planned assessment has results. Method and thresholds come from policy. |
| Improvement loop | `Improvements.php` | A missed target creates a **draft** action (owner = instructor, due date from policy). The instructor writes the academic response. Next term the system compares and labels it *improved / similar / declined following the intervention*. |
| Workflow | `Specs.php`, `Overrides.php`, `Workspaces.php` | Copy-on-write drafts, field-level diff, academic vs non-academic changes, red/amber/green routing, QA sampling, rollover. |
| Assisted insights | `Intelligence.php`, `Text.php` | TF-IDF cosine similarity for CLO→PLO mapping suggestions and CLO overlap, trend and anomaly detection. Labelled *Assisted*, shown with reasoning, applied only when a person accepts, and never described as AI. |
| Status | `Status.php` | Explainable course states: *Action required*, *Exception detected*, *Awaiting academic decision*, *Improvement follow-up*, *Ready*. Each state lists the reasons behind it. No opaque scores. |
| Role views | `ActionCenter.php`, `public/*.php` | One Action Center per role. Every number links to the records behind it. |
| Reports | `Reports.php`, `public/report.php` | Course and program reports are views over live data. Closing a term freezes each course report into `snapshots` with a SHA-256 seal. |
| Cross-cutting | `Audit.php`, `Ledger.php`, `Policy.php`, `Notify.php`, `ErrorLog.php`, `Security/*` | Hash-chained audit log, automation ledger ("work saved" counts), configurable policies, action-only notifications, error references. |

## 3. Event-driven pipeline

```
SIS assignment ─▶ offering.assigned ─▶ Workspaces::initialize
                                         ├─ approved spec exists → inherit (0 approvals)
                                         └─ none → OFFERING_NO_SPEC (faculty action)
Faculty edits draft ─▶ spec.changed ─▶ re-validate draft + open offerings, refresh suggestions
Submit ─▶ blockers? refuse ─▶ HoD decides on the diff only
          ├─ green (no warnings): auto-cleared after HoD, QA samples a share (policy)
          └─ amber: QA decides
spec.approved ─▶ re-evaluate offerings + every program containing the course
LMS batch published ─▶ (scheduler) results.imported ─▶ Achievement::compute
achievement.computed ─▶ draft improvement actions for gaps, evaluate last term's actions,
                        re-validate offering, trends/anomalies, re-evaluate programs
PLO changed ─▶ plo.changed ─▶ impact analysis + re-validate dependent specs and program
Term activated ─▶ close previous term (snapshot reports) ─▶ rollover: every assignment
                  inherits its approved spec ─▶ carry open improvement actions
```

`bin/tick.php` (cron, every 5 min) is the heartbeat. It imports newly published LMS batches, re-checks date-driven rules (overdue results and actions) and re-evaluates programs daily. The web app also triggers it opportunistically, at most every 5 minutes.

## 4. Study-plan intelligence (Al Yamamah University)

`data/yu/` holds 14 programs (10 undergraduate, 4 postgraduate) in 4 colleges and 10 departments. It contains 366 courses, 735 plan entries and 488 prerequisite links, each per program. 95 courses are shared by more than one program. Relationships are first-class data, so the UI can **prevent** invalid choices instead of reporting them:

- **Level separation:** postgraduate programs (MBA, EMBA, MCS, LL.M) only list their own courses, and undergraduate plans never show graduate courses. The study-plan browser narrows *level → college → program → type*.
- **Course ownership** comes from the course prefix (e.g. SWE/CIS → Computer Engineering Department, ACC/FIN → Accounting & Finance). A HoD can only assign courses their department owns, and only programs whose plan contains those courses are offered.
- **Required vs elective is per program.** MIS 327 is required in MIS but an elective in ACC/FIN/IE. Unmapped electives raise one aggregated advisory, not a blocker.
- **CLO→PLO mapping** offers only the PLOs of programs whose plan contains the course. The API rejects anything else.
- **Prerequisites are per program** (the same course can have different prerequisites in different plans). Credit thresholds and co-requisites are kept.
- **Preparatory courses** (e.g. PGRD 495/496 Pre-MBA foundation) are marked and kept out of credit totals.
- **Source conflicts are detected and resolved by authority.** When plans disagree (MIS 316 shows 3 CR in the semester grid and 4 CR in a course list), SAQF applies the authoritative value and routes a *data exception* to QA. Plan totals that do not match the published credit total (MGT/MKT) are flagged the same way.

## 5. Security model

- **Authentication:** bcrypt (`password_hash`), timing-equalised unknown users, lockout after N failures (policy), per-IP throttling, forced password change for reset or new accounts, minimum length plus a username check.
- **Sessions:** strict mode, HttpOnly, SameSite=Strict, Secure on HTTPS, regenerated on login, idle and absolute timeouts (policy), bound to the user agent. Logout is POST-only.
- **CSRF:** a token on every state-changing form and API call (`X-CSRF-Token`).
- **Authorization:** `src/Security/Authz.php` checks scope on every object (offering, course, program, finding, version) on the server. Faculty see only their offerings, HoDs their department, deans their college. Every denial is audited as `security.access_denied`. Hiding buttons is never the control.
- **Separation of duties:** administrators manage accounts and operations but cannot decide academic matters. QA cannot edit course content. Overrides require a reason.
- **Headers:** CSP (`default-src 'self'`), X-Frame-Options DENY, nosniff, Referrer-Policy, Permissions-Policy, HSTS on HTTPS.
- **Data access:** prepared statements only (`Db` wrapper, emulation off). Output is escaped by default (`View::h`). CSV exports are guarded against formula injection.
- **Audit:** append-only `audit_log` (DB triggers block UPDATE/DELETE), and every row is hash-chained (`SHA-256(prev | canonical row)`). `bin/verify_audit.php` and the admin console re-compute the chain. Automated actions are attributed to *SAQF automation* or the integration, never to a person.
- **Errors:** users see a reference code only. Details (trace, URL, user, request id) go to `system_errors` for IT.

## 6. Configuration and policy

Environment variables (or `config.local.php`): `APP_ENV`, `APP_DEBUG`, `APP_TIMEZONE`, `SAQF_DB_*`, `SAQF_DEMO`, `SAQF_TRUST_PROXY`, `SAQF_DEMO_CLOCK`.

Quality and security thresholds are **institutional policy** stored in `quality_policies` and edited by QA with an audited reason. There are 24 policies: achievement method and thresholds, weight limits, default targets, recurrence cycles, improvement deadlines, sampling rate, minimum sample size, coverage minimum, lockout and session limits, and approval routing switches. Defaults are labelled as not YU-approved until the Deanship of Quality confirms them.

## 7. Testing

- `tests/automation_test.php`: service-level scenarios. Assignment → workspace, CLO change → revalidation, results → achievement, missed target → finding + draft action, recurrence, early warning, rollover, effectiveness, PLO change → impact, override lifecycle, conflict resolution, audit tamper detection.
- `tests/http_smoke.php`: every page for every role renders cleanly. Cross-role and cross-scope access is denied server-side, and anonymous access is redirected. CSRF is enforced, scoped API mutations are checked, and lockout, the error log, audit verification and maintenance mode are exercised.
