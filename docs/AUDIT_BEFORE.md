# Audit of the original AQMS prototype

This is what we found in `AQMS_project-hackathon-ready.zip` before SAQF 2.0 was built. The original code is preserved in git history (commit *Import AQMS hackathon baseline*, tag `aqms-baseline`).

## What it was

PHP and MySQL with no framework, about 4,600 lines across about 35 pages, and 17 tables (`course_specs`, `course_learning_outcomes`, `clo_plo_mapping`, `assessments`, `assessment_clo`, `program_specs`, `program_learning_outcomes`, `program_kpis`, `quality_improvements`, `course_pdca`, `jahiziah_skills`, `approval_log`, `logs`, `user`…). It had three roles in the user table (`faculty`, `hod`, `qa`) plus a dean page.

## What was good, and kept

- **A deterministic, explainable quality gate** (`includes/quality_gate.php`): red (correction required), amber (review note) or green, with the exact reasons. SAQF keeps the idea and moves it into a central rules engine that runs continuously.
- **Green auto-clearance after HoD sign-off, with a QA sample:** kept as policy-controlled routing.
- **Status history and approval log:** kept and extended into a hash-chained audit log.
- **CLO ↔ PLO mapping, assessment ↔ CLO links, Jahiziah skill tags, PDCA improvement fields, printable course evidence:** kept as concepts and rebuilt on a cleaner data model.
- **Security basics:** password hashing (`password_verify`), CSRF tokens, prepared statements, role checks, and config from environment variables. All kept and hardened.

## Weaknesses found

| Area | Finding | Effect |
|---|---|---|
| Institutional data | 63 courses hard-coded in `includes/yu_courses.php`. 14 programs listed without any link between program and course | Any course could be attached to any program, e.g. an undergraduate course to the MBA. No prerequisites, no electives, no ownership |
| Course creation | Faculty *created* the course record, then chose code, title, credit hours, level, program and required/elective. JS pre-filled some values but they stayed editable | Re-typing data the university already has, with room for inconsistency |
| Specification | One long form (about 66 fields) re-submitted in full. No versions, no semesters | Every term repeats the work. Reviewers re-read everything |
| Validation | Ran when the record was submitted | Errors were found after submission, which is the return loop the project set out to remove |
| Achievement | No student results and no achievement calculation. Only assessment weights | The core quality evidence did not exist |
| Improvement | PDCA text fields inside the spec | Not tracked as actions (no owner, deadline or status) and never compared with later results |
| Program view | KPIs typed in manually (`hod/program_kpis.php`) | The program picture was rebuilt by hand instead of coming from course data |
| Dean view | Raw list of every course spec | No aggregation and no exceptions |
| QA | Reviewed amber records one by one | No categories, no override record, no policy configuration |
| Audit | `logs(username, action)` free text | Did not say what changed, so it was not usable as evidence |
| Security | Role checks on pages but object-level scope was inconsistent (e.g. dean query by department string) | Possible cross-department access by direct URL |
| Maintainability | Several overlapping CSS/JS "fix" files (`final_compliance.js`, `course_remaining_fixes.js`, `*_overrides.css`), duplicated review pages (`course_review.php` / `course_review_clean.php`) | Hard to extend safely |

## Automation audit (how each step was reclassified)

A = automatic (no person), B = assisted (system prepares, person decides), C = human decision, D = exception only.

| Process step | Before | SAQF 2.0 |
|---|---|---|
| Create course record each term | manual | **A**: workspace created from the SIS assignment |
| Course code, title, credits, level, department, program, required/elective, prerequisites | manual (pre-fill) | **A**: synced from study plans, with a provenance label |
| Choose valid programs for a course | manual, unrestricted | **A**: derived from the plans. Invalid choices are not offered |
| Re-enter unchanged CLOs, mappings, assessments | manual | **A**: inherited from the approved version |
| Contact hours | manual | **A**: derived from credit hours (policy weeks) |
| Check weights total 100%, every CLO mapped and assessed, measurable verbs, duplicates | QA after submission | **A**: continuous rules engine, before submission |
| Decide whether a change needs approval | always approval | **A**: unchanged → no approval. Non-academic change → auto-approved. Academic change → HoD |
| HoD review | full document | **C**: the diff only |
| QA review | every amber record | **D**: only warnings, policy exceptions and a random sample of green records |
| CLO→PLO mapping | manual | **B**: suggestions with reasoning, confirmed by faculty |
| Grade import | none | **A**: LMS adapter (simulated) via the scheduler |
| CLO/PLO achievement | none / manual | **A**: calculated with the configurable method |
| Detect missed targets, recurrence, worsening | manual reading | **A**: findings with history |
| Create improvement action | free text | **B**: draft created automatically (owner, due date). Faculty writes the academic response (**C**) |
| Check whether improvement worked | none | **A**: next-term comparison ("improved / similar / declined following the intervention") |
| Program KPIs / PLO picture | manual | **A**: aggregated from course data |
| Reports | form plus print | **A**: generated live. Frozen and sealed when the term closes |
| Rule exceptions (e.g. 70% capstone) | none | **C**: QA override with reason, recorded and reversible |
| Source data conflicts | none | **A**: the authoritative value is applied. **D**: QA is informed |
