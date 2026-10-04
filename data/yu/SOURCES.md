# Al Yamamah University data sources

SAQF's institutional data (`data/yu/`) is a **structured snapshot transcribed from public study-plan PDFs and program pages on [yu.edu.sa](https://yu.edu.sa/)**. It was captured on 1 Oct 2026 and contains no private university data.

In SAQF it plays the role of the Registrar / academic-catalogue feed. It is read by `CatalogFileSource` (`src/Integration/SeededSources.php`), the same class that reads a Registrar export placed in `storage/inbox/catalog/` or `SAQF_INSTITUTION_DIR`. For go-live the Registrar replaces this snapshot with its own export in the same layout (checked first with `php bin/pack.php validate`); no live Registrar connection exists today.

**Status of this data:** public information only, transcribed by the project team; **not validated by the Registrar or the Deanship of Quality**. Program outcomes marked as placeholders below are provisional and must be replaced by the program-approved PLOs. Demo people, teaching assignments and student results (`data/demo/`) are fictional or synthetic and are not part of this snapshot.

| Code | Program | Level | Published credits | Plan version | Source PDF | PLOs in snapshot (source) |
|---|---|---|---|---|---|---|
| ACC | Bachelor of Science in Business Administration – Accounting | Undergraduate | 127 | 5.2 | [5.2-Accounting-Program.pdf](https://yu.edu.sa/wp-content/uploads/2026/08/5.2-Accounting-Program.pdf) | 8 — Program Learning Outcomes as published on the YU program page. |
| ARCH | Bachelor of Architecture | Undergraduate | — | Study plan starting 2023 | [Architecture_StudyPlan_2023.pdf](https://yu.edu.sa/wp-content/uploads/2024/11/Architecture_StudyPlan_2023.pdf) | 0 — No PLO statements found on the public program page. |
| CNE | Bachelor of Science in Computer Network Engineering | Undergraduate | 141 | V1.0 | [SP-Network-Engineering-Study-Plan-V1.0-16-August-2025.pdf](https://yu.edu.sa/wp-content/uploads/2025/08/SP-Network-Engineering-Study-Plan-V1.0-16-August-2025.pdf) | 7 — ABET EAC general student outcomes (public criteria). Placeholder until CNE-approved PLOs are synced. |
| EMBA | Executive Master of Business Administration | Postgraduate | 42 | 1.6 | [1.6-EMBA-Study-Plan-1.pdf](https://yu.edu.sa/wp-content/uploads/2025/02/1.6-EMBA-Study-Plan-1.pdf) | 0 — No PLO statements found on the public program page. |
| FIN | Bachelor of Science in Business Administration – Finance | Undergraduate | 127 | 5.2 | [5.2-Finance-Program.pdf](https://yu.edu.sa/wp-content/uploads/2026/08/5.2-Finance-Program.pdf) | 8 — Program Learning Outcomes as published on the YU program page. |
| IE | Bachelor of Science in Industrial Engineering | Undergraduate | 134 | V2.0 | [SP-Industrial-Engineering_Y.2026-July-2026-V-2.0-134-CR.pdf](https://yu.edu.sa/wp-content/uploads/2026/08/SP-Industrial-Engineering_Y.2026-July-2026-V-2.0-134-CR.pdf) | 7 — ABET EAC general student outcomes (public criteria). Placeholder until IE-approved PLOs are synced. |
| LLB | Bachelor of Laws | Undergraduate | 131 | 2024 | [LLB-YU.pdf](https://yu.edu.sa/wp-content/uploads/2024/12/LLB-YU.pdf) | 0 — No PLO statements found on the public program page. |
| LLM | Master of Laws in Business Law | Postgraduate | 36 | 2022-23 | [New-LLM-study-plan-2022-23.pdf](https://yu.edu.sa/wp-content/uploads/2022/08/New-LLM-study-plan-2022-23.pdf) | 0 — No PLO statements found on the public program page. |
| MBA | Master of Business Administration | Postgraduate | 42 | 1.6 | [1.6-MBA-Study-Plan.pdf](https://yu.edu.sa/wp-content/uploads/2025/02/1.6-MBA-Study-Plan.pdf) | 7 — Learning objectives as published on the YU MBA page (condensed wording). |
| MCS | Master in Cyber Security | Postgraduate | 30 | 2026 | [MCS-Study-Plan.pdf](https://yu.edu.sa/wp-content/uploads/2026/07/MCS-Study-Plan.pdf) | 11 — PLOs as published on the YU MCS page. |
| MGT | Bachelor of Science in Business Administration – Management | Undergraduate | 127 | 5.2 | [5.2-Management-Program.pdf](https://yu.edu.sa/wp-content/uploads/2026/08/5.2-Management-Program.pdf) | 8 — Program Learning Outcomes as published on the YU program page. |
| MIS | Bachelor of Science in Business Administration – Management Information Systems | Undergraduate | 127 | 5.2 | [5.2-MIS-Program.pdf](https://yu.edu.sa/wp-content/uploads/2026/08/5.2-MIS-Program.pdf) | 6 — Program Learning Outcomes as published on the YU program page. |
| MKT | Bachelor of Science in Business Administration – Marketing | Undergraduate | 127 | 5.2 | [5.2-Marketing-Program.pdf](https://yu.edu.sa/wp-content/uploads/2026/08/5.2-Marketing-Program.pdf) | 8 — Program Learning Outcomes as published on the YU program page. |
| SWE | Bachelor of Science in Software Engineering | Undergraduate | 142 | V9.8 | [SP-Software-Engineering-Study-Plan-V9.8-05Aug2026.pdf](https://yu.edu.sa/wp-content/uploads/2026/08/SP-Software-Engineering-Study-Plan-V9.8-05Aug2026.pdf) | 5 — ABET CAC general student outcomes, as published by YU for its computing programs. Confirm SWE-approved PLO statements on integration. |

## What was captured per program

- Every course row in the plan: code, title, credit hours, year/semester slot, requirement group (university, college, major, elective group), required/elective, prerequisites (per program), co-requisites, credit-hour thresholds (e.g. "90 CR completed"), and preparatory/foundation courses (excluded from totals).
- Elective slots and the pool of courses that can fill them.
- Program learning outcomes where YU publishes them, with their source noted.
- Owning department, derived from the course prefix (`institution.json → ownership`). This is how SAQF knows which HoD is responsible for a course.

## Known gaps and conflicts SAQF reports itself (instead of hiding them)

- **ARCH, EMBA, LLB, LLM:** no PLO statements found on the public pages. Each raises a *Program has no approved PLOs* data exception for the HoD.
- **CNE, IE:** ABET EAC general outcomes are used as placeholders until the program-approved PLOs are synced. **SWE** uses the ABET CAC outcomes YU publishes for its computing programs.
- **MIS 316:** the MIS plan lists it as 3 CR in the semester grid and 4 CR in its course list. SAQF uses 3 CR (consistent with ACC/FIN/MIS grids) and routes a *data exception* to QA.
- **MGT, MKT:** the listed courses and elective slots add up to 126 CR, but the plans publish 127 CR. SAQF raises a *plan credit mismatch* data exception.
- **Course descriptions** are condensed from YU course-description PDFs where available.

Re-run `php bin/install.php` (or *Admin → Integrations → Sync institutional data now*) after updating the JSON. The sync is idempotent, writes an audit entry and records a `sync_runs` row.
