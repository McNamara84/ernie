# Local Development

## Overview

ERNIE uses a Docker-first local workflow.

- Fast Mode is the default path for day-to-day development.
- Optional profiles are available for assessment-specific and parity-specific work.
- Canonical validation entry points remain `npm run check:backend`, `npm run check:frontend`, and `npm run check:parity`.

Host-side frontend commands require local `node_modules` in the repository checkout. Run `npm ci` after cloning and whenever `package-lock.json` changes. Use `npm install` only when intentionally adding or updating dependencies so npm can update the lockfile.

| Mode               | Purpose                                                                                                                                                   | Command                         |
| ------------------ | --------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------- |
| Fast Mode          | Start the core development stack only                                                                                                                     | `npm run docker:dev:up`         |
| Assessment profile | Start the stack with the F-UJI container for assessment work; also set `FUJI_ENABLED=true` in `.env.docker` if the app should use it                      | `npm run docker:dev:assessment` |
| Parity profile     | Start the stack with the parity profile, which currently adds the F-UJI container; also set `FUJI_ENABLED=true` in `.env.docker` if the app should use it | `npm run docker:dev:parity`     |

Fast Mode is the default because it keeps the profile-gated F-UJI service out of the normal startup path.

## Windows Recommendation

### Preferred: WSL2 checkout

WSL2 is the recommended Windows setup because Docker bind mounts and host-side Node tooling are significantly faster inside the WSL filesystem.

1. Install Docker Desktop with WSL2 integration enabled.
2. Clone the repository inside your WSL home directory, for example `~/src/ernie`.
3. Open the project through VS Code Remote - WSL.
4. Run Docker Compose and host-side Node commands from the WSL shell.
5. Use your Windows browser for `https://ernie.localhost:3333` if preferred.

### Supported fallback: Windows checkout on NTFS

If the repository stays under `D:\` or another NTFS path:

- expect slower bind-mount performance than WSL2
- keep `VITE_USE_POLLING=true` enabled
- use the `public/hot` troubleshooting step below if HMR becomes unreliable

## Quick Start

1. Generate certificates.

    Windows PowerShell:

    ```powershell
    .\docker\generate-certs.ps1
    ```

    WSL, Git Bash, or another POSIX shell:

    ```bash
    ./docker/generate-certs.sh
    ```

2. Create the Docker environment file.

    Windows PowerShell:

    ```powershell
    Copy-Item .env.docker.example .env.docker
    ```

    WSL, Git Bash, or another POSIX shell:

    ```bash
    cp .env.docker.example .env.docker
    ```

3. Install host-side Node dependencies for frontend validation.

    ```bash
    npm ci
    ```

    This installs the local `node_modules` required by ESLint, TypeScript, Vitest, OpenAPI linting, and Playwright.

4. Start Fast Mode.

    ```bash
    npm run docker:dev:up
    ```

5. Trust `docker\traefik\certs\localhost.crt` on Windows if your browser warns about the local TLS certificate.

6. Open the application.

    - Main URL: `https://ernie.localhost:3333`
    - Localhost fallback after switching `ERNIE_DEV_HOST` and `ERNIE_DEV_SESSION_DOMAIN`: `https://localhost:3333`

    If `ernie.localhost` does not resolve, add `127.0.0.1 ernie.localhost` to your hosts file.

7. Create the first administrator account.

    ```bash
    npm run artisan -- add-user "Admin Name" admin@example.com SecurePassword
    ```

The Docker entrypoints install missing Composer dependencies and container-local npm dependencies, run migrations, and seed baseline data when the database is empty. Host-side frontend commands still require the local `npm ci` step above.

For day-to-day Laravel commands, use the npm wrappers. They run inside the app container, and generated files are written into the bind-mounted repository, so they still appear in your host checkout:

```bash
npm run artisan -- make:controller TestController
```

## Profiles And Services

Default Fast Mode services:

- Traefik
- app
- webserver
- vite
- db
- redis
- queue

Optional profiles:

- `assessment` starts the F-UJI container; set `FUJI_ENABLED=true` in `.env.docker` when the app should use it
- `parity` currently starts the same F-UJI container under the parity profile; set `FUJI_ENABLED=true` in `.env.docker` when the app should use it

Common startup commands:

```bash
npm run docker:dev:up
npm run docker:dev:assessment
npm run docker:dev:parity
```

## Command Reference

