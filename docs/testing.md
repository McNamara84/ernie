# Local Testing

## Overview

ERNIE uses a split local validation workflow.

- PHP, Composer, Artisan, Pest, and PHPStan are container-first.
- Vitest, Oxlint, Oxfmt, TypeScript, and Playwright run from the host shell.
- Host-side frontend checks require local `node_modules` in the repository checkout.
- The default PHP path stays fast by using SQLite in memory.
- MySQL-specific verification stays targeted and explicit.

Canonical entry points:

- `npm run check:backend`
- `npm run check:frontend`
- `npm run check:parity`

Run `npm ci` after cloning and whenever `package-lock.json` changes. Use `npm install` only when intentionally adding or updating dependencies so npm can update the lockfile. The Docker entrypoints install npm packages only inside Docker-managed volumes and do not satisfy host-side frontend commands.

The Vite container also has its own dependency volume. A clean host install does
not update it. The dev-stack Playwright wrapper verifies the container's Node
version and dependencies before changing the app configuration. If it reports
invalid packages, restore that volume while Vite is stopped, then restart it:

```bash
docker compose --env-file .env.docker -f docker-compose.dev.yml stop vite
docker compose --env-file .env.docker -f docker-compose.dev.yml exec -T app npm exec --yes --package=npm@12.2.0 -- npm ci
docker compose --env-file .env.docker -f docker-compose.dev.yml start vite
```

Wait for Vite's ready message before browser tests. This preserves the database
and development volumes; the first request may compile fresh frontend modules.

