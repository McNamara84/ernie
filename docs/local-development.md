# Local Development Guide

## Overview

ERNIE has two local operating speeds:

| Mode | Purpose | Command |
| --- | --- | --- |
| Fast Mode | Daily development with the core stack only | `npm run docker:dev:up` |
| Assessment profile | Start the F-UJI container when assessment workflows are under active development; also set `FUJI_ENABLED=true` in `.env.docker` if the app should use it | `npm run docker:dev:assessment` |
| Parity profile | Start the parity-oriented optional services for broader local verification; also set `FUJI_ENABLED=true` in `.env.docker` if the app should use F-UJI | `npm run docker:dev:parity` |

Canonical checks:

- `npm run check:backend`
- `npm run check:frontend`
- `npm run check:parity`

Fast Mode is the default. Optional services no longer slow down or block the standard local startup path.

## Recommended Windows Setup

### Preferred: WSL2 checkout

This is the recommended path for Windows developers because Docker bind mounts and Node-based tooling are significantly faster inside the WSL filesystem.

1. Install Docker Desktop with WSL2 integration enabled.
2. Clone the repository inside your WSL home directory, for example `~/src/ernie`.
3. Open the project through VS Code Remote - WSL.
4. Run Docker Compose and host-side Node commands from the WSL shell.
5. Use your Windows browser for `https://ernie.localhost:3333` if you prefer.

### Supported fallback: Windows checkout on NTFS

If you keep the repository under `D:\` or another NTFS path:

- Expect slower bind-mount performance than WSL2.
- Keep `VITE_USE_POLLING=true` enabled.
- If HMR becomes unreliable, check the `public/hot` troubleshooting step below.

## Quick Start

1. Generate certificates:

   ```powershell
   .\docker\generate-certs.ps1
   ```

2. Create the Docker env file:

   ```powershell
   Copy-Item .env.docker.example .env.docker
   ```

3. Start Fast Mode:

   ```powershell
   npm run docker:dev:up
   ```

4. Trust `docker\traefik\certs\localhost.crt` in Windows if your browser warns about TLS.

5. Open the app:

   - `https://ernie.localhost:3333`
   - localhost fallback after switching `ERNIE_DEV_HOST` and `ERNIE_DEV_SESSION_DOMAIN`: `https://localhost:3333`

6. Create the first admin user:

   ```powershell
   docker compose --env-file .env.docker -f docker-compose.dev.yml exec app php artisan add-user "Admin Name" admin@example.com SecurePassword
   ```

The development entrypoint already installs missing dependencies, runs migrations, and seeds baseline data when the database is empty.

## Service Profiles

Default Fast Mode services:

- Traefik
- app
- webserver
- vite
- db
- redis
- queue

Optional profiles:

- `assessment`: starts the F-UJI container; set `FUJI_ENABLED=true` in `.env.docker` when the app should use it
- `parity`: starts the parity-oriented optional services; set `FUJI_ENABLED=true` in `.env.docker` when the app should use F-UJI

Examples:

```powershell
# Fast Mode
npm run docker:dev:up

# Fast Mode with the F-UJI container
# Also set FUJI_ENABLED=true in .env.docker if the app should use it.
npm run docker:dev:assessment

# Fast Mode with the parity-oriented optional services
# Also set FUJI_ENABLED=true in .env.docker if the app should use F-UJI.
npm run docker:dev:parity
```

## Command Matrix

| Task | Recommended place | Command |
| --- | --- | --- |
| Start the core stack | Host shell | `npm run docker:dev:up` |
| Start the backend services needed for PHP checks | Host shell | `npm run docker:dev:backend:d` |
| Stop the stack | Host shell | `npm run docker:dev:down` |
| Reset Docker volumes | Host shell | `npm run docker:dev:reset` |
| Laravel Artisan | app container | `docker compose --env-file .env.docker -f docker-compose.dev.yml exec app php artisan <command>` |
| Composer | app container | `docker compose --env-file .env.docker -f docker-compose.dev.yml exec app composer <command>` |
| Pest | Host shell via npm wrapper | `npm run test:php` |
| MySQL-sensitive Pest slice | Host shell via npm wrapper | `npm run test:php:mysql-sensitive` |
| PHPStan | Host shell via npm wrapper | `npm run phpstan:check` |
| Vitest | Host shell | `npm run test:run` |
| ESLint check | Host shell | `npm run lint:check` |
| ESLint auto-fix | Host shell | `npm run lint` |
| TypeScript | Host shell | `npm run types` |
| Playwright against dev stack | Host shell | `npm run test:e2e:devstack` |
| Canonical backend validation | Host shell | `npm run check:backend` |
| Canonical frontend validation | Host shell | `npm run check:frontend` |
| Canonical parity validation | Host shell | `npm run check:parity` |

## Environment Files

- `.env.docker` is the Docker-oriented local environment file.
- `.env` is the Laravel application environment used inside the containers.
- The development entrypoint copies `.env.docker` to `.env` when `.env` does not already exist.
- The npm Docker wrappers always pass `--env-file .env.docker` so Compose and Laravel use the same source of truth.

## Troubleshooting

### `419 Page Expired`

- A plain URL switch to `https://localhost:3333` is not enough while `ERNIE_DEV_SESSION_DOMAIN=ernie.localhost`.
- For a localhost fallback, set `ERNIE_DEV_HOST=localhost` and `ERNIE_DEV_SESSION_DOMAIN=localhost` in `.env.docker`, keep `localhost:3333` in `ERNIE_DEV_STATEFUL_DOMAINS`, then restart the stack.

### `public/hot` is missing on Windows

Docker Desktop can fail to sync the file back to the host even when it exists in the container.

```powershell
docker compose --env-file .env.docker -f docker-compose.dev.yml exec vite sh -c 'echo "https://ernie.localhost:3333" > /var/www/html/public/hot'
```

### Optional services are not reachable

That is expected unless you started the matching profile:

- `npm run docker:dev:assessment`
- `npm run docker:dev:parity`

For F-UJI specifically, the app will still treat the integration as disabled until `FUJI_ENABLED=true` is set in `.env.docker` and the stack is restarted.

### The first startup feels slow

The initial boot may still need to:

- build images
- install Composer dependencies
- install npm dependencies
- run migrations
- seed baseline data

Subsequent startups should be much faster because Docker volumes keep `vendor`, `node_modules`, and the MySQL data directory.

## MySQL Test Slice

The fast default Pest loop remains SQLite-backed.

When you need driver-sensitive coverage, use:

```powershell
npm run test:php:mysql-sensitive
```

This command:

- starts the backend containers if needed
- creates an isolated `ernie_test` schema inside the local MySQL container
- runs the current explicit MySQL-sensitive migration file slice with a schema reset before each file

It does not reuse the regular development schema.