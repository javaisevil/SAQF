# University mapping files

Put the mapping file for the university's own SIS or LMS API here, for example `sis.json` and `lms.json`, and point
SAQF at it in `.env`:

```
SAQF_SIS_SOURCE=mapped
SAQF_SIS_MAPPING=config/mappings/sis.json
SAQF_LMS_SOURCE=mapped
SAQF_LMS_MAPPING=config/mappings/lms.json
```

Docker Compose mounts this folder read-only into the app container. A mapping file is data: SAQF validates it and
never executes it. It holds no secrets (tokens and client secrets go in `.env` or secret files), so it can be kept
under version control.

Start from the SIMULATED examples in [`docs/mappings/`](../../docs/mappings/) and check your file offline first:

```
php bin/mapping_check.php config/mappings/sis.json --system=sis --sample=terms=<saved answer>.json
```

Full instructions: [docs/INTEGRATIONS.md, Option C](../../docs/INTEGRATIONS.md).
