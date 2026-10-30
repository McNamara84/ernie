# Local Development

## Overview

ERNIE uses a Docker-first local workflow. See [Branching and Releases](branching-and-releases.md)
for `main` bugfixes, `dev` features, automatic synchronization, and publishing.
To test immutable Production candidates while Stage follows `dev`, use
[Release Candidate Testing](release-candidate-testing.md).

For PIDINST instrument refreshes, dry runs, identity checks and recovery, see
[B2INST v3 Integration](b2inst-integration.md).

For environment-specific COUNTER activation, aggregation, retention and recovery,
see [COUNTER Usage Statistics](counter-usage.md). New usage collection stays
inactive until explicitly activated; migrations alone preserve legacy counting.

- Fast Mode is the default path for day-to-day development.
- Optional profiles are available for assessment-specific and parity-specific work.
- Canonical validation entry points remain `npm run check:backend`, `npm run check:frontend`, and `npm run check:parity`.

Host-side frontend commands require local `node_modules` in the repository checkout. Run `npm ci` after cloning and whenever `package-lock.json` changes. Use `npm install` only when intentionally adding or updating dependencies so npm can update the lockfile.

| Mode               | Purpose                                                                                                                                                   | Command                         |
| ------------------ | --------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------- |
| Fast Mode          | Start the core development stack only                                                                                                                     | `npm run docker:dev:up`         |
| Assessment profile | Start F-UJI and the dedicated persistent assessment workers; also set `FUJI_ENABLED=true` in `.env.docker`                                         | `npm run docker:dev:assessment` |
| Parity profile     | Start the parity stack including F-UJI and the dedicated persistent assessment workers; also set `FUJI_ENABLED=true` in `.env.docker`                | `npm run docker:dev:parity`     |

Fast Mode is the default because it keeps the profile-gated F-UJI service out of the normal local startup path. These profiles apply only to `docker-compose.dev.yml`; the Portainer-oriented Stage and Production Compose files include the assessment services by default.

## Windows Recommendation

### Preferred: WSL2 checkout

WSL2 is the recommended Windows setup because Docker bind mounts and host-side Node tooling are significantly faster inside the WSL filesystem.

1. Install Docker Desktop with WSL2 integration enabled.
2. Clone the repository inside your WSL home directory, for example `~/src/ernie`.
3. Open the project through VS Code Remote - WSL.
4. Run Docker Compose and host-side Node commands from the WSL shell.
5. Use your Windows browser for `https://ernie.localhost:3333` if preferred.

The repository recommends Microsoft's TypeScript 7 extension for VS Code and enables its native language server with the workspace-local TypeScript 7 package. Accept the workspace extension recommendation after opening the checkout. Run `npm ci` first so the configured SDK path is available.

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

    Use Node **26.10.0** from `.node-version` and npm **12.2.0** from
    `package.json`. After switching Node, install the pinned npm version:

    ```bash
    npm install --global npm@12.2.0
    npm ci
    ```

    This installs the local `node_modules` required by Oxlint, Oxfmt, TypeScript, Vitest, OpenAPI linting, and Playwright.

4. Install the repository's Git pre-commit hook for this checkout.

    ```bash
    npm run hooks:install
    ```

    The hook checks staged whitespace, PHP style with Pint, frontend lint and formatting, and OpenAPI changes when applicable. PHP checks start the Docker backend. It rejects partially staged files that need checking because the tools read the worktree. Full tests remain in the validation commands and CI.

    On Windows, the hook uses `npm.cmd` so commits from GitHub Desktop do not depend on Bash or WSL. If an older hook reports `WSL ... execvpe(/bin/bash) failed`, update `.githooks/pre-commit` to the current version.

    Flatpak Git clients run the hook on the host through `flatpak-spawn --host`, where Docker, Node, and npm are installed. Git's repository and index environment is forwarded so the checks also respect an alternate staging index. The GitHub Desktop Flatpak already permits host execution; no additional Flatpak override is needed.

    On Linux and macOS, the hook also checks `$VOLTA_HOME/bin` (or `~/.volta/bin`) when Node or npm is missing from the Git client's `PATH`, so a desktop Git client can use an existing Volta installation without loading shell startup files. The `volta` pins in `package.json` select Node **26.10.0** and npm **12.2.0** automatically for this checkout. Keep those pins aligned with `.node-version` and `packageManager` when updating the toolchain. Other installations must make both Node and npm available on the Git client's `PATH`. Use the Node version pinned in `.node-version` and restart the Git client after changing your Node installation or `PATH`.

5. Start Fast Mode.

    ```bash
    npm run docker:dev:up
    ```

6. Trust `docker\traefik\certs\localhost.crt` on Windows if your browser warns about the local TLS certificate.

7. Open the application.

    - Main URL: `https://ernie.localhost:3333`
    - Localhost fallback after switching `ERNIE_DEV_HOST` and `ERNIE_DEV_SESSION_DOMAIN`: `https://localhost:3333`

    If `ernie.localhost` does not resolve, add `127.0.0.1 ernie.localhost` to your hosts file.

8. Create the first administrator account.

    ```bash
    npm run artisan -- add-user "Admin Name" admin@example.com SecurePassword
    ```

The Docker entrypoints install missing Composer dependencies and container-local npm dependencies, run migrations, and seed baseline data when the database is empty. Host-side frontend commands still require the local `npm ci` step above.

For day-to-day Laravel commands, use the npm wrappers. They run inside the app container, and generated files are written into the bind-mounted repository, so they still appear in your host checkout:

```bash
npm run artisan -- make:controller TestController
```

### Download URL suggestion default

Until an order has been saved in **Settings / Download URL suggestions**, the
installation offers `https://datapub.gfz.de/download` first, even with no existing
downloads. This also applies to upgrades without a stored order; no extra seeder
or manual setup is required.

Other installations can set `LANDING_PAGE_DEFAULT_DOWNLOAD_URL_PREFIX` in their
environment (`.env.docker` for local Docker) to another HTTP(S) prefix. An empty
value disables this default. The development, stage and production Compose files
pass this variable through and preserve explicit empty values. Apply environment
changes by recreating the affected containers; refresh Laravel's config cache if
the deployment caches configuration.

A saved order always takes precedence, including an explicitly empty list. Later
configuration changes or deployments do not overwrite it. Domains observed in
existing download URLs still follow the configured suggestions.

## Profiles And Services

Default Fast Mode services:

- Traefik
- app
- webserver
- vite
- db
- redis
- queue
- scheduler

The scheduler waits for the application and database to become healthy, refreshes the persisted license usage ranking once at startup, and then runs Laravel's configured schedule continuously. The ranking is refreshed weekly after that initial backfill, so opening the Data Editor never needs to aggregate all stored resource-license associations.

Optional profiles:

- `assessment` starts F-UJI plus the dedicated `assessment-queue` workers; set `FUJI_ENABLED=true` in `.env.docker` when the app should use it
- `parity` starts the same assessment services together with the parity profile; set `FUJI_ENABLED=true` in `.env.docker` when the app should use it

These are development profiles only. Stage and Production deploy F-UJI and the assessment workers as regular services so Portainer creates the complete runtime stack without profile activation.

Common startup commands:

```bash
npm run docker:dev:up
npm run docker:dev:assessment
npm run docker:dev:parity
```

### Curation mail queue

The standard stack includes `curation-queue` for review invitations, review-link
migration emails, mentions and assignment notifications. It runs
`php artisan queue:work curation --queue=curation --sleep=1 --tries=3 --timeout=60`.
The `curation` connection always uses the application's database and its `jobs`
table, even when `DB_QUEUE_CONNECTION`, `DB_QUEUE_TABLE` or `DB_QUEUE` sends the
general worker elsewhere. Queue insertion and the curation audit receipt share
one transaction; rolling it back removes both. Keep `after_commit=false` on this
connection so a queue insertion failure also rolls back the receipt.