| Task                                             | Recommended place              | Command                                             |
| ------------------------------------------------ | ------------------------------ | --------------------------------------------------- |
| Start the core stack                             | Host shell                     | `npm run docker:dev:up`                             |
| Install host-side frontend dependencies          | Host shell                     | `npm ci`                                            |
| Start the backend services needed for PHP checks | Host shell                     | `npm run docker:dev:backend:d`                      |
| Stop the stack                                   | Host shell                     | `npm run docker:dev:down`                           |
| Reset Docker volumes                             | Host shell                     | `npm run docker:dev:reset`                          |
| Laravel Artisan                                  | npm wrapper into app container | `npm run artisan -- <command>`                      |
| Example controller generator                     | npm wrapper into app container | `npm run artisan -- make:controller TestController` |
| Composer                                         | npm wrapper into app container | `npm run composer:app -- <command>`                 |
| Pest (2 GB, optimized complete suite)            | Host shell via npm wrapper     | `npm run test:php`                                  |
| Pest deprecation details                         | Host shell via npm wrapper     | `npm run test:php:deprecations`                     |
| MySQL-sensitive Pest slice                       | Host shell via npm wrapper     | `npm run test:php:mysql-sensitive`                  |
| PHPStan                                          | Host shell via npm wrapper     | `npm run phpstan:check`                             |
| Vitest                                           | Host shell                     | `npm run test:run`                                  |
| ESLint check                                     | Host shell                     | `npm run lint:check`                                |
| ESLint auto-fix                                  | Host shell                     | `npm run lint`                                      |
| TypeScript                                       | Host shell                     | `npm run types`                                     |
| Playwright against the dev stack                 | Host shell                     | `npm run test:e2e:devstack`                         |
| Canonical backend validation                     | Host shell                     | `npm run check:backend`                             |
| Canonical frontend validation                    | Host shell                     | `npm run check:frontend`                            |
| Canonical parity validation                      | Host shell                     | `npm run check:parity`                              |

## Environment Files

- `.env.docker` is the Docker-oriented local environment file.
- `.env` is the Laravel application environment used inside the containers.
- The development entrypoint copies `.env.docker` to `.env` when `.env` does not already exist.
- The npm Docker wrappers always pass `--env-file .env.docker` so Compose and Laravel use the same source of truth.
- Docker-managed `node_modules` live in the named Docker volume, not in your host checkout.
- Complete Pest runs copy the current checkout into the Docker-managed
  `ernie-pest-workspace` volume before execution. This avoids repeated
  Windows/macOS bind-mount reads while leaving the development source mount and
  focused test workflow unchanged.

### DataCite import mode

Keep `DATACITE_TEST_MODE=true` for local development and Stage. Eligible imported resources and every newly imported IGSN receive their local landing page, but the import never writes metadata to either DataCite API in this mode.

With `DATACITE_TEST_MODE=false` on Production, the same local landing pages are created and the newly imported, published records enter a separate DataCite synchronization phase. That phase exports the complete ERNIE metadata and changes the DOI target URL to the new landing page. Failed updates do not roll back the import or landing page and can be retried from the completed import dialog.

Production uses separate DataCite Repository accounts for ordinary GFZ DOIs and legacy IGSNs. Configure `DATACITE_USERNAME` / `DATACITE_PASSWORD` for the ordinary DOI repository and `DATACITE_IGSN_USERNAME` / `DATACITE_IGSN_PASSWORD` for the `GFZ.IGSN` repository that owns prefix `10.60510`. ERNIE selects the IGSN credentials automatically for that prefix; using the ordinary DOI credentials results in a DataCite HTTP 403 response.

### Legacy line coverage enrichment

Newly created resources from DataCite are optionally enriched with profile lines from non-empty `sumario-pmd.coverage.wkt` values. ERNIE stores each valid coordinate chain as `geo_type = line` with its ordered points in `polygon_points`, so the Data Editor and landing pages retain the original line geometry. On later DataCite exports, the existing thin-polygon workaround is used because DataCite does not provide a line geometry type.

The enrichment replaces a DataCite bounding box only when all four legacy bounds match and exactly one safe candidate can be identified, using the place description to resolve equal boxes where possible. Other GeoLocations are preserved. Invalid geometry, incomplete bounds, ambiguous matches, and legacy database failures keep the imported DataCite metadata and do not fail the import.

