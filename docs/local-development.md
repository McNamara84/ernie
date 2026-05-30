# Local Development

## Overview

ERNIE uses a Docker-first local workflow.

- Fast Mode is the default path for day-to-day development.
- Optional profiles are available for assessment-specific and parity-specific work.
- Canonical validation entry points remain `npm run check:backend`, `npm run check:frontend`, and `npm run check:parity`.

| Mode | Purpose | Command |
| --- | --- | --- |
| Fast Mode | Start the core development stack only | `npm run docker:dev:up` |
| Assessment profile | Start the stack with the F-UJI container for assessment work; also set `FUJI_ENABLED=true` in `.env.docker` if the app should use it | `npm run docker:dev:assessment` |
| Parity profile | Start the stack with the parity-oriented optional services; also set `FUJI_ENABLED=true` in `.env.docker` if the app should use F-UJI | `npm run docker:dev:parity` |

Fast Mode is the default because it keeps optional services out of the normal startup path.

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

3. Start Fast Mode.

   ```bash
   npm run docker:dev:up
   ```

4. Trust `docker\traefik\certs\localhost.crt` on Windows if your browser warns about the local TLS certificate.

5. Open the application.

   - Main URL: `https://ernie.localhost:3333`
   - Localhost fallback after switching `ERNIE_DEV_HOST` and `ERNIE_DEV_SESSION_DOMAIN`: `https://localhost:3333`

   If `ernie.localhost` does not resolve, add `127.0.0.1 ernie.localhost` to your hosts file.

6. Create the first administrator account.

   ```bash
   docker compose --env-file .env.docker -f docker-compose.dev.yml exec app php artisan add-user "Admin Name" admin@example.com SecurePassword
   ```

The development entrypoint installs missing dependencies, runs migrations, and seeds baseline data when the database is empty.

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
- `parity` starts the parity-oriented optional services; set `FUJI_ENABLED=true` in `.env.docker` when the app should use F-UJI

Common startup commands:

```bash
npm run docker:dev:up
npm run docker:dev:assessment
npm run docker:dev:parity
```

## Command Reference

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
| Playwright against the dev stack | Host shell | `npm run test:e2e:devstack` |
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