The worker's 60-second timeout is shorter than the 90-second reservation, and
Compose allows 75 seconds for graceful shutdown. New messages use the dedicated
`curation` queue so the general worker does not reserve them with its longer
timeout. Stage, Production and the isolated release candidate include this worker
using the same application image and mail settings as the general worker.

After pulling the change, start the consumer with:

```bash
docker compose --env-file .env.docker -f docker-compose.dev.yml up -d curation-queue
```

Earlier issue-409 builds queued these messages on the application database's
`default` queue. Existing jobs retain that routing: let the general worker finish
them if it uses that database. With a separate general queue database, drain the
old application `default` queue using the `curation` connection before completing
the rollout. Do not delete pending jobs or their audit receipts.

### Assistant discovery queue

The standard stack includes `assistance-queue`, separate from the general queue
and optional assessment workers. All generic and legacy discovery jobs use the
persistent `assistance` connection. Its queue name defaults to `assistance` and
can be changed with `ASSISTANCE_QUEUE`; the app and worker must use the same value.
`ASSISTANCE_JOB_TIMEOUT` defaults to 3600 seconds, and the reservation window is
always at least 60 seconds longer. `ASSISTANCE_WORKER_CPUS` defaults to 0.5 CPU.
The general database queue reservation defaults to 7260 seconds in Compose to
cover its existing longest worker timeout.

