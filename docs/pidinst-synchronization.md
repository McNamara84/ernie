# PIDINST synchronization from B2INST and DataCite

The PIDINST **Update Now** button in Settings and `get-pid4inst-instruments` publish one combined instrument vocabulary for ERNIE, ELMO and ELMO-MSL. Existing routes, activation settings and the filename `pid4inst-instruments.json` remain compatible.

## Sources and identity

- B2INST supplies public, published, current instruments under the [B2INST v3 contract](b2inst-integration.md), including its existing Handle registration checks.
- DataCite supplies worldwide public `findable` DOIs with `resourceTypeGeneral=Instrument`, using `GET /dois?resource-type-id=instrument&page[cursor]=1&page[size]=1000`. Drafts, private registered metadata and resources merely mentioning instruments are excluded. [DataCite states](https://support.datacite.org/docs/doi-states), [filters](https://support.datacite.org/docs/api-queries), [pagination](https://support.datacite.org/docs/pagination).
- Identical DOIs are compared without resolver prefixes and ignoring case. An overlap appears once with B2INST's complete normalized metadata and existing ID. DataCite-only records use IDs of the form `datacite:10.xxxx/suffix` and `pidType=DOI`.
- Different PIDs are retained separately even if their names match. No existing resource links or curated names are rewritten.

DataCite titles supply instrument names, including a fallback for records with only alternate/other titles. HostingInstitution contributors supply owners and creators supply manufacturers under the [PIDINST mapping](https://datacite-metadata-schema.readthedocs.io/en/4.7/mappings/pidinst/). Abstract and TechnicalInfo descriptions are retained as text. Unstructured technical details are not guessed into model or measured-variable fields.

## Publication and status

Both complete sources must pass pagination, identity and consistency checks before any snapshot is replaced. Failure of either source preserves the entire previous file and offered vocabulary. A previously populated source cannot silently become empty. New invalid descriptive records are diagnosed and excluded; validation failures on existing offered records abort publication, subject to the documented B2INST unregistered-Handle exception.

The first DataCite page is rechecked for total and metadata revisions. One bounded restart handles detected movement, but this does not provide snapshot isolation across every page. Connection failures and HTTP 429/500/502/503/504 allow at most three attempts. All waits, retries, both registries and Handle lookups share one deadline below the 1200-second job and lock lease. DataCite requests share ERNIE's public API limiter and cooldown with other discovery modules.

The settings count check compares each source with its own last imported raw count. Opposing changes in the two sources therefore still suggest a refresh. Raw source counts include overlaps and exclusions and cannot predict the final deduplicated selection count. Equal counts do not prove fresh metadata. Old single-source files remain readable and require a full combined refresh.

The private `_sync` metadata includes version 2, per-source counts, origins, exclusions and duplicate counts. Public vocabulary responses continue to contain only `lastUpdated`, `total` and `data`, without `_sync` or lookup identifiers. `lastUpdated` tracks content changes; `checkedAt` tracks successful full comparisons.

## Configuration

The public DataCite endpoint is independent of ERNIE's DOI registration mode. Development, Stage and Production Compose forward these settings to the application, scheduler and workers. Recreate affected containers after changing the deployment environment.

| Variable | Default | Purpose |
| --- | --- | --- |
| `PIDINST_MAX_DURATION` | `B2INST_MAX_DURATION`, otherwise `900` | Combined duration in seconds, capped at 1100; blank retains the fallback |
| `PIDINST_DATACITE_HOST` | `https://api.datacite.org` | Public discovery origin; HTTPS required |
| `PIDINST_DATACITE_PAGE_SIZE` | `1000` | Cursor page size, 1–1000 |
| `PIDINST_DATACITE_CONNECT_TIMEOUT` | `10` | Connection timeout in seconds |
| `PIDINST_DATACITE_TIMEOUT` | `30` | Per-request timeout in seconds |
| `PIDINST_DATACITE_MAX_PAGES` | `1000` | Maximum pages per source attempt |
| `PIDINST_DATACITE_MAX_RECORDS` | `100000` | Maximum advertised source records |

Existing `B2INST_*` settings continue to govern B2INST transport and resolution. Limits always fail the candidate instead of publishing a truncated list. Use a shared cache store when multiple instances can refresh the vocabulary. The snapshot needs local filesystem storage for atomic replacement.

## Running and recovery

On PowerShell, use the repository wrappers:

```powershell
npm.cmd run artisan -- get-pid4inst-instruments --dry-run
npm.cmd run artisan -- get-pid4inst-instruments
npm.cmd run artisan -- get-pid4inst-instruments --refresh-caches
```

Review source totals, exclusions, duplicate DOIs, additions, removals and PID changes before the first live publication. A dry run does not publish or notify ELMO; short-lived Handle lookup caches may be populated.

Publication preserves the previous changed file as `pid4inst-instruments.json.previous`. A subsequent cache or ELMO dispatch failure leaves the new snapshot published with `refreshPending=true`. Correct cache/target configuration and use `--refresh-caches` to retry without another harvest. Successful dispatch means queued, not confirmed remote delivery; check the ELMO refresh job logs.

To roll back, stop concurrent refresh workers, preserve both files, restore the matching application/configuration and previous snapshot, then invalidate/refresh caches. Resource instrument PIDs are not part of this rollback.

## DOI instrument import and export

Selections retain their DOI type through saving, loading and DataCite XML/JSON export. Exports mark instrument relations with `IsCollectedBy` and `resourceTypeGeneral=Instrument`; this allows reimport even when the vocabulary is unavailable. Names are filled locally when possible. Legacy unmarked `IsCollectedBy` DOI references are classified as instruments only when a unique active local record matches. An explicit resource type other than `Instrument` keeps a DOI reference in Related Work, including when it matches a local instrument or B2INST DOI alias. Classification adds no external lookups. Existing Handle imports remain supported.

## Validation

Automated tests fake all external sources and cover pagination, retries, metadata normalization, combined publication, preservation on failure, source count comparisons, consumer isolation and XML/JSON round trips. Final local validation uses the wrappers in [local-development.md](local-development.md), Node from `.node-version`, PHP memory limits of 2 GB and MySQL 9.7.

A read-only complete DataCite harvest on 17 November 2026 read and normalized all 2,113 advertised records, including two records with empty optional description placeholders. The check used an isolated in-memory cache and published no snapshot. This is a dated source observation, not an immutable inventory or remote ELMO UI acceptance.
