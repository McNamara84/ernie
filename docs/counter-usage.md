# COUNTER usage statistics

ERNIE implements the [COUNTER Code of Practice for Research Data 1.0.1](https://coprd.countermetrics.org/en/1.0.1/10-processing.html)
processing rules for its own public pages. Downloads are validated redirect
clicks, not confirmation of external file transfers. No certification or
DataCite reporting is implied.

## Activate an environment

1. Deploy and run migrations. Tracking remains inactive; legacy counters continue.
2. Configure a dedicated random `COUNTER_SESSION_SECRET` of at least 32 characters
   on every app and scheduler instance. Generate it with a password manager or
   `openssl rand -hex 32`. Do not reuse `APP_KEY` or commit the value.
   Set it in `.env.docker` locally or in the Portainer stack environment for
   Stage/Production. Compose forwards it to the PHP services; recreate the
   containers after changing it. Use a different secret for each environment.
3. Rebuild the Laravel configuration cache through the normal deployment process.
4. Run `php artisan counter:activate`, preferably at a UTC day boundary.
5. Inspect `php artisan counter:tracking status` and verify scheduler operation.

Activation is idempotent. The database stores the original start time permanently.
All old landing-page, download and portal-search writers stop at this boundary,
including stale browser POSTs. Existing daily statistics, `view_count` and
`last_viewed_at` are preserved. Legacy totals are never imported into COUNTER.
Part of the first day/month may be unobserved when activating mid-period.

After activation, pause collection before maintenance or recovery. Keep the
activation state and aggregates when rolling forward with a fix. An older image
that does not understand the activation state must not resume legacy writes.

For the local Docker stack, use the documented
`docker compose --env-file .env.docker -f docker-compose.dev.yml exec app`
prefix for Artisan commands. Keep the MySQL 9.7 server specified in that stack.

## Scheduled processing and recovery

| Command | Schedule (UTC) | Purpose |
| --- | --- | --- |
| `counter:aggregate` | Daily 00:15 | Finalize all missing completed days and rebuild affected months |
| `counter:prune` | Every 30 minutes | Delete raw events before the seven-day deadline, marking unprocessed days incomplete |
| `counter:update-lists` | Monday 04:00 | Fetch, validate and atomically activate all classification lists |
| `counter:tracking status` | Operator check | Show activation, pause, data-through date, aggregation time, list and gap count |

Aggregation holds an exclusive database state lock for one completed day.
Recorders take a shared lock and timestamp events after acquiring it. Daily and
monthly writes, finalization, raw deletion and cache revision changes commit
together. Re-running the command never adds already finalized counts again.
If a run fails, retry `counter:aggregate`; it resumes after the last completed day.
`counter:aggregate --through=YYYY-MM-DD` limits catch-up to a completed UTC day.
`counter:aggregate --rebuild-month=YYYY-MM` reconstructs a month from durable daily
aggregates without needing raw events. Current UTC days cannot be finalized.

Raw events are deleted immediately after successful aggregation. The independent
pruner deletes remaining events at 6 days 23 hours, leaving a margin before seven
days. Run the scheduler continuously and monitor its failures and age of
`last_aggregated_at`; prolonged scheduler/database outages require operator recovery.
Use `counter:tracking pause` before planned interruptions and
`counter:tracking resume` afterwards. Pausing marks the affected UTC days as
incomplete and never reactivates legacy counters. Activation does not implicitly
resume a paused environment.

Record failures log only the exception class, not request contents or SQL bindings.
Check application and scheduler logs for COUNTER failures. List-update failures
preserve the current version. Bundled sources and adaptations are documented in
[`resources/data/counter/README.md`](../resources/data/counter/README.md).

## Data and backups

`usage_events` and `usage_search_events` contain pseudonymous hourly session hashes,
classification versions, target/term hashes and UTC times. They contain no raw IP
addresses or user agents. Normalized search terms are retained for the internal
search report. Durable aggregates contain counts, not session identifiers.

ERNIE's application dump excludes the **data** in both raw event tables with
[`--ignore-table-data`](https://mariadb.com/docs/server/clients-and-utilities/backup-restore-and-import-clients/mariadb-dump#ignore-table-datatable).
Their schema remains in the dump. A client without this capability fails the dump
instead of retaining raw events. The bundled MariaDB client supports it; the
separate MySQL 8.4 legacy client remains exclusively for external legacy exports.
External database/volume backups must likewise exclude raw events or enforce the
same retention deadline. Restoring an ERNIE application dump cannot recover
unaggregated raw events; pause tracking and document the observation gap before
resuming a restored environment.
Use `counter:mark-gap YYYY-MM-DD YYYY-MM-DD` to mark the interval from the first
day after the restored `data_through` date through the recovery date. Existing
counts remain intact; both public cards and dashboard indicate the gap.

## Display and validation

The public card reads Unique / Regular monthly aggregates, shows lifetime totals
and up to twelve calendar months. Partial months, pending data and known gaps are
labelled. IGSNs have `null` request metrics throughout. The shared render cache
includes the aggregate revision; hiding a module changes only display.

`/statistics` is protected by the existing statistics gate. Total/Unique affects
resource metrics; Regular/Machine also affects filtered search counts. Search
counts are not a unique COUNTER dataset metric. The Before COUNTER view has its own
date window and excludes historical IGSN downloads without modifying stored data.
`/statistics/usage.csv` exports all four monthly metrics for the selected access
method and optional `from`/`to` months. IGSN request fields are empty. The export
accepts an omitted `mode` or `mode=counter`; `mode=legacy` is a validation error
(HTTP 422 for JSON clients, or a redirect with a field error for browser requests).
Historical counters have no CSV export.

The public method explanation is `/usage-statistics`. It links to the existing
TYPO3-managed privacy policy; ERNIE neither copies nor edits that policy.

Run focused backend coverage with `npm run test:php -- tests/pest/Feature/Usage`.
Use the repository's MySQL-sensitive usage slice for SQL and lock verification,
the frontend test wrapper for usage/layout tests, and `npm run check:backend` plus
`npm run check:frontend` for final validation.
