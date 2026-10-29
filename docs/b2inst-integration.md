# B2INST v3 instrument synchronization

Contract reviewed on 29 October 2026 against the [B2INST v3 records API](https://docs.eudat.eu/b2inst/v3-rest/records/), [v2/v3 comparison](https://docs.eudat.eu/b2inst/v3-rest/json_diff_v2_v3/) and [PIDINST metadata specification](https://docs.pidinst.org/en/latest/white-paper/metadata-schema.html). [B2FIND](https://docs.eudat.eu/b2find/) is EUDAT's separate metadata discovery/indexing service, not the instrument registry used here.

## API contract

The public endpoint remains `GET /api/records`. ERNIE requests `page=1`, `size=100`, `sort=newest`, `allversions=false`, and JSON, without authentication. It imports public, published, undeleted latest versions with PIDINST schema `1.0`. File restrictions do not imply restricted instrument metadata. Record IDs, version parent IDs and instrument PIDs are distinct.

`hits.total` must be a non-negative integer and `hits.hits` a list of objects. Pagination follows the server's `links.next`, including its `allversions=False` spelling. Only HTTPS and the same search scope are accepted. The production aliases `b2inst.pid.gwdg.de` and `b2inst.gwdg.de` are interchangeable; the test host is separate. Custom hosts permit only their own origin. Redirects are rejected, including redirects from Handle APIs; foreign Handle destinations are never fetched.

Duplicate IDs, inconsistent totals, empty intermediate pages, early termination and a changed first-page revision signature abort the candidate. Detected snapshot movement permits one complete restart within the original budget. First-page revalidation cannot detect every same-count change on intermediate pages: the API supplies no snapshot isolation. A successful sync represents the records read during that bounded interval.

## PID decisions

Matching metadata and ePIC Handles are normalized to bare Handles. The provider entry is registration evidence, not a guarantee of continuing resolution. OAI IDs are ignored. Missing provider IDs and conflicting Handle candidates require resolution through `https://hdl.handle.net/api/handles/…`. Only explicit Handle `responseCode=100` means not found; connection errors and other failures abort the import. Positive resolution results expire after at most one hour (default 15 minutes), negative results after one minute; unavailable results are not cached.

Handle results use the `vocabularies` cache tag, so `cache:clear-app vocabularies` and `cache:clear-app all` also clear resolution evidence. Cache drivers without tag support use the same lifetimes and the command's existing whole-store fallback.

Registered targets must match the instrument landing page or a B2INST record in the same version family. Legacy record URLs are read through the registry API and compared using the returned ID/parent. ERNIE never derives a Handle from a short record ID. Conflicts abort publication. DOI and URL identifiers retain their types; unsupported types and malformed records are diagnosed and excluded. Excluding an existing record because it cannot be normalized blocks replacement. An empty candidate cannot replace a non-empty list.

The public GFZ regression fixture preserves the identity facts observed on 29 October 2026:

| Property | Value |
| --- | --- |
| Current record | `7z780-7kj63` |
| Parent | `gqxtc-19e60` |
| Metadata Handle | `21.11157/7z780-7kj63` |
| Registered ePIC Handle | `21.11157/d96a9d31-fd99-4a0c-9b98-514a04b18423` |
| Registered target | `https://b2inst.gwdg.de/records/d96a9d31fd994a0c9b98514a04b18423` |

The legacy API address returned the current record; the ePIC Handle returned `responseCode=1`. The offline fixture uses synthetic descriptive metadata and retains only the public identity facts needed by the regression. Other fixtures are synthetic and exercise the shapes observed at the test service.

## Running and inspecting a sync

Use the repository wrapper on the Docker development stack:

```sh
npm run artisan -- get-pid4inst-instruments --dry-run
npm run artisan -- get-pid4inst-instruments
npm run artisan -- get-pid4inst-instruments --refresh-caches
```

On PowerShell use `npm.cmd` to preserve option forwarding. A dry run prints the candidate comparison without publishing or notifying consumers (short-lived resolver cache entries may be populated). Review PID changes and exclusions before the first live refresh. The report includes server/read/offered counts, additions, changes, removals, PID changes, exclusions, duration, and a count of stored dataset links outside the offered selection. Existing `resource_instruments` are read for this audit, never changed.

All writers use a shared 1,200-second cache lock. Use a shared cache store for deployments with multiple application/worker instances. The JSON snapshot must use local filesystem storage: a temporary sibling file is written and read back, then atomically renamed over `pid4inst-instruments.json`. A failed fetch, normalization, write, backup or rename keeps the prior file available. Changed snapshots preserve the previous file as `pid4inst-instruments.json.previous`.

The `_sync` object contains provenance, comparison results and a `refreshPending` flag. The API explicitly projects only `lastUpdated`, `total`, and `data`, including on cache hits. Old files without `_sync` remain readable. `lastUpdated` tracks the last content change; `_sync.checkedAt` tracks the latest successful full comparison. Record order and usage statistics do not produce content changes.

After publication, the shared sync service invalidates ERNIE's instrument cache and requests refreshes for active ELMO and ELMO-MSL targets through the existing dispatcher. Both direct commands and the background job use this path, avoiding duplicate dispatch. A later cache/dispatch failure is reported as a published snapshot with refresh pending. `--refresh-caches` retries without harvesting again; already queued targets may receive another idempotent refresh. Dispatch success means queued, not confirmed delivery: monitor the existing ELMO refresh jobs/logs for remote failures. ROR and RAiD retain their existing job behavior.

An enabled ELMO target with missing or invalid configuration leaves `refreshPending` set, just like a queue insertion failure. Correct the configuration before retrying `--refresh-caches`; ordinary non-strict dispatcher callers retain their skip behavior.

The quick status endpoint returns `comparisonBasis: "count"`. It compares the remote total with the last successful snapshot's `_sync.serverCount`, including excluded records; legacy snapshots fall back to their offered count. The displayed local count remains the number of accepted instruments. Equal server counts do not prove fresh metadata; administrators and group leaders can always run a full PIDINST refresh. Job results provide the actual published count and timestamp.

## Configuration

| Variable | Default | Purpose |
| --- | --- | --- |
| `B2INST_HOST` | `https://b2inst.pid.gwdg.de` | Production; existing overrides remain effective |
| `B2INST_PAGE_SIZE` | `100` | Records per page, 1–1000 |
| `B2INST_CONNECT_TIMEOUT` | `10` | Connection timeout in seconds |
| `B2INST_TIMEOUT` | `30` | Request timeout in seconds |
| `B2INST_MAX_DURATION` | `900` | Shared harvest/resolver/retry budget, capped at 1100 seconds |
| `B2INST_MAX_PAGES` | `1000` | Maximum pages per snapshot attempt |
| `B2INST_MAX_RECORDS` | `100000` | Maximum advertised total |
| `B2INST_RESOLVER_CACHE_TTL` | `900` | Successful Handle lookup lifetime, capped at 3600 seconds |

Development, Stage and Production Compose files forward all of these options to the application, scheduler and queue containers. `.env.docker.example` lists their defaults. Set overrides in the environment file supplied to Compose (or in the deployment process environment), then recreate the affected containers so Laravel rebuilds its configuration cache with the new values.

Connection failures and HTTP 429/500/502/503/504 permit at most three attempts. Backoff and valid `Retry-After` values consume the shared budget; a delay beyond it fails immediately. A page, record or time limit is an error, never permission to publish a partial result. The job timeout remains 1200 seconds. Use `https://b2inst-test.gwdg.de` for separate live contract checks; automated tests fake all external requests.

## Verification and deployment boundaries

Focused Pest suites cover the v3 client, normalization/identity resolution, command publication, status, jobs and editor API isolation. Vitest covers equal-count refresh and actual job results, alongside the existing selection/hook tests. The complete backend suite also exercises DataCite instrument export/import (`IsCollectedBy` plus Handle). DOI/URL selection support does not broaden the existing DataCite import classification of arbitrary identifiers.

Final local checks use `npm run check:backend` and `npm run check:frontend`, Node from `.node-version`, 2 GB PHP limits, and MySQL 9.7 from `docker-compose.dev.yml`. Complete line coverage remains a CI check.

Public GET checks establish the API shape and migrated identity case. ERNIE's consumer contract tests do not establish the behavior of a separately deployed ELMO UI or a production rollout. Those require a versioned ELMO/stage acceptance run. No B2INST records are created or changed by this integration.

The isolated test-host dry run on 29 October 2026 completed at 06:57 CET: all 815 advertised records were read, yielding 812 instruments in 379.66 seconds. Records `223c8-mht03`, `5ym0a-fqh54` and `a8rcw-s5m75` were excluded because they lacked provider PIDs and their metadata Handles were explicitly not found. The run used an in-memory cache, isolated storage and disabled ELMO dispatch; it published no snapshot. These are test-instance observations, not production inventory counts or an ELMO UI acceptance result.

For rollback, stop concurrent refresh workers, retain both files, restore the previous snapshot and corresponding application configuration/code through the normal deployment process, then invalidate/refresh the caches. Do not change the remote API protocol or rewrite stored dataset PIDs as part of rollback.
