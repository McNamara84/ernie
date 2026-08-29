# Local Testing

## Overview

ERNIE uses a split local validation workflow.

- PHP, Composer, Artisan, Pest, and PHPStan are container-first.
- Vitest, ESLint, TypeScript, and Playwright run from the host shell.
- Host-side frontend checks require local `node_modules` in the repository checkout.
- The default PHP path stays fast by using SQLite in memory.
- MySQL-specific verification stays targeted and explicit.

Canonical entry points:

- `npm run check:backend`
- `npm run check:frontend`
- `npm run check:parity`

Run `npm install` once after cloning and again whenever frontend dependencies change. The Docker entrypoints install npm packages only inside Docker-managed volumes and do not satisfy host-side frontend commands.

## Recommended Commands

| Check                      | Where to run it            | Command                                     | Notes                                                          |
| -------------------------- | -------------------------- | ------------------------------------------- | -------------------------------------------------------------- |
| Pest complete suite        | Host shell via npm wrapper | `npm run test:php`                          | Linux-native workspace; serial/Arch split; parallel remainder  |
| Pest TIA                   | Host shell via npm wrapper | `npm run test:php:tia`                      | Local-only affected-test loop; records a baseline on first use |
| Pest deprecation details   | Host shell via npm wrapper | `npm run test:php:deprecations`             | Use this instead of forwarding `--display-*` flags through npm |
| Pest Agent probe           | Host shell via npm wrapper | `npm run test:php:agent -- '<PHP snippet>'` | One-off verification; not a replacement for a regression test  |
| PHPStan                    | Host shell via npm wrapper | `npm run phpstan:check`                     | Required before finishing PHP changes                          |
| Pest type coverage         | Host shell via npm wrapper | `npm run test:php:type-coverage`            | Enforces the measured 92% minimum; expensive on a cold cache   |
| MySQL-sensitive Pest slice | Host shell via npm wrapper | `npm run test:php:mysql-sensitive`          | Uses isolated `ernie_test` schema                              |
| Vitest one-shot            | Host shell                 | `npm run test:run`                          | Preferred for focused frontend validation                      |
| Vitest coverage            | Host shell                 | `npm run test:coverage`                     | Use only when coverage detail is needed                        |
| ESLint check               | Host shell                 | `npm run lint:check`                        | Non-mutating validation                                        |
| ESLint auto-fix            | Host shell                 | `npm run lint`                              | Applies ESLint fixes                                           |
| TypeScript                 | Host shell                 | `npm run types`                             | Runs app and test TS checks                                    |
| Playwright dev stack       | Host shell                 | `npm run test:e2e:devstack`                 | Requires the Docker dev stack                                  |
| Playwright stage           | Host shell                 | `npm run test:e2e:stage`                    | Use only for stage-specific bug reproduction                   |
| Backend umbrella check     | Host shell                 | `npm run check:backend`                     | Pest plus PHPStan                                              |
| Frontend umbrella check    | Host shell                 | `npm run check:frontend`                    | ESLint plus OpenAPI lint plus TypeScript plus one-shot Vitest  |
| Parity umbrella check      | Host shell                 | `npm run check:parity`                      | Parity profile plus MySQL slice plus Playwright                |

## PHP Test Database Strategy

The default PHP suite is intentionally optimized for speed.

- `tests/pest/CreatesApplication.php` forces `APP_ENV=testing`.
- The same bootstrap defaults `DB_CONNECTION=sqlite` and `DB_DATABASE=:memory:`.
- Setting `ERNIE_TEST_DB_CONNECTION` switches the dedicated MySQL-sensitive slice to its isolated Docker test schema instead.

Use the SQLite path for the routine local loop.

Use a MySQL-backed slice only when one of the following is true:

- a migration behaves differently across drivers
- a query depends on MySQL-specific behavior
- a failing production or stage bug cannot be reproduced against SQLite

The npm wrapper runs the current explicit schema-mutating MySQL-sensitive file slice against a dedicated MySQL schema named `ernie_test`.

That wrapper recreates the schema before each file so DDL-heavy migration tests do not leak state into the next process.

## Backend Validation

Recommended commands:

```bash
npm run test:php
npm run test:php:tia
npm run test:php:deprecations -- tests/pest/Unit/Enums/UserRoleTest.php
npm run phpstan:check
npm run test:php:mysql-sensitive
```

### Optimized complete Pest suite

`npm run test:php` is the only supported entry point for the routine complete
PHP suite. The wrapper always applies a 2 GB PHP memory limit, including to
ParaTest workers, and reports the duration of every phase plus the total.