This enrichment runs only while creating a new DataCite resource. Duplicate, skipped, and repair paths do not add lines to resources that already exist in ERNIE; there is no automatic backfill.

### Legacy description break cleanup

New SUMARIO imports correct the duplicated paragraph breaks produced by the legacy XML export before storing descriptions. The correction is pairwise: two consecutive `<br>` tags become one, three become two, four become two, and in general a run of `n` break tags or plain-text newline tokens becomes `ceil(n / 2)`. Whitespace between tags and the variants `<br>`, `<br/>`, and `<br />` are supported; unrelated text and HTML remain unchanged.

The migration adds `resources.legacy_description_breaks_normalized_at` as a durable one-time marker because applying the pairwise rule twice would remove intentional spacing. The cleanup considers both resources marked with `legacy_source = sumario-pmd` and older unmarked resources whose normalized DOI has exactly one match in SUMARIO. Ambiguous DOI matches are reported for manual review. The configured `metaworks` connection must therefore be reachable for every run, although the command never writes to the legacy database.

Deploy the migration, take the normal ERNIE database backup, and audit the complete selection before applying changes:

```bash
npm run artisan -- migrate --force
npm run artisan -- resources:repair-legacy-description-breaks \
    --report=storage/app/legacy-description-breaks-dry-run.csv
npm run artisan -- resources:repair-legacy-description-breaks \
    --apply --after-id=0 --limit=500 --chunk=100 \
    --report=storage/app/legacy-description-breaks-applied.csv
```

Use repeatable `--doi` or `--legacy-id` options for targeted audits. `--after-id` refers to the ERNIE `resources.id`; the command always prints the last scanned ID, including batches containing only non-legacy candidates whose CSV has no data rows. Review the CSV and use that printed ID before continuing with another bounded batch. Apply runs update all descriptions of one resource transactionally, reject concurrent edits, invalidate a changed published landing-page cache, and never process an already marked resource again.

With `DATACITE_TEST_MODE=false`, every changed resource with a DOI is queued for a complete metadata synchronization through the `imports` queue. In test mode the local repair still applies but no DataCite request is made. Sync failures do not roll back local changes; retry them with the run UUID printed by the apply command:

```bash
npm run artisan -- resources:repair-legacy-description-breaks --retry-sync=<sync-run-uuid>
```

### Legacy temporal coverage backfill

The temporal-coverage migration adds nullable columns to `geo_locations`; it does not guess or manufacture values for existing rows. After deploying the migration, the original `sumario-pmd.coverage.start` and `coverage.end` values can be copied into already imported ERNIE resources with the dry-run-first backfill command.

By default, the command considers only resources with the exact `legacy_source = sumario-pmd` and `legacy_source_id` recorded by the SUMARIO import. It matches each legacy coverage to an existing GeoLocation by its spatial coordinates, then uses a normalized description or the original one-to-one position only where that is unambiguous. A legacy coverage without spatial identity is added as a temporal/place-only GeoLocation. Existing equal values are left unchanged. If any temporal field conflicts, the matched GeoLocation remains completely unchanged and is reported for manual review; missing fields are filled only when the complete merge is conflict-free.

Run the migration and audit before applying changes:

```bash
npm run artisan -- migrate --force
npm run artisan -- resources:backfill-legacy-temporal-coverages --report=storage/app/legacy-temporal-coverage-dry-run.csv
```

Review every `manual_review`, `missing_legacy`, and `error` row in the CSV. Then apply the safe rows in bounded batches:

```bash
npm run artisan -- resources:backfill-legacy-temporal-coverages --apply --after-id=0 --limit=500 --chunk=100 --report=storage/app/legacy-temporal-coverage-applied.csv
```

Use repeatable `--doi` or `--legacy-id` options for a targeted rollout. `--after-id` always refers to the ERNIE `resources.id` shown in the report. The command is idempotent and invalidates the rendered cache of a changed published landing page.

Imports created before ERNIE recorded `legacy_source_id` require an explicit DOI fallback. Audit a small selection first because this mode also inspects otherwise unlinked ERNIE resources whose DOI exists in SUMARIO:

```bash
npm run artisan -- resources:backfill-legacy-temporal-coverages --match-by-doi --doi=10.5880/example --report=storage/app/legacy-temporal-coverage-doi-audit.csv
npm run artisan -- resources:backfill-legacy-temporal-coverages --apply --match-by-doi --doi=10.5880/example
```

