# Local Testing

## Overview

ERNIE uses a split local validation workflow.

- PHP, Composer, Artisan, Pest, and PHPStan are container-first.
- Vitest, ESLint, TypeScript, and Playwright run from the host shell.
- The default PHP path stays fast by using SQLite in memory.
- MySQL-specific verification stays targeted and explicit.

Canonical entry points:

- `npm run check:backend`
- `npm run check:frontend`
- `npm run check:parity`

## Recommended Commands

| Check | Where to run it | Command | Notes |
| --- | --- | --- | --- |
| Pest fast path | Host shell via npm wrapper | `npm run test:php` | Starts backend containers if needed |
| PHPStan | Host shell via npm wrapper | `npm run phpstan:check` | Required before finishing PHP changes |
| MySQL-sensitive Pest slice | Host shell via npm wrapper | `npm run test:php:mysql-sensitive` | Uses isolated `ernie_test` schema |
| Vitest one-shot | Host shell | `npm run test:run` | Preferred for focused frontend validation |
| Vitest coverage | Host shell | `npm run test:coverage` | Use only when coverage detail is needed |
| ESLint check | Host shell | `npm run lint:check` | Non-mutating validation |
| ESLint auto-fix | Host shell | `npm run lint` | Applies ESLint fixes |
| TypeScript | Host shell | `npm run types` | Runs app and test TS checks |
| Playwright dev stack | Host shell | `npm run test:e2e:devstack` | Requires the Docker dev stack |
| Playwright stage | Host shell | `npm run test:e2e:stage` | Use only for stage-specific bug reproduction |
| Backend umbrella check | Host shell | `npm run check:backend` | Pest plus PHPStan |
| Frontend umbrella check | Host shell | `npm run check:frontend` | ESLint plus OpenAPI lint plus TypeScript plus one-shot Vitest |
| Parity umbrella check | Host shell | `npm run check:parity` | Parity profile plus MySQL slice plus Playwright |

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
npm run phpstan:check
npm run test:php:mysql-sensitive
```

Why backend validation stays Docker-backed:

- PHP version and extensions remain aligned with the local app container.
- Laravel configuration matches the local Docker runtime.
- Windows developers do not need a separate local PHP installation.

## Frontend Validation

Recommended commands:

```bash
npm run lint:check
npm run types
npm run test:run
```

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