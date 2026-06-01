# ERNIE

![Laravel](https://img.shields.io/badge/Laravel-13-FF2D20?logo=laravel&logoColor=white)
![PHP](https://img.shields.io/badge/PHP-8.5-777BB4?logo=php&logoColor=white)
![React](https://img.shields.io/badge/React-19-61DAFB?logo=react&logoColor=white)
[![codecov](https://codecov.io/gh/McNamara84/ernie/graph/badge.svg)](https://codecov.io/gh/McNamara84/ernie)

ERNIE is a metadata curation system for research datasets at GFZ Helmholtz Centre for Geosciences. It supports DataCite Metadata Schema v4.7 for DOI registration, IGSN registration for physical samples, public landing pages for published records, and a search portal for curated datasets.

## Key Capabilities

- DataCite metadata curation for DOI-ready dataset records
- IGSN workflows with CSV import and hierarchical sample relationships
- Public landing pages and DOI-oriented publication views
- Search and discovery workflows for published resources
- ORCID, ROR, GCMD, SPDX, and MSL integration for enriched metadata
- Role-based access control for editorial and administrative workflows
- Container-first development and validation workflow for consistent local setup

## Local Development With Docker

This README documents the Docker-based development workflow only. For deeper setup notes, platform-specific troubleshooting, and the full command matrix, see [docs/local-development.md](docs/local-development.md).

### Prerequisites

- Docker Desktop
- Node.js 26 or newer and npm 10 or newer on the host
- OpenSSL support for generating local certificates
- On Windows, WSL2 is recommended, ideally with VS Code Remote - WSL
- On macOS, Docker Desktop runs on both Apple Silicon and Intel Macs. On Apple Silicon, enable **Use Rosetta for x86_64/amd64 emulation** under Docker Desktop → Settings → General so images without a native `arm64` build still run reliably.
- On macOS, the default shell (`zsh`) and the bundled `openssl` (LibreSSL) work out of the box. If Node.js is not installed yet, the easiest route is [Homebrew](https://brew.sh) (`brew install node`) or a version manager such as `nvm`.

### Step-By-Step Setup

1. Clone the repository:

```
   git clone https://github.com/McNamara84/ernie.git
   cd ernie
```

2. Generate local TLS certificates:

   Windows PowerShell:

```
   .\docker\generate-certs.ps1
```

   macOS, Linux, WSL, Git Bash, or other POSIX shells:

```
   ./docker/generate-certs.sh
```

   On macOS the browser will flag the self-signed certificate on first launch. To trust it system-wide, add it to the system keychain:

```
   sudo security add-trusted-cert -d -r trustRoot -k /Library/Keychains/System.keychain docker/traefik/certs/localhost.crt
```

3. Create the Docker environment file:

   Windows PowerShell:

```
   Copy-Item .env.docker.example .env.docker
```

   macOS, Linux, WSL, Git Bash, or other POSIX shells:

```
   cp .env.docker.example .env.docker
```

   The default values work for a standard local setup.

4. Install host-side Node dependencies for frontend validation:

```
   npm install
```

   This installs the local `node_modules` required by ESLint, TypeScript, Vitest, OpenAPI linting, and Playwright.

5. Start the default development stack:

```
   npm run docker:dev:up
```

   The first startup can take a few minutes because Docker may need to build images, install dependencies, run migrations, and seed baseline data.

6. Open the application:

   - Main URL: <https://ernie.localhost:3333>
   - Traefik dashboard: <http://localhost:8080>

   If `ernie.localhost` does not resolve on your machine, add `127.0.0.1 ernie.localhost` to your hosts file. On macOS and Linux this is `/etc/hosts`, for example:

```
   echo "127.0.0.1 ernie.localhost" | sudo tee -a /etc/hosts
```

7. Create the first administrator account:

```
   docker compose --env-file .env.docker -f docker-compose.dev.yml exec app php artisan add-user "Admin Name" admin@example.com SecurePassword
```

   The first user created in a fresh environment becomes an administrator automatically.

8. Initialize SPDX license data:

```
   docker compose --env-file .env.docker -f docker-compose.dev.yml exec app php artisan spdx:sync-licenses
```

The Docker entrypoints install missing Composer dependencies and container-local npm dependencies, run migrations, and seed baseline data when the database is empty. Host-side frontend commands still require the local `npm install` step above.

### Daily Commands

Use the npm wrapper commands whenever possible so Docker Compose and Laravel stay aligned with `.env.docker`.

Host-side frontend commands in this repository require local `node_modules` in your checkout.

| Command | Purpose |
| --- | --- |
| `npm run docker:dev:up` | Start the default development stack in the foreground |
| `npm run docker:dev:up:d` | Start the default development stack in the background |
| `npm run docker:dev:down` | Stop the development stack |
| `npm run docker:dev:reset` | Stop the stack and remove Docker volumes |
| `npm run docker:dev:assessment` | Start the stack with the assessment profile, which adds the F-UJI container |
| `npm run docker:dev:parity` | Start the stack with the parity profile, which currently adds the F-UJI container |
| `npm run check:backend` | Run Pest and PHPStan against the Docker-backed backend workflow |
| `npm run check:frontend` | Run ESLint, OpenAPI linting, TypeScript checks, and one-shot Vitest on the host |
| `npm run check:parity` | Run the parity validation flow, including the MySQL-sensitive backend slice and Playwright |

For ad-hoc Laravel commands, use the app container directly:

```bash
docker compose --env-file .env.docker -f docker-compose.dev.yml exec app php artisan <command>
```

## Testing And Quality Checks

ERNIE uses a split local validation workflow:

- PHP, Composer, Artisan, Pest, and PHPStan run against the Docker development stack
- ESLint, TypeScript, Vitest, and Playwright run from the host shell

Host-side frontend validation requires local `node_modules` in the repository checkout. Run `npm install` once after cloning and again whenever frontend dependencies change.

Recommended validation entry points:

- `npm run check:backend`
- `npm run check:frontend`
- `npm run check:parity`

For the full local testing strategy, focused commands, and MySQL-sensitive test guidance, see [docs/testing.md](docs/testing.md).

## Further Documentation

- [docs/local-development.md](docs/local-development.md) for Docker setup details, platform guidance, and troubleshooting
- [docs/testing.md](docs/testing.md) for local validation strategy and command recommendations
- [resources/data/openapi.json](resources/data/openapi.json) for the OpenAPI specification used by the public API

## Contributing

1. Create a branch from `main`.
2. Make your changes using the Docker development workflow described above.
3. Run the relevant quality checks before opening a pull request.
4. Open a pull request with a clear description of the change.

## License

This project is licensed under the GNU General Public License v3.0 or later (GPL-3.0-or-later).