The application and queue containers need working access to the configured `metaworks` connection while the command runs. The backfill reads but never modifies the legacy database. Take the normal ERNIE database backup before the apply run; a nonzero exit code indicates processing errors, while manual-review rows deliberately remain unchanged and do not fail the complete run.

### Exact subject duplicate cleanup

Metadata imports classify each DataCite subject only once. Resources imported before that fix can be audited with a dry-run-first command. By default it considers controlled subjects only and treats rows as duplicates only when value, language, scheme, scheme URI, value URI, classification code, and breadcrumb path are all exactly equal. The smallest Subject ID survives.

Audit GEMET and MSL first and retain the CSV for review:

```bash
npm run artisan -- subjects:deduplicate \
    --scheme="GEMET - GEneral Multilingual Environmental Thesaurus" \
    --scheme="EPOS MSL vocabulary" \
    --report=storage/app/subject-duplicates-dry-run.csv
```

After taking the normal database backup and reviewing the report, apply the same bounded selection:

```bash
npm run artisan -- subjects:deduplicate --apply \
    --scheme="GEMET - GEneral Multilingual Environmental Thesaurus" \
    --scheme="EPOS MSL vocabulary" \
    --after-resource-id=0 --limit=500 --chunk=100 \
    --report=storage/app/subject-duplicates-applied.csv
```

The repeatable `--doi` option narrows an audit further. Use `--include-free` only when exact free-keyword duplicates should also be considered. Apply runs are transactional per resource and idempotent; they remove stale Subject assistance rows and invalidate affected keyword and landing-page caches. A nonzero exit code indicates processing errors.

### Testing Data Editor registration safely

Keep `DATACITE_TEST_MODE=true` and use test Repository credentials when exercising the Data Editor's `Register` or `Update Metadata` actions locally. `Validate`, `Save Draft`, autosave, `Preview LP`, and `Show LP` are local-only actions and must not produce a DataCite request. The two DataCite write actions run complete client validation, show an explicit confirmation before their action-specific save, and automatically continue through landing-page setup when a page is missing.

After a new test DOI is registered, the success dialog displays the number of locally published Resources plus published IGSNs. This is deliberately smaller than or equal to the combined sidebar badges: a record counts only when it has a non-empty DOI and a published landing page. Draft, curation, and review records are excluded. The dialog redirects to `/resources` after five seconds; operating-system reduced-motion settings suppress confetti without changing the result or countdown.

There is no separate post-import sync flag: `DATACITE_TEST_MODE` is the only switch. The queue worker must consume the `imports` queue so the bounded synchronization jobs can complete.

### Testing queued IGSN batch registration safely

The IGSN list can queue up to 1000 selected samples for DataCite registration or metadata updates. Keep `DATACITE_TEST_MODE=true`, configure valid DataCite Test credentials, and use only disposable identifiers when testing this workflow locally. The start request returns `202 Accepted`; it never performs the DataCite writes in the web request.

Each run and its ordered items are stored in `igsn_registration_runs` and `igsn_registration_items`. One short-lived job processes one item and dispatches the next job on the queue configured by `DATACITE_QUEUE`, which defaults to `datacite`. Both the app and queue services must use a persistent queue connection such as `database`; `sync` and `null` are deliberately rejected. Every Docker environment forwards `DATACITE_QUEUE` to both services, and its worker consumes the configured queue.

The effective DataCite test/production mode and endpoint are snapshotted when a run starts. Credentials are never stored with the run and are read from the current server configuration by each job. A changed mode or endpoint pauses the run before another write. Beginner runs remain on DataCite Test even when a worker executes without a signed-in browser user.

Closing the progress dialog, navigating away, restarting a worker, or reloading the browser does not cancel a run. Return to `/igsns` and use **View registration progress** to inspect it. Cancellation takes effect between external requests. Failed items can be retried without resending successful items; a resumed item also checks whether DataCite already accepted an earlier create request before it attempts another create.

For a safe local check:

1. Start the Docker stack and confirm the queue service consumes `datacite`.
2. Create a small set of synthetic IGSNs with published landing pages.
3. Confirm the progress dialog says **DataCite Test**, then use **Register Selected**.
4. Close and reopen the dialog while the run is active, and verify the same run and counters return.
5. Exercise cancellation or retry only with disposable test identifiers. Never use production credentials for automated or local development tests.

### Legacy IGSN enrichment