The npm overrides keep indirect dependencies safe while upstream packages still
request older versions. Solid.js uses Seroval and Seroval Plugins 1.6.8 or newer
to fix [GHSA-p6vx-979v-rg4c](https://github.com/advisories/GHSA-p6vx-979v-rg4c)
and [GHSA-jp82-f5mq-hwhp](https://github.com/advisories/GHSA-jp82-f5mq-hwhp).
Swagger UI's Remarkable dependency uses Argparse 2.0.1, which removes the vulnerable
sprintf-js dependency ([GHSA-hp3w-g68c-fv3c](https://github.com/advisories/GHSA-hp3w-g68c-fv3c))
and preserves Remarkable's CLI API. Argparse 3 removes that compatibility API.
The Swagger and query devtools runtime tests verify these overrides; recheck and
remove them when the upstream dependency ranges include safe versions.

## Recommended Commands

| Check                      | Where to run it            | Command                                     | Notes                                                          |
| -------------------------- | -------------------------- | ------------------------------------------- | -------------------------------------------------------------- |
| Git pre-commit checks      | Host shell                 | `npm run precommit:check`                   | Checks staged files after `npm run hooks:install`              |
| Pest complete suite        | Host shell via npm wrapper | `npm run test:php`                          | Linux-native workspace; serial/Arch split; parallel remainder  |
| Pest TIA                   | Host shell via npm wrapper | `npm run test:php:tia`                      | Local-only affected-test loop; records a baseline on first use |
| Pest deprecation details   | Host shell via npm wrapper | `npm run test:php:deprecations`             | Use this instead of forwarding `--display-*` flags through npm |
| Pest Agent probe           | Host shell via npm wrapper | `npm run test:php:agent -- '<PHP snippet>'` | One-off verification; not a replacement for a regression test  |
| Laravel Pint               | Host shell via npm wrapper | `npm run pint:check`                        | Matches the CI PHP style check                                 |
| PHPStan                    | Host shell via npm wrapper | `npm run phpstan:check`                     | Required before finishing PHP changes                          |
| Pest type coverage         | Host shell via npm wrapper | `npm run test:php:type-coverage`            | Enforces the measured 92% minimum; expensive on a cold cache   |
| MySQL-sensitive Pest slice | Host shell via npm wrapper | `npm run test:php:mysql-sensitive`          | Uses isolated `ernie_test` schema                              |
| MySQL-sensitive OAI-PMH | Host shell via npm wrapper | `npm run test:php:mysql-sensitive:oai-pmh` | Snapshot migration, indexed page ranges, and EPOS-MSL matching/harvesting on MySQL 9.7 |
| Vitest one-shot            | Host shell                 | `npm run test:run`                          | Preferred for focused frontend validation                      |
| Vitest coverage            | Host shell                 | `npm run test:coverage`                     | Use only when coverage detail is needed                        |
| Vitest performance doctor  | Host shell                 | `npm run test:doctor`                       | Runs the suite repeatedly; use for measured tuning only        |
| Oxlint check               | Host shell                 | `npm run lint:check`                        | Non-mutating validation                                        |
| Oxlint auto-fix            | Host shell                 | `npm run lint`                              | Applies safe Oxlint fixes                                      |
| Oxfmt check                | Host shell                 | `npm run format:check`                      | Checks frontend formatting without mutations                   |
| Oxfmt write                | Host shell                 | `npm run format`                            | Formats frontend sources                                       |
| TypeScript                 | Host shell                 | `npm run types`                             | Runs app and test TS checks                                    |
| Playwright dev stack       | Host shell                 | `npm run test:e2e:devstack`                 | Requires the Docker dev stack                                  |
| Playwright stage           | Host shell                 | `npm run test:e2e:stage`                    | Use only for stage-specific bug reproduction                   |
| Backend umbrella check     | Host shell                 | `npm run check:backend`                     | Pint, Pest, and PHPStan                                        |
| Frontend umbrella check    | Host shell                 | `npm run check:frontend`                    | Oxlint plus OpenAPI lint plus TypeScript plus one-shot Vitest  |
| Parity umbrella check      | Host shell                 | `npm run check:parity`                      | Parity profile plus MySQL slice plus Playwright                |

## PHP Test Database Strategy

The default PHP suite is intentionally optimized for speed.

- `tests/pest/CreatesApplication.php` forces `APP_ENV=testing`.
- The same bootstrap defaults `DB_CONNECTION=sqlite` and `DB_DATABASE=:memory:`.
- Setting `ERNIE_TEST_DB_CONNECTION` switches the dedicated MySQL-sensitive slice to its isolated Docker test schema instead.

GitHub Actions also runs the complete MySQL-sensitive slice through
`npm run test:php:mysql-sensitive` in the `MySQL Compatibility Tests` workflow.
It builds `Dockerfile.dev` and uses the same digest-pinned MySQL 9.7 service from
`docker-compose.dev.yml` as local development. Each test slice resets only the
isolated `ernie_test` schema on the optional `db-test` service. Its disposable data directory uses tmpfs to avoid disk
flush latency during repeated schema migrations. The general Pest and Playwright
CI suites retain SQLite for fast feedback.

Whenever a test opts into MySQL, the repository wrapper starts the pinned
MySQL 9.7 service and waits for a healthcheck that also verifies the `9.7.x`
server series. The separate MySQL 8.4 `mysqldump` build stage is only a legacy
export client and is not a test database.

Use the SQLite path for the routine local loop.

Use a MySQL-backed slice only when one of the following is true:

- a migration behaves differently across drivers
- a query depends on MySQL-specific behavior
- a failing production or stage bug cannot be reproduced against SQLite

The npm wrapper runs the current explicit schema-mutating MySQL-sensitive file slice against a dedicated MySQL schema named `ernie_test`.

The wrapper recreates the schema before each group defined in
`tests/mysql-sensitive-slices.json`, preserving the existing DDL isolation
boundaries. Run only one MySQL wrapper at a time: all groups share `ernie_test`.
Parallel Pest worker options are rejected, but separate wrapper processes do
not acquire a shared lock.

## Backend Validation

Recommended commands:

```bash
npm run test:php
npm run test:php:tia
npm run test:php:deprecations -- tests/pest/Unit/Enums/UserRoleTest.php
npm run phpstan:check
npm run test:php:mysql-sensitive
npm run test:php:mysql-sensitive:editor-settings
npm run test:php:mysql-sensitive:relation-correction
```

### Optimized complete Pest suite

`npm run test:php` is the only supported entry point for the routine complete
PHP suite. The wrapper always applies a 2 GB PHP memory limit, including to
ParaTest workers, and reports the duration of every phase plus the total.

Pest 5.3.0 currently excludes PHPUnit versions newer than 13.3.6. ParaTest 7.26.0
requires PHPUnit 13.4, so the lockfile keeps PHPUnit 13.3.6 and ParaTest 7.25.0
until Pest supports that newer PHPUnit release line.

On Docker Desktop, the checked-out source is a Windows/macOS bind mount. Pest
and Laravel load hundreds of PHP files in every worker, so running directly
from `/var/www/html` makes filesystem I/O dominate the suite. Before a complete
run, the wrapper copies the current checkout once to the Linux-native
`ernie-pest-workspace` Docker volume. It then follows the CI-safe split:

1. tests marked `serial`
2. the `Arch` testsuite without coverage
3. all remaining Unit and Feature tests in parallel without coverage

The workspace omits `public/build` and `public/hot`: these suites disable Vite,
and manifest-specific tests create their own complete fixtures. The Pest CI
jobs therefore install only PHP dependencies and do not build frontend assets.
Browser tests continue to use their existing asset setup.

The default worker count is half of the available CPUs, rounded down, with a
minimum of one and a maximum of eight. Use a measured override only when the
local Docker resource allocation differs substantially:

```bash
ERNIE_PEST_PROCESSES=4 npm run test:php
```

Set `ERNIE_PEST_PROFILE=1` to add Pest's slowest-test report to every complete
suite phase. Focused paths and filters still run directly against the checkout,
so generated snapshots and other intentional source changes are not trapped in
the disposable test workspace:

```bash
npm run test:php -- tests/pest/Unit/Support/UrlNormalizerTest.php
```

Read-only `--list-tests` discovery also synchronizes a fresh native workspace.
This includes dataset expansion and shard selection without repeatedly loading
the full suite through the host bind mount. TIA and coverage commands continue
to use the checkout.

After a failure, rerun the failing path first. Run the complete suite again only
after the focused failure passes; the 2 GB wrapper settings must not be replaced
with the container's former 512 MB limit.

The non-mutating `pint:check` also prepares and checks the Linux-native workspace
to avoid scanning the Windows bind mount. Intentional formatting changes must
still run against the checkout, for example through `composer:app exec`.

The local PHP wrappers verify the Docker `vendor` volume against `composer.lock`,
including development packages and locked revisions. A stale volume stops the
check before testing or resetting a schema. Restore it with
`npm run composer:app -- install`; updating host-side `vendor` does not update
Docker's separate volume.

Pure PHP tests listed in `tests/pest/pure-unit-tests.json` use PHPUnit without
booting Laravel or migrating a database. Their paths and assertions stay intact;
other Unit tests still receive the Laravel integration setup by default. Arch
tests boot Laravel for path helpers but do not migrate a database. New entries
in the pure manifest must work without facades, the container, or database access.

### Measuring test performance

`ERNIE_TEST_TIMINGS_FILE` writes an optional JSON report for Pest, Vitest, MySQL,
and the dev-stack Playwright wrapper. It records startup, test phases, failures,
available host resources, Node version, commit, and whether the checkout is dirty.
Run suites sequentially when comparing performance. A failed or interrupted run
is not a valid performance result.

```powershell
$env:ERNIE_TEST_TIMINGS_FILE = 'storage/logs/pest-timings.json'
$env:ERNIE_PEST_REPORT_DIR = 'storage/logs/pest-junit'
npm run test:php
Remove-Item Env:ERNIE_PEST_REPORT_DIR, Env:ERNIE_TEST_TIMINGS_FILE
```

JUnit reports are optional and copied out of the Linux workspace, including
the failing phase when Pest produced a report. The ordinary wrapper remains quiet
apart from its existing phase timings. `npm run test:inventory` lists configured
test files and reports files outside the default suites; runtime dataset counts
and conditional skips still require actual suite reports.
The current inventory contains 736 configured Pest files (including 23 pure
Unit files), 515 Vitest entry points, and 35 Playwright specs. The 29 Pest
Browser files and four Debug files remain outside the normal PHPUnit suites;
they are optional browser experiments and diagnostic checks, respectively.
The two additional Playwright specs (`authors-contributors.spec.ts` and
`stage/full-workflow-stage.spec.ts`) remain outside shared discovery and are not counted
as executed CI coverage. This refactoring preserves their existing roles.

`npm run test:php:shard-timings` runs the complete backend suite and exports Pest's
native `tests/.pest/shards.json`. Review and commit updated timings when test
workloads change. Pest uses this data to balance the existing CI shards; newly
discovered classes remain included automatically. Normal local runs use the same
worker count and complete discovery.

The fixed aggregate coverage baseline is in `tests/coverage-baseline.json`.
All PHP coverage slices and all frontend blobs must be present before uploading.
The existing final Vitest CI check then waits for the latest successful Pest run
for the same revision and compares complete Codecov integer totals against that
baseline. Failed reruns, missing uploads, timeout, and a one-line loss fail that
workflow, including its existing release dependencies. Codecov's project status
also reports the fixed target; no new per-file or per-area gates are added.
After a successful CI run, the separate manual audit is:

```bash
npm run test:coverage:verify -- --report <complete-codecov-api-json> --commit <tested-sha>
```

The report must belong to that SHA, contain fresh backend and frontend uploads,
and have completed successfully. Passing an audit of the baseline report itself
does not validate an uncommitted refactoring. See
[the refactoring plan and measurements](test-suite-refactoring-plan.md).

### Pest 5 development tools

Use TIA for the short local feedback loop after the first baseline has been recorded:

```bash
npm run test:php:tia
```

The wrapper enables Xdebug coverage inside the app container only for TIA. The normal `test:php` command and CI continue to execute the complete suite. Structural dependency changes invalidate the local TIA graph automatically; use `npm run test:php:tia -- --fresh` if a manual rebuild is needed.

The Agent plugin runs disposable verification snippets with the real Laravel/Pest setup. Keep the outer quotes single so the shell does not expand PHP variables:

```bash
npm run test:php:agent -- 'expect(\App\Models\User::query()->count())->toBeInt();'
```

Turn a useful probe into a permanent test whenever it protects behavior that can regress.

PHPStan uses Pest-aware type inference at level 8. The first migration slice covers the enum tests; expand the test paths in `phpstan.neon` as legacy test typing is repaired instead of masking findings with a baseline:

```bash
npm run phpstan:check
```

Type coverage remains an explicit, slower quality check. The Pest 5 baseline covers all 743 configured PHP source files at 92.93%, so the reproducible command enforces a conservative 92% floor. Mutation testing was evaluated against a focused, fully covered unit: all 35 tests passed, but the stable Pest 5.0.0 mutation plugin then failed internally because it still expects the pre-PHPUnit-13 code-coverage API. Pest itself currently requires the plugin, but Ernie does not expose a broken mutation command. Pest Rector was evaluated in dry-run mode, but its broad style set would rewrite 341 existing test files and was therefore not retained as a dependency.

Why backend validation stays Docker-backed:

- PHP version and extensions remain aligned with the local app container.
- Laravel configuration matches the local Docker runtime.
- Windows developers do not need a separate local PHP installation.
- Complete runs avoid Docker Desktop bind-mount overhead through a Linux-native
  synchronized test workspace.
- Deprecation detail mode has a dedicated npm script because some npm versions treat forwarded `--display-*` flags as npm config and emit warning noise.

## Frontend Validation

Host prerequisite:

```bash
npm ci
```

Recommended commands:

```bash
npm run lint:check
npm run format:check
npm run types
npm run test:run
```

TypeScript 7 uses four parallel type checkers for the application and test projects. This fixed value was the fastest configuration in local measurements across two to twelve checkers while avoiding the substantially higher memory use of larger worker counts.

For continuous application feedback during development, run the native TypeScript 7 watcher alongside the Docker development stack. Use the separate test watcher when editing Vitest types or helpers:

```bash
npm run types:watch
npm run types:watch:test
```

Vitest can repeat a focused test file to expose flaky behavior without multiplying the complete suite:

```bash
npm run test:run -- tests/vitest/path/to/file.test.tsx --repeats=5
```

Vitest 5 reports performance hints when its timing data indicates a likely
configuration improvement. For a measured comparison of pools, isolation,
DOM environments, worker counts, and the filesystem module cache, run:

```bash
npm run test:doctor
```

Doctor executes the complete suite several times. Use it for deliberate
performance work, not as part of the normal validation loop. Adopt a suggested
setting only after its result is reproducible and the complete suite remains
green with shuffled file order and normal project isolation requirements.

The Vitest 5 migration measurement on the standard Node 26.8.1 workstation
confirmed the existing defaults: the isolated thread-pool baseline took
320.57 seconds, while four workers took 533.83 seconds (+67%). The VM pools
failed because the MSW setup needs a global `WritableStream`, and the
non-isolated run exceeded four times the baseline while exposing shared-state
failures. Keep the local eight-worker thread pool and per-file isolation.

For slow startup or import-heavy tests, print the import-duration breakdown before changing optimizer settings:

```bash
npm run test:run -- tests/vitest/path/to/file.test.tsx --experimental.importDurations.print
```

The persistent filesystem module cache is stable in Vitest 5 but remains opt-in because Wayfinder and other plugin inputs must be invalidated correctly. It can be compared on focused repeated runs and cleared explicitly:

```bash
npm run test:run -- tests/vitest/path/to/file.test.tsx --fsModuleCache
npx vitest --clearCache
```

The cache is intentionally not enabled by default. The full Vitest 5 Doctor run
measured 319.29 seconds with a warm cache versus 320.57 seconds without it,
which is not a meaningful improvement. The suite is dominated by DOM
interactions rather than module transformation.

Vitest 5 reserves `toMatchTextContent` for Browser Mode. In the host-side jsdom
suite, use `toHaveTextContent` for string assertions and Vitest's regular
`toMatch` against `element.textContent` when a regular expression is needed.

The large DataCite form suite is registered through six `datacite-form.part-*.test.tsx` entrypoints. They distribute direct tests while keeping nested `describe` groups intact, allowing Vitest to schedule the formerly serial suite across isolated workers. Keep shared tests and setup in `datacite-form.test-suite.tsx`; do not add that support file to the Vitest include pattern.

Local Vitest runs use the faster thread pool and default to half of the
available CPUs, rounded down, with a minimum of one and a maximum of eight. On
the standard 16-CPU workstation, using all 16 workers oversubscribes the
CPU-heavy jsdom DataCite form suites and causes otherwise healthy tests to miss
their timeouts. Override the calculated limit only for a measured reason with
`ERNIE_VITEST_WORKERS=<n>`; CI keeps its own shard and worker allocation.

If your host cannot start Laravel Artisan locally, start the Docker backend stack before Vitest:

```bash
npm run docker:dev:backend:d
npm run test:run
```

The Vitest wrapper checks whether the host can run `php -d memory_limit=2G artisan ernie:wayfinder-generate --with-form` before starting Vitest. The check writes to a temporary directory, so it does not touch the committed Wayfinder output. It also has a timeout, so a hanging host Artisan process falls back to Docker instead of blocking Vitest startup.

The host's Composer packages must match `composer.lock` before that probe.
Missing or stale host packages select the Docker fallback immediately; it
verifies its own Composer packages before generation. When the host probe
succeeds, actual generation uses that same checked PHP binary
and 2 GB limit. Both generator paths therefore use the validation memory floor.

On Windows, the probe resolves PowerShell's active `php` command and executes
the PHP binary selected by Laravel Herd's `php.bat` shim directly. This
preserves Herd's version selection even when an older Herd Lite `php.exe` also
appears later in `PATH`, and ensures a timed-out probe cannot leave a child PHP
process writing into the temporary directory.

If that host check fails, the wrapper prints the failing command, the exit reason, and any captured output before falling back to the app container for Wayfinder route generation. Keep the Docker backend stack running for that fallback path:

```bash
npm run docker:dev:backend:d
```

`WAYFINDER_COMMAND` is the supported escape hatch for custom setups, for example:

```bash
WAYFINDER_COMMAND="php -d memory_limit=2G artisan ernie:wayfinder-generate" npm run test:run
```

The separate `vitest.browser.config.ts` deliberately contains only browser-test transforms. Laravel HMR and Wayfinder generation stay in the main Vite configuration: Vitest 5 starts multiple browser environments, and generating files from their `buildStart` hooks can repeatedly invalidate the browser test server. Generate Wayfinder sources before introducing or running browser tests that import them.

Why frontend validation stays on the host:

- Host-side Node feedback is faster than spawning short-lived container commands.
- `npm run test` remains available for watch mode, but it is not the default validation command.
- `npm run lint` remains the auto-fix command, while `npm run lint:check` is the safe validation path.

CI formatter jobs are non-mutating and check the complete frontend and PHP codebases. Use `npm run format` and `npm run lint` for frontend auto-fixes; run Pint without `--test` when intentionally applying PHP formatting changes.

## Browser Validation

### Local browser verification

The validation UX suite reuses a completed UI login per worker in memory.
Every scenario still gets an isolated browser context; login/session tests keep
their own UI flow. It waits for validation badges, accordion state, dropdown
focus restoration, and completed backend responses rather than fixed pauses.
Before copying the worker's authenticated state, it explicitly completes a real
CSRF-cookie request through the context's shared cookie jar. XML workflows use
the same preparation after their own UI login. Ordinary UI login only requires
the successful dashboard redirect: the application's optional automatic CSRF
refresh can abort after five seconds. A separate regression scenario verifies
login and explicit preparation with those automatic requests deliberately aborted.
The previous CI WebKit skip for this file has been removed after two successful
complete local runs in each browser (144 executions, no retries or skips).

IGSN workflows import complete CSV payloads with a unique sample identifier per
test, including its occurrence in the title. Parallel deletion and export must
operate on separate resources. The duplicate scenario uploads the same own
payload twice and requires the first import to succeed. UI deletion waits for
its HTTP redirect and the refreshed list; exports also verify their own IGSN
in the returned metadata and filename.

The modal preview switch test completes the real download URL suggestion
response before writing the next session preview. Each transition also requires
a completed HTTP 201 preview response. Its original request/download/removal
assertions remain in place. This checks the functional transitions after the
preceding input request finishes; concurrent session updates remain a separate
application concern documented in the refactoring plan.

The contact and preview specs give each test a separate client identity in
`2001:db8:ee00::/48`. The normal per-IP contact limits stay enabled, including
within a test; messages from previous or concurrent tests do not consume that
test's allowance. CI's direct Laravel server receives the forwarded test IP.
For the local Traefik path, the wrapper derives a temporary Nginx configuration
from the unchanged development configuration. It forwards only marked client
identities in the reserved range and preserves ordinary forwarded addresses.
It restores both app and webserver configurations and removes its generated
routing file after successful restoration. An existing routing file prevents a
second wrapper from changing services; if restoration fails, the mounted file
remains available for recovery. No contact records are deleted for isolation.

Use the Docker dev stack behind Traefik:

```bash
npm run docker:dev:up:d
npm run test:e2e:devstack
```

This path exercises the local routing setup at `https://ernie.localhost:3333`.
The wrapper temporarily applies `docker-compose.playwright-test.yml` to the app
so contact submissions use synchronous log mail, a fixed example.test team
recipient, and DataCite's existing fake service. Inertia DevTools request
recording is disabled for this temporary browser backend. A backend preflight verifies
these settings before starting browsers. It restores the normal
app and webserver Compose configurations even if preparation or browser tests fail. Nginx reloads
after both app container changes to refresh its FPM upstream. Before starting
tests, the public `/login` route must return HTTP 200 HTML. A separate readiness
phase waits up to 60 seconds for temporary gateway or connection failures;
application errors fail immediately. Browser retries and test timeouts remain
unchanged. `.env` remains untouched. `--list` only discovers tests and does not restart services. Seed the
documented Playwright fixtures before running browser scenarios. Optional JSON
timings include backend preparation and restoration separately.

Fresh native workspaces omit generated `storage/inertia-devtools` recordings
alongside logs and framework caches. Development recordings stay in place;
they are runtime diagnostics rather than test or build inputs.
The browser app uses a bounded Linux-native compiled-view cache to avoid
concurrent Blade compilation on the Docker Desktop bind mount.

For complete runs, `ERNIE_PLAYWRIGHT_ASSETS=build` builds the current checkout in
the Linux-native workspace and serves those assets instead of Vite modules.
This avoids waiting for Vite CSS/module compilation during browser navigation.
The build is fresh each time, uses the verified Docker dependencies and a 2 GB
Wayfinder process, and adds its own preparation time to the timing report.
The wrapper restores the original `public/hot` bytes even on failure; the Vite
service keeps running. The default remains `vite` for normal local feedback.

```powershell
$env:ERNIE_PLAYWRIGHT_ASSETS = 'build'
npm run test:e2e:devstack
Remove-Item Env:ERNIE_PLAYWRIGHT_ASSETS
```

Local runs keep failure screenshots and make videos opt-in with
`ERNIE_PLAYWRIGHT_VIDEO=1`; recording passing scenarios before discarding their
videos adds runtime cost. CI keeps its existing recording settings. Use
`--trace=on` when a detailed local failure trace is needed.

If Windows WebKit fails to connect to the local HTTPS subdomain, the optional
`ERNIE_WEBKIT_WS_ENDPOINT` connects just that browser to a Linux Playwright
server. The test runner remains on the host with the pinned Node version and
forwards loopback requests, including `ernie.localhost`, to the helper. Chromium and
Firefox continue to use their normal host browsers. The server must match the
installed Playwright version. For the current 1.63.0 dependency:

```powershell
docker run --rm --init -d --name ernie-webkit-tests --workdir /home/pwuser --user pwuser -p 127.0.0.1:3043:3000 mcr.microsoft.com/playwright:v1.63.0-noble npx --yes playwright@1.63.0 run-server --port 3000 --host 0.0.0.0
$env:ERNIE_WEBKIT_WS_ENDPOINT = 'ws://127.0.0.1:3043/'
npm run test:e2e:devstack -- --project=webkit
Remove-Item Env:ERNIE_WEBKIT_WS_ENDPOINT
docker stop ernie-webkit-tests
```

Wait for the server's listening message before running tests. See Playwright's
[remote browser documentation](https://playwright.dev/docs/docker#remote-connection).

### Stage bug reproduction

Use stage only when the problem is known to be stage-specific or was explicitly reported there:

```bash
npm run test:e2e:stage
```

## Coverage Guidance

- Run local coverage only when targeted feedback is needed.
- Keep day-to-day backend runs on `--no-coverage`.
- Let CI remain the primary source of complete coverage reporting.
- CI runs the Vitest coverage suite on three machines with `--shard=1/3`, `--shard=2/3`, and `--shard=3/3`. Each machine uploads its Vitest 5 blob report from `.vitest/blob`; the final `vitest` job merges all test and V8 coverage results before uploading the single complete `coverage/lcov.info` to Codecov.
- Keep the blob upload and merge job together when changing the workflow. Uploading either shard's partial LCOV report would make the Codecov result incomplete.
- CI runs the serial and architecture Pest slices alongside two disjoint shards of the remaining test suite. Serial and parallel tests collect the configured line coverage with PCOV into separate Clover reports; the final `Pest PHP Tests` job uploads all three reports together. PCOV remains rooted at the repository so `routes/`, `config/`, and `database/` can contribute coverage, while `vendor/` and `tests/` are excluded before instrumentation. Each parallel shard gives the coverage-merging parent process 4 GB of memory while its ParaTest workers retain the configured 1 GB limit. Architecture tests remain coverage-free because their structural assertions do not produce meaningful runtime coverage.

## Suggested Validation Sets

Backend change:

```bash
npm run check:backend
```

Frontend change:

```bash
npm run check:frontend
```

Cross-stack or browser-facing change:

```bash
npm run check:backend
npm run check:frontend
npm run check:parity
```
