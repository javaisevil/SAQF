# storage/

Runtime folders. Nothing here is served by the web server and nothing here is committed except
this file and the placeholders.

| Folder | Filled by | Read by |
|---|---|---|
| `inbox/sis/` | the university SIS export job (`terms.csv`, `assignments.csv`) | `FileSisSource` (`SAQF_SIS_SOURCE=file`) |
| `inbox/lms/<term>/<course>/` | the LMS gradebook export job (`*.csv`) | `FileLmsSource` (`SAQF_LMS_SOURCE=file`) |

File formats and the other connectors (SIS API, Moodle, Blackboard): `docs/INTEGRATIONS.md`.