Single-IGSN imports preload their complete DataCite family and the corresponding legacy DIF metadata before writing resources. The public legacy IGSN portal is the mandatory source for this strict preflight. If the portal is unreachable, returns invalid JSON, or contains malformed DIF data, the complete single import fails without creating any new resource, IGSN metadata, relationship, datacenter assignment, or landing page. Existing ERNIE resources remain unchanged and are not backfilled.

The default portal endpoint and retry settings are defined by:

- `GFZ_IGSN_PORTAL_PROXY_URL`
- `GFZ_IGSN_PORTAL_CONNECT_TIMEOUT`
- `GFZ_IGSN_PORTAL_TIMEOUT`
- `GFZ_IGSN_PORTAL_RETRY_TIMES`
- `GFZ_IGSN_PORTAL_RETRY_SLEEP_MS`
- `GFZ_IGSN_PORTAL_RETRY_JITTER_MS`

Keep the portal URL on HTTPS. Retries include the observed failure mode in which the proxy answers with HTTP 200 but the body is not valid JSON. The queue job records a stable `error_code` such as `legacy_source_unavailable` or `legacy_invalid_payload` in the import progress instead of silently creating a DataCite-only partial record.

Authenticated Solr and the direct legacy database remain optional enrichment sources for non-single imports. Enable `IGSN_LEGACY_DB_ENABLED` only after the configured TLS and credentials have been verified from both the app and queue containers. Network reachability of port 3306 alone does not prove that the legacy database connection works.

Both app and queue services must use `QUEUE_CONNECTION=database`, and the worker command must consume `imports`. The single-import start endpoint returns `202 Accepted`; later portal or persistence failures are reported through the import status endpoint.

#### Repair legacy IGSN classifications

New legacy imports preserve all supported classifications from every `<sample>` block in their original order. This includes the Medusa, Sonne273, Earth Shape, and ICDP values covered by issues #1191, #1200, #1202, and #1210. Unknown controlled values remain rejected and are reported without rolling back unrelated DIF metadata.

Existing IGSNs are deliberately skipped by the DataCite importer, but they no longer need to be deleted and reimported to recover classifications. The dedicated command audits every imported IGSN, fetches its DIF metadata from the public legacy portal in batches of at most 100, and only appends missing classifications or fills an empty classification type. Existing values, positions, and non-empty types are never removed or overwritten. The command is a dry run unless `--apply` is supplied:

```bash
npm run artisan -- igsn:backfill-classifications --doi=ICDP5054ES1O201
npm run artisan -- igsn:backfill-classifications --report=storage/app/igsn-classification-dry-run.csv
npm run artisan -- igsn:backfill-classifications --apply --report=storage/app/igsn-classification-applied.csv
```

Deploy the updated classification catalogs and application code before running the command. Review rejected values, type conflicts, missing DIF documents, and technical errors in the dry-run report before applying changes globally. Use `--limit` for a bounded run, repeat `--doi` for selected handles or DOIs, and resume after the last completed Resource ID with `--after-id`. A successful apply invalidates only changed published landing-page caches. A second global dry run must report no remaining supported classifications as `would_update`.

The command also repairs still-incomplete classification data from the earlier issues, so their former delete-and-reimport procedure is obsolete. The separate `igsn_metadata.user_code` schema correction from issue #1192 still requires its existing migration. The rejected vocabulary request from issue #1201 remains intentionally excluded.

#### Legacy IGSN sample images

When a legacy DIF record contains a sample image, the import stores its validated source description with the IGSN metadata. Known GFZ Data Services images are downloaded only after the metadata transaction has committed and are served from the persistent Laravel `public` disk. Known ICDP image URLs are normalized to `https://data.icdp-online.org/...` and remain external. Unknown hosts, unsafe paths, placeholders, invalid MIME types, oversized files, and failed downloads never produce a public image card and do not roll back an otherwise successful metadata import. The completed import dialog reports those image failures separately so they can be retried through the backfill.

Configure the storage disk and download limits with `IGSN_IMAGE_DISK`, `IGSN_IMAGE_CONNECT_TIMEOUT`, `IGSN_IMAGE_TIMEOUT`, and `IGSN_IMAGE_MAX_BYTES`. The default size limit is 20 MiB and only validated JPEG files are accepted. In production, the selected disk must be persistent, backed up, and publicly linked in the same way as the existing Laravel `public` disk.

