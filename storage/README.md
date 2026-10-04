# storage/

Runtime folders. Nothing here is served by the web server and nothing here is committed except
this file and the placeholders.

| Folder | Filled by | Read by |
|---|---|---|
| `inbox/sis/` | the university SIS export job (`terms.csv`, `assignments.csv`) | `FileSisSource` (`SAQF_SIS_SOURCE=file`) |
| `inbox/catalog/` | the Registrar's catalogue export (`institution.json`, `programs/*.json`; template: Administration → Go-live) — used automatically when present, after a completeness check | `CatalogFileSource` |
| `inbox/lms/<term>/<course>/` | the LMS gradebook export job (`*.csv`) | `FileLmsSource` (`SAQF_LMS_SOURCE=file`) |
| `evidence/` | course-file evidence uploaded in SAQF (random file names, owner-only) — in Docker the `saqf_evidence` volume; elsewhere set `SAQF_STORAGE_DIR` | `Evidence` (downloads are authorised and audited) |
| `backups/` | in Docker: the backup service's `last-backup.json` (read-only mount) | *System health → Backups* and the IT alerts |

File formats and the other connectors (SIS API, Moodle, Blackboard): `docs/INTEGRATIONS.md`.