After pulling this change, recreate the stack to start the new consumer. Existing
discovery jobs on `default` are not moved and must finish on the general worker.
Do not clear queued work to change routing. See the
[production runtime notes](production-runtime-performance.md#editor-requests-during-background-checks)
for interactive API budgets and provider cooldown behavior.

Run the separate-process MySQL 9.7 regression checks with:

```bash
npm run test:php:mysql-sensitive -- --slice editor-background
```

These checks pause background work inside its HTTP call and save the same resource
through an authenticated editor request on an independent database connection.

### Funding award URI enrichment

The optional Award URI assistant uses Crossref Grants and the public CORDIS SPARQL
endpoint. Set `FUNDING_AWARD_URI_ENABLED=true` in `.env.docker`, configure a monitored
`CROSSREF_MAILTO`, and recreate the application and Assistance worker. Keep the
existing Redis cache available for shared provider locks and cooldowns. ROR-based
inputs also require the existing ROR/FundRef index to be current.

Discovery proposes values only for empty award URI fields. Review is manual and
retains source evidence and reviewer identity permanently. Partial checks expose
source errors and can be resumed with Check. See the
[award URI operating guide](funding-award-uri-enrichment.md) for source limitations,
cache and timeout defaults, deployment/rollback steps and focused test commands.

### Persistent FAIR assessment queue

Resource and IGSN assessments use database-backed runs and one short queue job per resource. Closing the browser, restarting a worker, or deploying the application therefore does not discard the run snapshot or its progress. Opening `/assessment` again reconnects to an active run; pressing `Check` while a run is active returns that same run, and a paused run is resumed instead of replaced.

The assessment profile starts one `assessment-queue` worker by default. Its shared Redis limiter permits at most 80 F-UJI requests per rolling 60-second window and spaces request starts by at least 750 ms. This avoids overlapping CPU-intensive F-UJI evaluations on single-CPU hosts. Increase `FUJI_ASSESSMENT_CONCURRENCY` only after measuring F-UJI latency and resource use.

The relevant settings are documented in `.env.docker.example`. Keep these relationships intact when changing them:

- `FUJI_ASSESSMENT_ITEM_TIMEOUT` must remain higher than `FUJI_TIMEOUT`.
- `FUJI_ASSESSMENT_LEASE_SECONDS` and `FUJI_ASSESSMENT_QUEUE_RETRY_AFTER` must remain higher than the item timeout.
- `FUJI_ASSESSMENT_QUEUE_CONNECTION` must use a persistent driver; `sync` and `null` are rejected.
- Every web and assessment-worker process must use the same Redis cache so request limiting and start locks are shared.

The default HTTP timeout is 300 seconds. A cURL 28 response timeout that consumes this full window becomes a service error after one attempt instead of blocking the worker for three identical attempts. Short DNS/connect failures and retryable HTTP responses remain bounded by `FUJI_ASSESSMENT_MAX_ATTEMPTS`. The run UI reports service errors separately and can retry only those items after a run completes.

The local assessment profile builds `Dockerfile.fuji` from the pinned F-UJI 4.0.1 base image. That build installs Chromium because the upstream image starts Playwright but does not include its browser executable. The first build therefore downloads browser packages. Stage and Production receive the same derived image through the digest-pinned image publication workflow.

The derived image refreshes Debian packages, applies the compatible Python updates
in `docker/fuji/requirements.txt`, and checks Python dependency consistency
before removing the build-only `pip` installer and `ensurepip` bootstrap archive.
This removes the old libraries bundled inside pip; updating F-UJI's installed
`setuptools` alone would not replace those copies. Runtime dependencies, including
the `setuptools` package used by Tika, remain installed. Change dependencies through
the Dockerfile and rebuild the image. CI checks that F-UJI dependencies import,
the certificate bundle excludes the compromised emSign ECC Root CA - G3, and
Chromium launches without the installers, then scans the complete image alongside
the application and Nginx images before Stage publication.

F-UJI 4.0.1 restricts Pandas, Levenshtein, Tika, and Uvicorn to specific release
lines; Playwright also fixes its supported Pyee major. Their newer incompatible
releases require an upstream compatibility update. The image build keeps these
constraints and fails if the installed Python packages become inconsistent.

For a one-worker local load comparison, run:

```bash
docker compose --env-file .env.docker -f docker-compose.dev.yml --profile assessment up --build --scale assessment-queue=1
```

## Command Reference

### Contact person index

Admins, Group Leaders and Curators reach **Contact Persons** under **Tools** in
either sidebar workspace. The overview and exact contact filters read the
derived `resource_contact_points` table. Deploy the migration and build the index
in the deployment maintenance window before reopening the application:

```bash
npm run artisan -- migrate --force
npm run artisan -- contact-persons:rebuild
```

The rebuild is idempotent, processes resources in batches and replaces only the
derived contact index. It does not edit person identities or metadata. Run it
again after a bulk data repair that bypasses model events. Resource edits and
contact-role changes refresh the index automatically. Changes to shared person
names or ORCIDs propagate through the existing listing dependency queue; ensure
those workers finish before judging the new grouping. Restart workers with the
new application code during deployment. Rollback removes only the derived table;
restore the previous application code before rolling back its migration.

Focused validation uses `npm run test:php -- tests/pest/Feature/ContactPersons`,
`npm run test:php:mysql-sensitive -- --slice contact-persons` and
`npm run test:e2e:devstack -- workflows/contact-persons.spec.ts`. On Windows use
`npm.cmd` if PowerShell consumes arguments after `--`. The browser fixtures are
included in `PlaywrightTestSeeder` through `ContactPersonsPlaywrightSeeder`.

### Development commands

| Task                                             | Recommended place              | Command                                             |
| ------------------------------------------------ | ------------------------------ | --------------------------------------------------- |
| Start the core stack                             | Host shell                     | `npm run docker:dev:up`                             |
| Install host-side frontend dependencies          | Host shell                     | `npm ci`                                            |
| Start the backend services needed for PHP checks | Host shell                     | `npm run docker:dev:backend:d`                      |
| Stop the stack                                   | Host shell                     | `npm run docker:dev:down`                           |
| Reset Docker volumes                             | Host shell                     | `npm run docker:dev:reset`                          |
| Laravel Artisan                                  | npm wrapper into app container | `npm run artisan -- <command>`                      |
| Example controller generator                     | npm wrapper into app container | `npm run artisan -- make:controller TestController` |
| Refresh the license usage ranking                | npm wrapper into app container | `npm run artisan -- rights:update-usage-count`      |
| Composer                                         | npm wrapper into app container | `npm run composer:app -- <command>`                 |
| Pest (2 GB, optimized complete suite)            | Host shell via npm wrapper     | `npm run test:php`                                  |
| Pest deprecation details                         | Host shell via npm wrapper     | `npm run test:php:deprecations`                     |
| Offline DataCite / Schema.org JSON-LD semantics    | Host shell via npm wrapper     | `npm run test:jsonld`                               |
| MySQL-sensitive Pest slice                       | Host shell via npm wrapper     | `npm run test:php:mysql-sensitive`                  |
| PHPStan                                          | Host shell via npm wrapper     | `npm run phpstan:check`                             |
| Vitest                                           | Host shell                     | `npm run test:run`                                  |
| Vitest performance diagnosis                     | Host shell                     | `npm run test:doctor`                               |
| Oxlint check                                     | Host shell                     | `npm run lint:check`                                |
| Oxlint auto-fix                                  | Host shell                     | `npm run lint`                                      |
| Oxfmt check                                      | Host shell                     | `npm run format:check`                              |
| Oxfmt write                                      | Host shell                     | `npm run format`                                    |
| TypeScript                                       | Host shell                     | `npm run types`                                     |
| TypeScript application watcher                   | Host shell                     | `npm run types:watch`                               |
| TypeScript test watcher                          | Host shell                     | `npm run types:watch:test`                          |
| Playwright against the dev stack                 | Host shell                     | `npm run test:e2e:devstack`                         |
| Canonical backend validation                     | Host shell                     | `npm run check:backend`                             |
| Canonical frontend validation                    | Host shell                     | `npm run check:frontend`                            |
| Canonical parity validation                      | Host shell                     | `npm run check:parity`                              |

## Environment Files

JSON-LD exports use `APP_URL` as their canonical public origin. The versioned context
is served anonymously at `/metadata/contexts/datacite-4.7-v1.jsonld`. Leave
`DATACITE_LINKED_DATA_CONTEXT_URL` unset unless an independently verified compatible
mirror is required. Docker forwards this optional override from `.env.docker` to
the app and workers; recreate them after changing it. See
[the JSON-LD profile and release checks](jsonld-profile.md).
`npm run check:backend` includes the offline semantic check; its Node step needs the
host dependencies installed with `npm ci`.

- `.env.docker` is the Docker-oriented local environment file.
- `.env` is the Laravel application environment used inside the containers.

### Portal basemap

The DOI and IGSN portal maps use the keyless OpenFreeMap vector basemap so geographic labels can prefer English and fall back to the local name when no English value exists. No account or API key is required. The public OpenFreeMap Liberty style is the default:

```dotenv
PORTAL_MAP_BASEMAP_STYLE_URL=https://tiles.openfreemap.org/styles/liberty
```

Override the URL only to use a compatible style served by a self-hosted OpenFreeMap instance. Arbitrary MapLibre styles are not supported because the portal displays the fixed OpenFreeMap, OpenMapTiles, and OpenStreetMap attribution required by this basemap. The public service has no SLA, so self-hosting remains available if operational guarantees become necessary.

If the configured style or its tiles cannot be loaded, the portal remains usable and its ERNIE data overlays can still load, but the map shows the notice “The map background is temporarily unavailable. Reload the page later or contact support if the problem continues.” instead of falling back to a differently labelled map.
- The development entrypoint copies `.env.docker` to `.env` when `.env` does not already exist.
- The npm Docker wrappers always pass `--env-file .env.docker` so Compose and Laravel use the same source of truth.
- Docker-managed `node_modules` live in the named Docker volume, not in your host checkout.
- Complete Pest runs copy the current checkout into the Docker-managed
  `ernie-pest-workspace` volume before execution. This avoids repeated
  Windows/macOS bind-mount reads while leaving the development source mount and
  focused test workflow unchanged. Read-only `--list-tests` discovery uses a
  freshly synchronized native workspace as well.

### MySQL version and existing local data

The local database service is pinned to MySQL 9.7, and its healthcheck verifies
the `9.7.x` server series. MySQL-sensitive tests use the same pinned image in
the disposable `db-test` service. Do not
downgrade the service to MySQL 8 when an existing data directory fails to start.

The Compose volume is named `ernie-db-data-mysql-9-7` so an older
`ernie-db-data` volume cannot accidentally be mounted into MySQL 9.7. This is
necessary because MySQL 9.7 rejects a direct in-place upgrade from a data
directory created by a non-LTS MySQL 8.0 release. Compose creates and seeds the
new 9.7 volume on first startup; the old volume is retained and can be migrated
separately with a logical dump and restore if its local data is still needed.

`Dockerfile` and `Dockerfile.dev` also contain a MySQL 8.4 build stage. That
stage contributes only the separately named `mysql-legacy-mysqldump` client
needed to export the external MySQL 5.6 IGSN database. It never runs as ERNIE's
database server.

### Log retention and downloads

Docker entrypoints run their Artisan commands, queue workers and scheduler as
`www-data`. Root is retained only for container setup and the PHP-FPM master;
FPM pool workers also use `www-data`. The shared runtime helper uses Debian's
`setpriv`, whose availability is checked when both PHP images are built.

When dependencies are missing, Composer also runs as `www-data`, including its
plugins and the `post-autoload-dump` package discovery. Setup makes only the
`vendor` dependency directory writable for installation; Composer keeps its
configuration and cache under `storage/framework/.config/composer`.

All development PHP services read their application key from the shared `.env`.
The entrypoint unsets an exported empty `APP_KEY` before Laravel boots, so it
cannot hide the file's value. First-time environment copying and key generation
run under a file lock; simultaneous app/worker starts generate one key, and
restarts reuse it. Artisan generates the key with `--show`, and root stores it
without changing existing ownership or permissions. The web user receives no
additional write access, and the key is not printed in container logs.
Initialization and key generation reject a symbolic link at `.env`. Writes use
no-follow file opens, including initial creation and template copying, so a
link inserted after validation cannot redirect the write to another file.
Git and Docker exclude `.env-key.*` temporary files, including any leftovers
after an abrupt container stop, so they cannot enter normal commits or builds.
Stage and Production instead require a shared key supplied by the deployment.

The entrypoints recursively repair only `storage/framework`, `storage/logs`
and `bootstrap/cache`. They initialize `storage`, `storage/app` and the private
and public disk roots without traversing uploaded files. Existing upload
ownership and permissions are preserved across restarts; repairing imported
data is a separate, one-time administrative task.
Startup rejects symbolic links at the managed runtime directories and their
parents before changing files. Recursive permission repair does not follow
links nested inside the runtime directories.
Storage-link setup also rejects a symbolic link at `public/` before creating or
replacing anything in that directory.

The setgid bit on `storage/logs` makes new logs inherit group `www-data`, and the
`single` and `daily` channels create files with mode `0664`. This also lets web
workers append to a daily file first created by an accidental root CLI invocation.
It does not replace running administrative Artisan commands as the application user.
`npm run artisan -- <command>` selects `www-data`, sets a writable PsySH
configuration path under `storage/framework/.config`, and uses a 2 GB PHP
memory limit. The Docker command for the same behavior is:

```bash
docker compose --env-file .env.docker -f docker-compose.dev.yml exec -T --user www-data -e XDG_CONFIG_HOME=/var/www/html/storage/framework/.config app php -d memory_limit=2G artisan list
```

In a Portainer app-container console, select user `www-data`, or switch from a
root console with `su -s /bin/sh www-data` before running Artisan. For Tinker,
set `export XDG_CONFIG_HOME=/var/www/html/storage/framework/.config` in that shell.
Rebuild and
recreate the PHP containers to apply entrypoint changes. Restarting an old image
does not install this fix. The PHP images still start as root for setup; do not
override the whole service with `user: www-data`.

The **Application Logs** card at `/logs` also records successful user actions on
resources, IGSNs and their landing pages at `INFO` level. English entries identify
the user and dataset, name changed fields, and offer dataset and DOI links when
available. This includes metadata and landing-page edits, tombstones, imports,
registrations, metadata exports and review-link emails queued for delivery.
Batch actions have individual successful-record entries and a correlated summary.
Unchanged saves and failed actions do not create success entries; existing
technical warnings and errors remain available.

The viewer reads both single and daily application logs, newest first. It uses
one shared 50 MB window across log files and shows a notice when older history
falls outside that window. Downloads can include retained history outside it.
Activities follow the same administrator access, deletion and rotation rules as
other application logs; there is no separate permanent audit database and no
retroactive reconstruction of earlier activity. Queue processing retains the
initiating user's identity; file logging remains best effort after database commit.
Run migrations before deploying the activity writer; background runs store a
nullable actor snapshot. Resource links open the editor; IGSN links search for
the recorded identifier. Deleted datasets retain their recorded identity and DOI.

Stage/Production images now default to `LOG_LEVEL=info`; development keeps
`debug`. Existing Portainer installations with an explicit `LOG_LEVEL=error`
override must set it to `info` to record activities, recreate the affected app and
worker services, and refresh Laravel's configuration cache using their normal
deployment procedure. A threshold above `info` suppresses activity entries.

The administration log viewer can download the application log for the last
24 hours, 7 days, or 30 days. It reads both `storage/logs/laravel.log` and
Laravel's daily files named `laravel-YYYY-MM-DD.log`. A download can only
contain history that is still present on disk. Deployments using the `daily`
log channel must therefore keep `LOG_DAILY_DAYS` at 31 or more for complete
30-day downloads. The production template selects that channel with
`LOG_STACK=daily`; the development templates continue to use `single`. The
repository environment templates and Laravel's default use 31 days, while an
environment-specific override takes precedence.

### Host VM resource monitoring

The administrator-only `/logs` page includes CPU and memory history for the
entire host VM. Production and Stage enable the collector in their Compose
files. Only the `scheduler` container receives read-only bind mounts for the
host's `/proc/stat` and `/proc/meminfo`; the web application reads normalized
samples from MySQL and has no host filesystem access.

The scheduler records one sample per minute. The page displays five-minute
averages for the last 24 hours or 30-minute averages for the last 7 days. Raw
samples are retained for 30 days by default and pruned daily. The first sample
after enabling monitoring or rebooting the VM establishes a CPU baseline, so a
CPU percentage appears after the next consecutive sample. Missing intervals
remain visible as gaps instead of being filled with zeroes.

Local collection defaults to `SYSTEM_METRICS_ENABLED=false`. Docker Desktop
would report the resource usage of its internal Linux VM rather than the
physical Windows or macOS computer, so `/logs` shows an intentional disabled
state locally. Parser and collector tests use deterministic fixtures and never
depend on the development host's `/proc`.

Useful Stage or Production checks are:

```bash
docker compose -f docker-compose.stage.yml exec scheduler test -r /host/proc/stat
docker compose -f docker-compose.stage.yml exec scheduler test -r /host/proc/meminfo
docker compose -f docker-compose.stage.yml exec --user www-data scheduler php artisan system-metrics:collect
```

No Docker socket, privileged container, host PID namespace, or writable host
mount is required. Override `SYSTEM_METRICS_RETENTION_DAYS` only with a positive
number. Disabling collection preserves existing history until normal retention
removes it.

### Anonymous public traffic history

The administrator-only `/logs` page also shows the typical number of unique
signed-out visitors by weekday and hour for published landing pages and the DOI
and IGSN portals. The application deduplicates visitors for the current UTC
hour in the shared cache using a bucket-specific HMAC of IP address and user
agent. This HMAC identifier exists only in short-lived cache key names and
expires shortly after its UTC hour. Only hourly counters are persisted; no IP
address, user agent, cookie, session identifier, or visitor hash is stored in
MySQL or application logs. Authenticated users, empty user agents, and
recognizable crawlers are excluded. Extend the built-in
heuristic list with a comma-separated
`BOT_PROTECTION_ADDITIONAL_CRAWLER_USER_AGENTS` value when required.

`PUBLIC_TRAFFIC_ENABLED` defaults to `false` locally. Stage and Production
enable it in Compose. Their scheduler runs
`public-traffic:observe-availability` every minute against the configured
public `/health` URL and verifies the shared cache before marking the minute as
observed. Only completed hours with all 60 minutes observed contribute to the
heatmap. This keeps a healthy zero-visitor hour distinct from an application or
analytics outage. Aggregates are retained for 400 days by default and pruned
daily.

For a controlled local check, enable the feature and point the probe at a
trusted reachable health URL before running:

```bash
npm run artisan -- public-traffic:observe-availability
npm run artisan -- public-traffic:prune
```

The UI groups UTC buckets in `Europe/Berlin`, including daylight-saving time,
and offers rolling 4-, 12-, and 52-week views. Recommendations remain in the
collecting state until every weekday/hour cell has at least one complete
observation.

### DataCite mode and repository credentials

Keep `DATACITE_TEST_MODE=true` for local development and Stage. Use only the configured test repository for registration and metadata-update checks.

Production uses separate Repository accounts for ordinary GFZ DOIs and IGSNs. Configure `DATACITE_USERNAME`, `DATACITE_PASSWORD`, and `DATACITE_CLIENT_ID` for the ordinary repository, and `DATACITE_IGSN_USERNAME`, `DATACITE_IGSN_PASSWORD`, and `DATACITE_IGSN_CLIENT_ID` for the IGSN repository. ERNIE selects the IGSN credentials for the configured IGSN prefix. Test API audits additionally require `DATACITE_TEST_CLIENT_ID`.

### GEOFON seismic-event landing-page repair

Use the authenticated, dry-run-first repair command for existing `GEOFON Seismic Events` Resources whose external landing page still uses the retired `db/eqpage.php` route. It checks the local and DataCite URLs independently, requires the event ID in both URLs to match the DOI suffix, and accepts only the known GEOFON event DOI namespaces and hosts. It also reports a matching retired local URL assigned to any other datacenter as manual review and never updates that Resource.

Start with a complete audit:

```bash
npm run artisan -- resources:repair-geofon-event-landing-page-urls \
    --report=storage/app/geofon-event-url-audit.csv
```

The dry run performs authenticated DataCite GETs and bounded reachability checks against the canonical `https://geofon.gfz.de/eqinfo/event.php?id=...` target, but it sends no PUT and changes no local row. Review every wrong-datacenter, unknown URL, event-ID mismatch, unreachable target, and API error before applying anything.

Apply and verify one representative DOI first:

```bash
npm run artisan -- resources:repair-geofon-event-landing-page-urls \
    --apply --force-production \
    --doi=10.1594/gfz.geofon.gfz2011axdw \
    --report=storage/app/geofon-event-url-pilot.csv
```

For each required DataCite update, ERNIE saves the complete source record below `storage/app/private/geofon-event-url-updates/`, sends only the new `url`, and confirms it with another authenticated GET. The local domain and path are updated transactionally only after DataCite is confirmed; an independently stale local URL is still repairable when DataCite is already current. Authentication failures stop subsequent records, while per-record validation, reachability, verification, and concurrency failures remain visible in the CSV without overwriting local data.

After the pilot resolves directly to the canonical GEOFON page, run the complete apply and then repeat the dry run:

```bash
npm run artisan -- resources:repair-geofon-event-landing-page-urls \
    --apply --force-production \
    --report=storage/app/geofon-event-url-applied.csv

npm run artisan -- resources:repair-geofon-event-landing-page-urls \
    --report=storage/app/geofon-event-url-verification.csv
```

The final dry run must report every eligible Resource as `already_current`. Use repeatable `--doi` filters for targeted retries, `--after-id=<resource-id>` to resume after the last processed Resource, and `--limit=<count>` for bounded batches. Production writes always require both `--apply` and `--force-production`; test mode never reaches the production API.

### DataCite Kernel 4 upgrade for imported resources

Every metadata update for an existing DOI now asks DataCite to store the record with Kernel 4. In the Data Editor, any non-empty DOI therefore produces the **Update Metadata** action regardless of the local draft, curation, review, or published status. This is an update of the existing DOI, not another registration.

Use the authenticated, dry-run-first upgrade command to normalize already imported repository records. It lists every state visible to the configured Repository account, intersects the result with local ERNIE resources by normalized DOI, and excludes IGSNs and unconfigured prefixes. No database migration is involved.

Start with an audit and inspect all `manual_review`, `not_imported`, and `error` rows:

```bash
npm run artisan -- resources:upgrade-datacite-schema \
    --report=storage/app/datacite-schema-upgrade-dry-run.csv
```

The dry run never sends a PUT. Kernel 3, Kernel 2.x, and records without a schema version are automatically eligible only when `types.resourceTypeGeneral` is valid for Kernel 4 and no legacy `contributorType: Funder` remains. Unknown schema versions and incompatible metadata stay unchanged for manual review.

Apply one representative DOI from each legacy group first:

```bash
npm run artisan -- resources:upgrade-datacite-schema \
    --apply --force-production \
    --doi=10.5880/example \
    --report=storage/app/datacite-schema-upgrade-pilot.csv
```

`--force-production` is mandatory only when `--apply` targets the production API. It is an explicit safety acknowledgement; the command remains non-interactive and still performs every per-record preflight. Confirm that the pilot retained its DOI state, landing-page URL, and resource type before running the complete upgrade:

```bash
npm run artisan -- resources:upgrade-datacite-schema \
    --apply --force-production \
    --report=storage/app/datacite-schema-upgrade-applied.csv
```

Apply runs send only `doi`, `schemaVersion`, and the existing `types` object. Before each PUT, the complete DataCite source record is saved below `storage/app/private/datacite-schema-upgrades/`; this location is not publicly served. API or verification failures are isolated in the CSV, except that HTTP 401 or 403 stops subsequent writes. Resume a bounded run with `--after-id=<resource-id>` and `--limit=<count>`, or retry individual records with repeatable `--doi` options.

After applying, repeat the dry run. Successfully upgraded records appear as `already_current` and do not cause another write, making the process idempotent. Archive the apply report and private snapshots according to the operating retention policy.

### Completed legacy migration

The Resources and IGSNs legacy import actions, background jobs, and legacy metadata backfill commands have been retired. Editing, file uploads, citation-label hydration, registration, and public search use ERNIE and the current external metadata providers without accessing the old SUMARIOPMD, MetaWorks, Solr, or IGSN DIF sources. Existing migrated metadata and provenance remain stored.

Citation-label hydration preserves supplied labels and resolves missing DOI and DOI-URL citations through the current metadata providers. Arbitrary URLs without a supplied citation remain without an automatic label. The old MetaWorks citation cache is no longer queried.

The admin-only `/database` exports still support SUMARIOPMD, MetaWorks and IGSN for final backups. Keep their `DB_SUMARIOPMD_*`, `DB_METAWORKS_*`, `DB_IGSN_*`, TLS configuration and `DATABASE_DUMP_IGSN_BINARY` until these exports are retired. The separate MySQL 8.4 dump-client build stage remains necessary for the legacy IGSN export; the development and test server remains MySQL 9.7.

Before deploying this removal, prevent new imports and drain or explicitly resolve existing import and import-sync jobs using the old application version. Include delayed and failed jobs, and inspect their actual queues before changing workers. Do not clear unrelated queues. Deploy the application and worker configuration together, rebuild route/configuration caches and Wayfinder assets, and verify the remaining DataCite and database-dump workers. No legacy import jobs may be retried against the new code.

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

### Migrated IGSN metadata and images

Existing IGSN classifications, descriptions, geological and drilling metadata, sample families, and repository contacts remain available in local records and landing pages. Legacy-source enrichment and repair commands have been removed after the completed migration.

Already stored sample images continue to use `IGSN_IMAGE_DISK`. Keep that disk persistent, backed up and publicly linked. Existing external image and download URLs remain stored; removal of the metadata import does not migrate external files or change their hosting.

IGSN landing-page templates partition every module exactly once across a left column, a right column, and `Hidden cards`. Modules can be reordered or moved among all three zones; visible cards also provide a Hide action, and hidden cards can be restored directly to either column. `version_notice` may move between the visible columns but can never be hidden. The built-in `Templates IGSN` copy template uses General, Sample Family, Repositories, Map, Related Work, Download Metadata, Dates, and Citation on the left; Version Notice, Contributors, Authors, Location, Acquisition, and Funding References on the right; and hides the remaining optional modules. IGSN Location details and the Leaflet Map are independent cards, while Resource templates retain their combined Location / Map card.

The migration `2026_09_14_000001_restructure_igsn_landing_page_templates.php` preserves the visible positions of existing custom IGSN cards, inserts Map directly after their former combined Location card, converts a disabled legacy Drilling setting into a hidden module, and removes the superseded `show_igsn_drilling` column. The `landing_pages:render_data:v9` cache namespace prevents pre-migration render payloads and citations with legacy DOI notation from bypassing the current three-zone layout and complete DOI resolver URLs.

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

### GEMET update checks time out

The GEMET check in **Editor Settings / Thesauri** uses at most four concurrent
group requests and retries temporary connection or HTTP failures once. Provider
`Retry-After` delays and all requests share a 90-second budget. Each attempt has
a maximum 10-second connection timeout and 30-second total timeout, reduced to
the remaining budget. A complete count is cached for one hour; failed or partial
checks are not cached and can be retried without clearing the application cache.

Persistent failures return HTTP 503 with an understandable message. Look for
`GEMET update check failed.` in the Laravel logs for the phase, group URI,
attempt count, elapsed time and original exception. If a single GEMET request
also fails from the affected app container, inspect DNS, outbound connectivity
and TLS negotiation in that environment. A successful check from the host alone
does not establish container connectivity. The actual vocabulary download and
update jobs retain their existing request policy.

The following opt-in probes run through the app container with the real cURL
transport and production clock. They are outside the default PHPUnit suites:

```bash
npm run test:php -- tests/pest/Runtime/GemetUpdateCheckRuntimeTest.php
npm run test:php -- tests/pest/Runtime/GemetLiveCheckTest.php
```

The controlled probe starts a temporary Node HTTP server on loopback and changes
only the request destination. It measures concurrent connections, out-of-order
responses, a real 30-second cURL timeout, `Retry-After`, and the shared 90-second
deadline across successive pools. Allow about two minutes for the real waits.
It also checks failure without a cache entry, recovery, and a warm cache hit.
The server is stopped after each scenario.

The live probe makes read-only requests to the public EEA service. It compares
against an isolated copy of the local vocabulary and uses the test database and
cache; it does not update vocabulary files or start update jobs. It writes status,
counts, cold/warm durations, and per-request cURL timings to
`storage/logs/issue-1476-gemet-live.json`. On failure, inspect the probe output
and the container's connectivity. These probes are
deliberately opt-in because they have real delays and the live result depends on
the provider and the network of the app container where it runs.

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
- calculate the initial license usage ranking

Subsequent startups are usually much faster because Docker volumes keep `vendor`, `node_modules`, and the MySQL data directory.

### The License dropdown is entirely alphabetical

The Data Editor orders active licenses by the persisted `rights.usage_count` value and uses the license name as the tie-breaker. If every counter is `0`, the whole list therefore appears alphabetical.

Check that the `scheduler` service is running and inspect its startup output. For an immediate diagnostic refresh, run:

```bash
npm run artisan -- rights:update-usage-count
```

Restarting the scheduler also performs this refresh before `schedule:work` starts. A failed startup refresh leaves the previous complete counter snapshot in place and causes the scheduler container to restart instead of silently serving a partially updated ranking.

## Public Page Feature Switches

Each of the four new public pages can be enabled independently in `.env.docker`:

| Environment variable | Route |
| --- | --- |
| `PUBLIC_HOME_ENABLED` | `/` |
| `PUBLIC_FIND_ENABLED` | `/find` |
| `PUBLIC_DATA_CENTRES_ENABLED` | `/data-centres` |
| `PUBLIC_DATA_CENTRE_DESCRIPTION_ENABLED` | `/data-centres/description` |

All environments default to `true`. Use the literal `true` to serve the ERNIE
page or `false` to return a local HTTP 404. Disabled pages no longer redirect
to the old portal. Run `npm run docker:dev:backend:d` after
changing `.env.docker` to recreate the app container with the new values. The
switches apply equally to direct visits and Inertia navigation, including
signed-in users. They do not disable the search portals or other public pages.

For route configuration and Portainer instructions, see [public page routing
and feature switches](production-container-deployment.md#public-page-routing-and-feature-switches).

## Maintaining the Public Data Centres Catalogue

The public `/data-centres` and `/data-centres/description` pages use
`DataCentreCatalogService` to join the local DOI portal's published datacenter
facets with `resources/data/data-centres.json`. Only centres with published DOI
resources appear; IGSN-only, unpublished, or empty centres are omitted. New names
automatically receive a neutral text tile and description. There are no runtime
requests to the production portal or TYPO3.

To update the editorial content:

1. Compare the Datacenters facet at <https://dataservices.gfz.de/doi-search> with
   `tests/fixtures/data-centres-production.json`. Update the dated snapshot when
   the production selection changes; do not copy resource counts.
2. Keep `datacenterName` as the original portal name used to attach reviewed
   editorial content. The catalogue resolves that historical name to the current
   Datacenter ID, and links use the current name as their DOI filter value. Use
   `displayName` and `shortName` for curated public labels. Preserve existing
   `slug` values so shared description links continue to work.
   The `datacenter-` slug prefix is reserved for automatic fallbacks.
3. Write English plain-text description paragraphs and HTTPS links using official
   project or institution sources. Record `sources`, `reviewedAt`, and `logoSource`
   for review. These provenance fields are not sent to the browser. Use a null
   logo and a `logoNote` if no suitable official asset is available.
4. Store original logo assets in `public/images/data-centres/`, preserving their
   proportions, and record their real pixel dimensions. Refresh the Find preview
   at `public/images/find/data-centres.png` when the page appearance changes.
5. Run the focused checks below, then the normal frontend/backend validation.

```bash
npm run test:php -- tests/pest/Feature/DataCentresTest.php
npm run test:run -- tests/vitest/pages/__tests__/data-centres.test.tsx
npm run test:e2e:devstack -- critical/data-centres.spec.ts accessibility/data-centres-a11y.spec.ts
```

Browser layout and accessibility tests inject the repository catalogue into the
real page response, so all reviewed entries are exercised even on a small local
database. Separate browser tests use the live local catalogue and DOI portal;
backend integration tests cover publication visibility, exact filters, and facet
cache invalidation.

A later administration interface can replace the repository content source
behind the service. Keep its public `DataCentre` contract, stable name resolution,
stable slugs, and publication-based selection unchanged.

## MySQL-Sensitive Pest Slice

The default local Pest loop remains SQLite-backed.

Use the dedicated MySQL-backed slice only when driver-sensitive verification is required:

```bash
npm run test:php:mysql-sensitive
```

This command:

- starts the backend and optional `test` profile once
- verifies MySQL 9.7 before resetting a schema; `db-test` uses the exact image pinned for `db`
- uses `ernie_test` on the disposable `db-test` server, backed by a bounded 2 GiB tmpfs without a host port
- runs the manifest in `tests/mysql-sensitive-slices.json` with 28 serial reset boundaries, including COUNTER aggregation/activation concurrency, editor/background concurrency, shared-Redis DOI retry checks and identifier-pattern collation conflicts
- copies the checkout into the Linux-native Pest workspace for the complete run; focused aliases keep using the checkout

The development server and its versioned data volume are preserved. Focused
aliases, `db:dev:test:prepare`, `db:dev:test:reset`, and the raw MySQL test wrapper
all address the disposable test server. `--slice <name>` selects a manifest block;
`--filter` and other Pest arguments can follow it. MySQL slices remain serial
because they share the isolated test schema. To stop the optional server and
release its tmpfs:

```bash
docker compose --env-file .env.docker -f docker-compose.dev.yml --profile test stop db-test
```

Stopping it discards test data; the next wrapper call starts and initializes it
again. For broader testing and optional timing reports, see [testing.md](testing.md).

### Digital resource access metadata

Resource landing pages and the DataCite JSON/XML exports resolve access from the curated `access_level` first. When it is empty, an internal digital-resource landing page with an active usable primary Download URL or imported file URL implies Open access. An internal page without automatic downloads exposes Metadata only access, including pages whose saved download links are still hidden. External landing pages and resources without a landing page remain unresolved unless a curator provides a reliable access level. This inference does not apply to Physical Objects or resources with IGSN metadata.

The effective level appears in Schema.org `conditionsOfAccess`, Dublin Core `DC.accessRights`, and DataCite `rightsList` with the COAR Access Rights URI. Digital Metadata only access omits `isAccessibleForFree`: emitting `false` would let F-UJI infer Restricted access even though the absence of a download does not establish that restriction. Download availability and the resource license remain separate metadata. The inference is computed from current configuration without persisting it during public requests, so adding or removing downloads updates the output immediately.

`npm run artisan -- metadata:backfill-access-content` audits missing stored access levels without writes; `--apply` persists unambiguous candidates and reports unresolved resources for review. Existing curated levels are preserved. Earlier versions initialized digital resources to Open even without internal download evidence; those stored values cannot be distinguished from curated Open values and need review when the actual access is unknown.

### Embargo workflow (issue #1350)

ERNIE uses `Embargo` only as an internal workflow status. DataCite 4.7 metadata keeps `dateType=Available` and the COAR `Embargoed access` right. A valid embargo needs exactly one day-precision `Available` date (`YYYY-MM-DD`). The configured `APP_TIMEZONE` determines when that date becomes due at 00:00. The status remains Embargo until a curator manually registers the DOI or IGSN; successful registration changes the access right to Open and publishes the internal landing page. The dashboard lists due resources for every curator. Unpublished preview URLs require their token and omit stored download destinations. The XML schema snapshot under `resources/data/scheme/datacite-4.7/` comes from the official DataCite 4.7 schema and is used by the schema regression test.

An uncertain DataCite create response leaves a durable registration attempt on the resource. A retry first reads DataCite by the stable `/datasets/{id}` target (or by the known IGSN) and completes the local release only when the remote record is Findable and has the expected Open access right. It never sends a second POST while the attempt is pending; editor saves are blocked until reconciliation. If the retry reports that no matching record exists, an operator must inspect the correct DataCite environment and prefix before clearing `embargo_registration_started_at` and `embargo_registration_prefix` for that resource in MySQL. Keep the marker when the remote outcome is uncertain or when multiple records match.

### Subject hierarchy correction (issue #1050)

The Subject Hierarchy Correction assistant reads version 1 caches under `storage/app/private/subject-hierarchies/`. Each cache preserves all source parent relations and is bound by SHA-256 to the corresponding editor vocabulary JSON. Existing editor caches alone are insufficient. Update the enabled vocabularies through Editor Settings or their existing artisan commands (`get-gcmd-science-keywords`, `get-gcmd-platforms`, `get-gcmd-instruments`, `get-msl-keywords`, `get-gemet-thesaurus`, `get-chronostrat-timescale`, `get-analytical-methods`, `get-euroscivoc`, `get-cgi-simple-lithology`). For Docker development use `npm run artisan -- <command>`.

GEMET updates additionally download the official EEA SKOS concept relation export. Navigation groups are recorded as non-indexable hints and do not supply concept hierarchy edges. Invalid graphs, missing parents and incomplete exports fail the update before publication. Discovery and acceptance never fetch vocabulary data remotely; unavailable or mismatched caches are reported in the check result and cannot be used for corrections.

Rebuild the assistant manifest and route caches using the deployment's normal cache procedure after adding the module. Vocabulary updates do not change resource subjects. Admins and Group Leaders must confirm the terms that describe each resource. Retaining the broader concept when every terminal descendant is chosen is an ERNIE curation rule; it is not a general thesaurus requirement. A reasoned decline supports broader scopes that should remain unchanged. Registered resources use the existing DataCite synchronization and retry flow after a confirmed correction.

Publishers and hierarchy readers share a persistent per-vocabulary filesystem lock, so concurrent updates cannot interleave the two cache files and discovery or acceptance cannot read a partially replaced pair. Both existing files are backed up before either replacement. A failed write or rename restores the previous files under the same lock; files that did not previously exist are removed. Backups are restored by rename without rewriting their contents. If filesystem errors also prevent rollback, the failure reports this and retains the `.bak` files beside the original caches for recovery.

### Tombstone landing pages

Curators, Group Leaders and Admins can activate, edit and restore resource tombstones in **Setup Landing Page**. Activation verifies a registered or findable DOI through the authenticated DataCite Member API and requires a reason, a public explanation and confirmation. The default resource template retains bibliographic metadata and contact information while disabling data downloads and requests. Internal listings show **Dead**; public portal discovery excludes these resources. The workflow follows [DataCite's tombstone guidance](https://support.datacite.org/docs/tombstone-pages).

Run migrations before using this feature. Keep the `queue` worker (including the configured DataCite queue) and `scheduler` services running: each lifecycle change stores a durable `resource_tombstone_transitions` record before queue dispatch. The scheduler recovers due work every minute, including jobs interrupted for more than six minutes. Transient failures retry up to five times with increasing delays; the setup modal shows progress and permits an explicit retry after failure. A failed DataCite write leaves the local tombstone publicly accessible. Only the desired DOI URL and the Registered/Findable transition are sent by these jobs; they use the DataCite environment recorded at activation.

Restoration requires confirmation and restores the saved landing page configuration and previous DOI state/URL. If no landing page existed, choose explicitly whether to publish the restored default page. After synchronization completes, normal metadata updates can intentionally publish a restored DOI again. Revisions prevent stale modal saves and queued changes from replacing newer lifecycle decisions. Audit records retain the actor, public explanation and configuration snapshot. Metadata corrections remain possible while a tombstone is active. Retrying synchronization preserves unsaved reason and explanation drafts. Jobs are unique per transition through the database cache; recovery releases abandoned worker locks and failed queue pushes release their owned locks.

Seed `PlaywrightTestSeeder` before the browser slice, as in CI. It automatically calls `TombstonePlaywrightSeeder`; the tombstone fixture represents a completed synchronization and sends no DataCite requests.

Focused regression checks:

```bash
npm run test:php -- tests/pest/Feature/LandingPages/ResourceTombstoneTest.php tests/pest/Feature/Database/ResourceTombstoneSchemaTest.php
npm run test:php:mysql-sensitive:tombstones
npm run test:run -- tests/vitest/components/landing-pages/modals/__tests__/TombstoneLandingPageControls.test.tsx tests/vitest/pages/LandingPages/__tests__/default_gfz.test.tsx
npm run test:e2e:devstack -- critical/tombstone-pages.spec.ts --project=chromium
```

### Editor date type usage ordering

The Data Editor's **Dates** dropdown orders ERNIE-enabled date types by their repository-wide usage, descending, with alphabetical ties. Each saved entry in `dates` counts once, including multiple entries of the same type on one resource and date ranges. All resource types, datacenters, imports and publication states contribute except `PhysicalObject`, identified by the stable `physical-object` slug. Resources without an assigned type also contribute.

Only the usage counts are cached for seven days under `editor:date_type_usage:v1`. The next request after expiry refreshes the counts; changes to enabled types and display names take effect on the next editor load. The Dates section continues to exclude system-managed dates and Coverage. New date rows select the first editable option in this order, while saved or imported date selections are preserved.

Redis resource-cache invalidation does not clear this usage snapshot. To refresh it manually before expiry, use the existing vocabulary cache reset, which also clears the other vocabulary caches:

```bash
npm run artisan -- cache:clear-app vocabularies
```

The complete `cache:clear-app all` reset also clears the snapshot. Cache stores without tags use the existing fallback; their global resource-cache flushes can clear the snapshot earlier than seven days.


### Automatic ELMO cache refresh

When explicitly enabled, saving Editor Settings queues a refresh for each affected
external editor whose upstream implementation is ready. Changes
to ELMO and ELMO-MSL activations and license exclusions remain independent.
Shared names, contributor categories and identifier patterns refresh the editors
whose API data changes. Unchanged values, request ordering, timestamps and
ERNIE-only settings do not trigger a refresh. Successful thesaurus/PID downloads
and genuine thesaurus version changes also request a refresh for active editors.

Local development and Stage both target:

- ELMO: `https://env.rz-vm182.gfz.de/elmo`
- ELMO-MSL: `https://env.rz-vm182.gfz.de/elmo-msl`

Set `ELMO_API_KEY` and `ELMO_MSL_API_KEY` in `.env.docker` to the corresponding
Stage ELMO admin keys. These authenticate outgoing `POST /api/v2/admin/cache/refresh`
requests through `X-API-KEY`; `ERNIE_API_KEY` authenticates ELMO requests to ERNIE.
`ELMO_BASE_URL` and `ELMO_MSL_BASE_URL` override the deployment's default URLs.
The `/elmo` or `/elmo-msl` path must be retained in the base URL.

Automatic refresh is **disabled by default** in configuration, environment examples
and every Compose stack. After deploying and validating the matching regular ELMO
admin endpoint, set `ELMO_CACHE_REFRESH_ENABLED=true` explicitly. Provisioning keys
alone does not enable refreshes.

ELMO-MSL additionally requires `ELMO_MSL_CACHE_REFRESH_READY=true`, default `false`.
Set it only after the MSL instance refreshes from the correct ERNIE ELMO-MSL source
routes. The reviewed ELMO PR still hard-codes regular ELMO routes, so leave this
flag disabled for that version. Readiness is checked after commit and again before
HTTP execution, including for jobs queued before a rollout or flag change. MSL
settings remain saved while its refresh is gated.

Start or recreate the workers after configuring the environment:

```bash
docker compose --env-file .env.docker -f docker-compose.dev.yml up -d queue elmo-cache-refresh
```

The web application must receive the same configuration; recreate `app` if its
environment changed. Incomplete target configuration is logged and skips only that
target. `ELMO_CACHE_REFRESH_ENABLED=false` disables automatic dispatch and skips
already queued jobs. Automated tests force this switch off and enable simulated
integration explicitly in their own tests.

`elmo-cache-refresh` uses the persistent `elmo` database queue connection and the
`elmo-cache-refresh` queue. Each job calls one target and makes at most three
attempts, with 30/120-second retry delays. Network failures, HTTP 408/429 and 5xx
responses are retried; valid `Retry-After` values on HTTP 429 are capped at 300
seconds. Other HTTP errors and invalid success responses fail immediately.
Success requires all 16 vocabulary result keys from the reviewed ELMO contract,
with strictly `true` values: resourceTypes, titleTypes, descriptionTypes, languages,
contributorPersonRoles, contributorInstitutionRoles, relationTypes, identifierTypes,
pid4instInstruments, mslLabs, gcmdScienceKeywords, gcmdPlatforms, gcmdInstruments,
chronostratTimescale, gemet and thesauriAvailability. A partial all-true map is a
contract error. Additional successful result keys are accepted; review this list
when the upstream contract changes.
Redirects are rejected and TLS verification remains enabled.

Default limits are 10 seconds to connect, 310 seconds for the HTTP request,
330 seconds for the job and 390 seconds for the queue reservation. Configure them
with `ELMO_CACHE_REFRESH_CONNECT_TIMEOUT`, `ELMO_CACHE_REFRESH_TIMEOUT`,
`ELMO_CACHE_REFRESH_JOB_TIMEOUT` and `ELMO_CACHE_REFRESH_RETRY_AFTER`. Keep
`connect_timeout <= HTTP timeout < job timeout < retry_after`; invalid settings
skip dispatch. Increase the worker's 360-second container stop grace period if
increasing its job timeout. The general worker does not consume this queue.

Saved settings and completed local data updates remain successful when ELMO or
queue insertion fails. Logs identify the editor, trigger and correlation ID.
Failed jobs can be inspected and retried with the existing Artisan wrapper:

```bash
npm run artisan -- queue:failed
npm run artisan -- queue:retry <failed-job-uuid>
```

If insertion into the queue itself failed, no failed job exists. After resolving
the queue error, invoke the authenticated ELMO admin refresh manually. The
post-commit dispatch does not provide an outbox guarantee across process crashes.

A refresh triggered locally makes Stage ELMO read from its configured ERNIE
source; it does not upload local database contents. Thesaurus version changes
remove the old local file, so a subsequent successful vocabulary download is
needed to make the new data available and requests another refresh.

The matching ELMO endpoint is introduced by
[ELMO PR #1270](https://github.com/McNamara84/elmo/pull/1270). Its reviewed version
still needs complete cache coverage, the correct MSL source routes and handling
for empty/disabled vocabularies. Check the actual ELMO UI and vocabulary data as
well as `GET /api/v2/admin/cache/status` after deployment; a successful request
alone does not establish support for every ERNIE setting.

Focused regression checks:

```bash
npm run test:php -- tests/pest/Feature/ElmoCacheRefresh --parallel
npm run test:php -- tests/pest/Unit/Deployment/ElmoCacheRefreshDeploymentTest.php
npm run test:php:mysql-sensitive:editor-settings
npm run check:backend
```

## Curation tracking

Until all curation tables and projection columns exist, curation overview, export,
panel, history and write requests return HTTP 503. Dashboard and user-list counts
remain zero. Review invitations and review-link migration emails continue through
the configured default mail queue, without curation receipts or operation-ID
deduplication. Schema readiness is rechecked on subsequent requests, so normal
curation tracking resumes once the migration is complete.

After deploying the additive curation migration, finish or pause running imports
before initializing existing unpublished resources:

```bash
docker compose --env-file .env.docker -f docker-compose.dev.yml exec app php artisan curation:initialize
docker compose --env-file .env.docker -f docker-compose.dev.yml exec app php artisan curation:initialize --apply
```

The first command is a dry run. Repeated runs preserve existing curation work.
Published resources, IGSNs and tombstones are excluded; eligible older drafts
start unassigned. Published records require the editor's explicit **Start curation**
action. Newly saved drafts and finished unpublished imports start automatically.
Beginner users can read every curation section but cannot write or be newly assigned.

Review dispatch receipts are recorded only for resources whose curation has
already started. Untracked resources continue using the configured default mail
queue without receipts or operation-ID deduplication, even after the migration.
Initializing curation later does not retroactively count those earlier emails
as observed review evidence or complete the automatic review-link checklist item.

Keep the scheduler and dedicated `curation-queue` worker running. Assignment and mention
notifications use the `curation` database connection's `curation` queue so the job
and its receipt commit atomically. The scheduler retries pending notifications
every minute. Existing DOI verification uses the configured DataCite queue.
Review events mean queued, not delivered. Internal notes and locations are not
included in public metadata exports; the authenticated curation CSV is intentional.

`CURATION_WAITING_OVERDUE_DAYS` defaults to 14 (overdue on calendar day 15).
The start date is day 1: a wait started at any time on October 1 becomes overdue
at 00:00 on October 15 in the application timezone. The same boundary applies to
the panel, overview filters, CSV export and dashboard counts, including DST changes.
Deadlines become overdue the following day for unpublished records. Application
timezone boundaries apply. Completion requires publication, all applicable manual
items, no waiting and no help request. Missing historical review evidence does
not prevent completion. Embargos remain open and tombstones retain read-only history.

Focused checks use the pinned Node version and MySQL 9.7:

```bash
npm run test:php -- tests/pest/Feature/Curation
npm run test:run -- tests/vitest/components/curation-workflow
npm run test:php:mysql-sensitive -- --slice curation
npm run test:e2e:devstack -- workflows/curation-performance.spec.ts workflows/curation-tracking.spec.ts --workers=1
```

The browser performance measurement requires a single worker so concurrent test
fixtures and editor actions do not distort the one-second target. Its separate
`curation-performance.spec.ts` file uses a common 1280 x 720 viewport at pixel
ratio 1 in every browser and disables video and trace recording to avoid
measuring capture overhead; timing attachments and failure screenshots remain
available. Functional curation scenarios retain normal recording settings and
also run with the normal parallel browser configuration.
Set `ERNIE_PLAYWRIGHT_ASSETS=build` for performance acceptance with production
assets; the wrapper restores the usual Vite development setup afterwards.
On Docker Desktop, also set `ERNIE_PLAYWRIGHT_BACKEND=native` to serve the same
PHP sources from the prepared Linux workspace, as the backend test wrapper does.
This excludes Windows bind-mount file latency from the application benchmark.
The native backend requires built assets and uses separate local storage; use it
for database-backed scenarios such as curation. The default `bind` backend keeps
the development filesystem for scenarios with file fixtures. Run native browser
checks separately from PHP test/style wrappers because they share the Linux
workspace. For example:

```powershell
$env:ERNIE_PLAYWRIGHT_ASSETS = 'build'
$env:ERNIE_PLAYWRIGHT_BACKEND = 'native'
npm run test:e2e:devstack -- workflows/curation-performance.spec.ts workflows/curation-tracking.spec.ts --workers=1
Remove-Item Env:ERNIE_PLAYWRIGHT_ASSETS, Env:ERNIE_PLAYWRIGHT_BACKEND
```

Each sort path is warmed once before measuring from the actual change event
through Inertia completion and browser painting. The attached JSON records
response time, native PHP request time, navigation completion, browser frames, time until the page is
usable, and Playwright control time separately. The rendered row IDs must match
the response order. Sorting and filtering preserve the mounted overview and
expanded details without replaying the page transition.
