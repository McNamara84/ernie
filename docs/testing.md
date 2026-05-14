# Local Testing Guide

## Overview

ERNIE uses a hybrid local testing workflow:

- PHP, Composer, Artisan, Pest, and PHPStan are container-first.
- Vitest, ESLint, TypeScript, and Playwright run best from the host shell.
- The default PHP test path stays fast by forcing SQLite in memory.
- MySQL-specific verification should stay targeted and intentional.

Canonical entry points:

- `npm run check:backend`
- `npm run check:frontend`
- `npm run check:parity`

## Recommended Command Matrix

| Check | Where to run it | Command | Notes |
| --- | --- | --- | --- |
| Pest fast path | Host shell via npm wrapper | `npm run test:php` | Starts backend containers if needed |
| PHPStan | Host shell via npm wrapper | `npm run phpstan:check` | Required before finishing PHP changes |
| MySQL-sensitive Pest slice | Host shell via npm wrapper | `npm run test:php:mysql-sensitive` | Uses isolated `ernie_test` schema |
| Vitest one-shot | Host shell | `npm run test:run` | Use for focused frontend validation |
| Vitest coverage | Host shell | `npm run test:coverage` | Use only when coverage details matter |
| ESLint check | Host shell | `npm run lint:check` | Non-mutating validation |
| ESLint auto-fix | Host shell | `npm run lint` | Use when you want ESLint to rewrite files |
| TypeScript | Host shell | `npm run types` | Runs app and test TS checks |
| Playwright dev stack | Host shell | `npm run test:e2e:devstack` | Requires the Docker dev stack |
| Playwright stage | Host shell | `npm run test:e2e:stage` | Stage-only bug reproduction |
| Backend umbrella check | Host shell | `npm run check:backend` | Pest plus PHPStan |
| Frontend umbrella check | Host shell | `npm run check:frontend` | ESLint plus TS plus one-shot Vitest |
| Parity umbrella check | Host shell | `npm run check:parity` | Parity profile plus MySQL slice plus Playwright |

## PHP Test Database Strategy

The default PHP suite is intentionally optimized for speed.

- `tests/pest/CreatesApplication.php` forces `APP_ENV=testing`.
- The same bootstrap forces `DB_CONNECTION=sqlite` and `DB_DATABASE=:memory:`.
- This prevents Docker runtime environment variables from accidentally switching tests to MySQL.

Use the SQLite path for the routine local loop.

Use a MySQL-backed slice only when one of these is true:

- a migration behaves differently across drivers
- a query depends on MySQL-specific behavior
- a failing production or stage bug cannot be reproduced against SQLite

The current `mysql-sensitive` Pest group covers driver-aware migration tests and runs against a dedicated MySQL schema named `ernie_test`.

The npm wrapper recreates that schema before each schema-mutating file so DDL-heavy migration tests do not leak state into the next process.

## Pest And PHPStan

Recommended commands:

```bash
npm run test:php
npm run phpstan:check
npm run test:php:mysql-sensitive
```

Why this stays in Docker:

- PHP version and extensions stay aligned with the local app container.
- Laravel configuration matches the Docker development runtime.
- Windows developers do not need a separate local PHP installation.

## Vitest, ESLint, And TypeScript

Recommended commands:

```bash
npm run lint:check
npm run types
npm run test:run
```

Why these stay on the host:

- Host-side Node feedback is faster than spawning short-lived container commands.
- `npm run test` remains available for watch mode, but it is not the default validation command.
- `npm run lint` remains the auto-fix command, while `npm run lint:check` is the safe validation path.

## Browser Tests

### Local browser verification

Use the dev stack behind Traefik:

```bash
npm run docker:dev:up:d
npm run test:e2e:devstack
```

This path exercises the real local routing setup at `https://ernie.localhost:3333`.

### Stage bug reproduction

Use stage only when the problem is known to be stage-specific or explicitly reported there:

```bash
npm run test:e2e:stage
```

## Coverage Guidance

- Run local coverage only when you need targeted feedback.
- Keep day-to-day backend runs on `--no-coverage`.
- Let CI remain the primary source of complete coverage reporting.

## Suggested Fast Validation Sets

### Backend change

```bash
npm run check:backend
```

### Frontend change

```bash
npm run check:frontend
```

### Cross-stack or browser-facing change

```bash
npm run check:backend
npm run check:frontend
npm run check:parity
```