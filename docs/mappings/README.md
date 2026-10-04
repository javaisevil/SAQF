# Mapping files (examples)

Both mappings here describe a **SIMULATED** university API (`tests/mock/uni_api.php`), built to prove that SAQF can
read an API whose JSON looks nothing like its own contract, with configuration only. They are **not** Edugate's or
any real LMS's API: that documentation was not available to the project, and nothing was guessed.

| File | What it maps |
|---|---|
| `university-sis.simulated.json` | terms (page/per_page paging, `d/m/Y` dates) and teaching assignments (cursor paging, nested staff objects, joined names, Y/N coordinator flag) |
| `university-lms.simulated.json` | gradebook rows (next-link paging, coded columns `MT`/`FN` mapped to assessment names, the LMS total column skipped, points converted to percentages, API key in a header) |
| `samples/*.json` | saved answers of the simulated API, with **synthetic** student numbers, for the offline checker |

Check a mapping without any network or database:

```
php bin/mapping_check.php docs/mappings/university-sis.simulated.json --system=sis \
    --sample=terms=docs/mappings/samples/terms.json \
    --sample=assignments=docs/mappings/samples/assignments-page1.json \
    --sample=assignments=docs/mappings/samples/assignments-page2.json --term=2026-2
php bin/mapping_check.php docs/mappings/university-lms.simulated.json --system=lms \
    --sample=grades=docs/mappings/samples/marks.json
```

To connect a real system: copy a file, change it to match the university's API documentation, save one anonymised
answer from that API, run the checker on it, then follow
[INTEGRATIONS.md, Option C](../INTEGRATIONS.md#option-c-any-other-json-api-by-configuration-saqf_sis_sourcemapped-saqf_lms_sourcemapped).