On Docker Desktop, the checked-out source is a Windows/macOS bind mount. Pest
and Laravel load hundreds of PHP files in every worker, so running directly
from `/var/www/html` makes filesystem I/O dominate the suite. Before a complete
run, the wrapper copies the current checkout once to the Linux-native
`ernie-pest-workspace` Docker volume. It then follows the CI-safe split:

1. tests marked `serial`
2. the `Arch` testsuite without coverage
3. all remaining Unit and Feature tests in parallel without coverage

The default worker count is half of the available CPUs, capped at eight. Use a
measured override only when the local Docker resource allocation differs
substantially:

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

After a failure, rerun the failing path first. Run the complete suite again only
after the focused failure passes; the 2 GB wrapper settings must not be replaced
with the container's former 512 MB limit.

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
npm run types
npm run test:run
```

Vitest can repeat a focused test file to expose flaky behavior without multiplying the complete suite:

```bash
npm run test:run -- tests/vitest/path/to/file.test.tsx --repeats=5
```

For slow startup or import-heavy tests, print the import-duration breakdown before changing optimizer settings:

```bash
npm run test:run -- tests/vitest/path/to/file.test.tsx --experimental.importDurations.print
```

The persistent filesystem module cache remains an opt-in experiment because Wayfinder and other plugin inputs must be invalidated correctly. It can be compared on focused repeated runs and cleared explicitly:

```bash
npm run test:run -- tests/vitest/path/to/file.test.tsx --fsModuleCache
npx vitest --clearCache
```

The cache is intentionally not enabled by default. A representative DataCite run took 2:24 without it, 2:29 with a cold cache, and 2:44 with a warm cache; this suite is dominated by DOM interactions rather than module transformation.

The large DataCite form suite is registered through six `datacite-form.part-*.test.tsx` entrypoints. They distribute direct tests while keeping nested `describe` groups intact, allowing Vitest to schedule the formerly serial suite across isolated workers. Keep shared tests and setup in `datacite-form.test-suite.tsx`; do not add that support file to the Vitest include pattern.

Local Vitest runs use the faster thread pool but cap it at eight workers. On the
standard 16-CPU workstation, using all 16 workers oversubscribes the CPU-heavy
jsdom DataCite form suites and causes otherwise healthy tests to miss their
timeouts. Override the cap only for a measured reason with
`ERNIE_VITEST_WORKERS=<n>`; CI keeps its own shard and worker allocation.

If your host cannot start Laravel Artisan locally, start the Docker backend stack before Vitest:

```bash
npm run docker:dev:backend:d
npm run test:run
```

The Vitest wrapper checks whether the host can run `php artisan ernie:wayfinder-generate --with-form` before starting Vitest. The check writes to a temporary directory, so it does not touch the committed Wayfinder output. It also has a timeout, so a hanging host Artisan process falls back to Docker instead of blocking Vitest startup.

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
WAYFINDER_COMMAND="php artisan ernie:wayfinder-generate" npm run test:run
```

The separate `vitest.browser.config.ts` deliberately contains only browser-test transforms. Laravel HMR and Wayfinder generation stay in the main Vite configuration: Vitest 5 starts multiple browser environments, and generating files from their `buildStart` hooks can repeatedly invalidate the browser test server. Generate Wayfinder sources before introducing or running browser tests that import them.

Why frontend validation stays on the host:

- Host-side Node feedback is faster than spawning short-lived container commands.
- `npm run test` remains available for watch mode, but it is not the default validation command.
- `npm run lint` remains the auto-fix command, while `npm run lint:check` is the safe validation path.

## Browser Validation

### Local browser verification

Use the Docker dev stack behind Traefik:

```bash
npm run docker:dev:up:d
npm run test:e2e:devstack
```

This path exercises the local routing setup at `https://ernie.localhost:3333`.

### Stage bug reproduction

Use stage only when the problem is known to be stage-specific or was explicitly reported there:

```bash
npm run test:e2e:stage
```

## Coverage Guidance

- Run local coverage only when targeted feedback is needed.
- Keep day-to-day backend runs on `--no-coverage`.
- Let CI remain the primary source of complete coverage reporting.
- CI runs the Vitest coverage suite on two machines with `--shard=1/2` and `--shard=2/2`. Each machine uploads a Vitest blob report; the final `vitest` job merges both test and V8 coverage results before uploading the single complete `coverage/lcov.info` to Codecov.
- Keep the blob upload and merge job together when changing the workflow. Uploading either shard's partial LCOV report would make the Codecov result incomplete.

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