The backfill deliberately considers only IGSNs that already exist in ERNIE. It is a dry run unless `--apply` is supplied:

```bash
npm run artisan -- igsn:backfill-images --doi=GFSO273N39
npm run artisan -- igsn:backfill-images --apply --after-id=0 --chunk=100 --report=storage/app/igsn-image-backfill.csv
```

Use `--limit` for bounded rollout batches, repeat `--doi` to select multiple handles or DOIs, and use `--force` only when already processed images must be revalidated or replaced. A failed run can resume after the last reported resource ID with `--after-id`. The command is idempotent; missing legacy DIF records and records without an image are reported separately from real processing failures.

IGSN landing-page templates expose every IGSN module, including Sample Image and Location, in a shared two-column editor. Modules can be reordered within a column or moved across columns; each module must occur exactly once across the saved layout. The built-in `Templates IGSN` copy template places Sample Image in the right column immediately before Location.

Cloned Resource landing-page templates use the same two-column interaction, but expose only Resource modules. Every module can be reordered or moved between columns and must occur exactly once across the complete layout. Description, people, funding, keyword, and metadata-download modules retain the existing shared metadata card in each occupied column; moving them does not create separate cards. The built-in `Templates Resources` copy template remains immutable and keeps the canonical layout used for new clones.

### DataCite landing-page domain migration

The admin-only actions on `/resources` and `/igsns` use a persistent queue and the shared application cache. The Docker worker consumes the dedicated `datacite` queue. Queue connections whose configured driver is `sync` or `null` are rejected regardless of the connection name, because the run must survive request timeouts, browser navigation, deployments, and worker restarts.

Keep the safe rate defaults from `.env.example`: at most 300 authenticated requests per rolling five-minute window, at least one second between requests, concurrency one, a 10-second connection timeout, and a 30-second request timeout. Target landing-page reachability checks have separate configurable connection and total timeouts of three and eight seconds. All DataCite writes in ERNIE share this limiter. Redis or another cache shared by every web and queue process is therefore required; an in-process array cache is not safe outside automated tests.

After a domain move, the configured HTTPS `APP_URL` is the sole source for every new landing-page URL. There is deliberately no separate old-host or expected-new-host setting. `DATACITE_USER_AGENT_EMAIL` should identify the operational contact DataCite can reach.

For a local dry run, use DataCite test credentials and `DATACITE_TEST_MODE=true`, configure `APP_URL` to the HTTPS base URL being tested, and make sure the generated target URLs are actually reachable from the app/queue containers. Never place production credentials in a local environment.

## Troubleshooting

### `419 Page Expired`

- A plain URL switch to `https://localhost:3333` is not enough while `ERNIE_DEV_SESSION_DOMAIN=ernie.localhost`.
- For a localhost fallback, set `ERNIE_DEV_HOST=localhost` and `ERNIE_DEV_SESSION_DOMAIN=localhost` in `.env.docker`, keep `localhost:3333` in `ERNIE_DEV_STATEFUL_DOMAINS`, then restart the stack.

### `public/hot` is missing on Windows

Docker Desktop can fail to sync the file back to the host even when it exists in the container.

```powershell
docker compose --env-file .env.docker -f docker-compose.dev.yml exec vite sh -c 'echo "https://ernie.localhost:3333" > /var/www/html/public/hot'
```

### F-UJI is not reachable

That is expected unless the matching profile was started:

- `npm run docker:dev:assessment`
- `npm run docker:dev:parity`

For F-UJI specifically, the app still treats the integration as disabled until `FUJI_ENABLED=true` is set in `.env.docker` and the stack is restarted.

### The first startup is slow

The initial boot may still need to:

- build images
- install Composer dependencies
- install npm dependencies
- run migrations
- seed baseline data

Subsequent startups are usually much faster because Docker volumes keep `vendor`, `node_modules`, and the MySQL data directory.

## MySQL-Sensitive Pest Slice

The default local Pest loop remains SQLite-backed.

Use the dedicated MySQL-backed slice only when driver-sensitive verification is required:

```bash
npm run test:php:mysql-sensitive
```

This command:

- starts the backend containers if needed
- creates an isolated `ernie_test` schema inside the local MySQL container
- runs the current explicit MySQL-sensitive migration file slice with a schema reset before each file

It does not reuse the regular development schema. For broader testing guidance, see [testing.md](testing.md).
