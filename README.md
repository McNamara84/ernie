# ERNIE - Earth Research Notary for Information Editing

![Version](https://img.shields.io/badge/version-1.0.0-blue)
![Laravel](https://img.shields.io/badge/Laravel-13-FF2D20?logo=laravel&logoColor=white)
![PHP](https://img.shields.io/badge/PHP-8-777BB4?logo=php&logoColor=white)
![React](https://img.shields.io/badge/React-19-61DAFB?logo=react&logoColor=white)
![shadcn/ui](https://img.shields.io/badge/shadcn%2Fui-4-000000?logo=shadcnui&logoColor=white)
![TailwindCSS](https://img.shields.io/badge/TailwindCSS-4-06B6D4?logo=tailwindcss&logoColor=white)
![Pest](https://img.shields.io/badge/Pest-4-F24C6A?logo=pestphp&logoColor=white)
![PHPStan](https://img.shields.io/badge/PHPStan-8-4B8BBE?logo=php&logoColor=white)
[![codecov](https://codecov.io/gh/McNamara84/ernie/graph/badge.svg)](https://codecov.io/gh/McNamara84/ernie)

A metadata editor for reviewers of research data at GFZ Helmholtz Centre for Geosciences.

**🎉 Version 1.0.0** – ERNIE 1.0.0 delivers complete core metadata curation functionality, DOI registration workflows, and comprehensive testing. See the [interactive changelog](/changelog) for full release notes.

## Features

- **DataCite v4.7 Metadata Editor** – Complete support for DOI registration with all mandatory and recommended fields
- **Citation Manager** – Curate inline bibliographic metadata (DataCite 4.7 `relatedItem`) for related works, with Crossref DOI auto-fill and APA/IEEE preview
- **Role-Based Access Control** – Four-tier permission system (Admin, Group Leader, Curator, Beginner)
- **IGSN Support** – Physical sample registration with CSV import and hierarchical relationships
- **ORCID & ROR Integration** – Researcher identification and institutional affiliation lookup
- **Controlled Vocabularies** – NASA GCMD (Science Keywords, Platforms, Instruments) and MSL keywords
- **Interactive Google Maps** – Spatial coverage with point, bounding box, and polygon support
- **XML Import** – Automatic metadata extraction from DataCite XML / ELMO exports
- **Legacy Dataset Browser** – Import metadata from previous database systems
- **Landing Page Management** – Create, preview, and publish dataset landing pages
- **REST API** – OpenAPI 3.1 specification with interactive Swagger UI at `/api/v1/doc`
- **Accessible Design** – WCAG 2.1 AA compliant with Radix UI and Axe-core testing

📖 **[View detailed user documentation at /docs](/docs)**

## Installation

### Prerequisites

- **PHP 8.2+** with required extensions:
  - Core: `ctype`, `curl`, `dom`, `fileinfo`, `filter`, `hash`, `iconv`, `json`, `libxml`, `mbstring`, `openssl`, `tokenizer`, `xml`, `xmlwriter`
  - Database: `pdo`, `pdo_mysql`
  - Additional: `intl`, `simplexml`, `sodium`, `xsl`
- **Node.js 24** and npm
- **MySQL 9.7.x** or **MariaDB 10.6+**
- **Composer 2.x**

### Basic Setup

1. Clone the repository and switch to the project directory:
   ```bash
   git clone https://github.com/McNamara84/ernie.git
   cd ernie
   ```

2. Install dependencies:
   ```bash
   composer install
   npm install
   ```

3. Configure environment:
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

4. Update `.env` with your database credentials and other settings

5. Run database migrations:
   ```bash
   php artisan migrate
   ```

6. Create your first admin user:
   ```bash
   php artisan add-user "Admin Name" admin@example.com SecurePassword123
   ```
   *Note: The first user created automatically becomes an admin.*

7. Build frontend assets:
   ```bash
   npm run build
   ```

8. Start the development server:
   ```bash
   composer run dev
   ```
   This command starts three concurrent processes:
   - Laravel development server (`php artisan serve`)
   - Queue worker (`php artisan queue:listen`)
   - Vite dev server (`npm run dev`)

   Alternatively, for SSR (Server-Side Rendering) support:
   ```bash
   composer run dev:ssr
   ```

### Optional Configuration

#### Google Maps API Key (for Spatial Coverage)
Add to `.env`:
```env
GM_API_KEY=your_api_key_here
```

#### ORCID API Configuration
ERNIE uses the ORCID Public API for read-only access to researcher profiles. No authentication is required:
```env
ORCID_API_URL=https://pub.orcid.org/v3.0
ORCID_SEARCH_URL=https://pub.orcid.org/v3.0/search
```

#### Old Database Connection (for Legacy Data Import)
Add to `config/database.php` and `.env`:
```env
DB_OLD_CONNECTION=mysql
DB_OLD_HOST=127.0.0.1
DB_OLD_PORT=3306
DB_OLD_DATABASE=old_database
DB_OLD_USERNAME=root
DB_OLD_PASSWORD=
```

#### ERNIE API Key (for External Service Integration)
Add to `.env`:
```env
ERNIE_API_KEY=your_api_key_here
```

#### Landing Page Contact Form Cc
When visitors send messages via the landing page contact form, the data publication team receives a Cc copy. Configure the recipient email:
```env
LANDING_PAGE_CONTACT_CC_EMAIL=datapub@gfz.de
```
Set to empty string to disable Cc notifications.

### User Management

ERNIE uses a **closed application model** – there is no public registration. Users must be created via command line by administrators.

#### Create a New User
```bash
php artisan add-user {name} {email} {password}
```

**Examples:**
```bash
# Create the first user (automatically becomes admin)
php artisan add-user "Jane Doe" jane@example.com SecurePass123

# Create additional users (default role: beginner)
php artisan add-user "John Smith" john@example.com AnotherPass456
```

**Important Notes:**
- The **first user** created in the system automatically receives the `admin` role
- All subsequent users receive the `beginner` role by default
- Admins and Group Leaders can change user roles via the `/users` interface
- User ID 1 is system-protected and cannot be modified or deactivated

📖 **[View detailed user management documentation at /docs](/docs)**

### Data Synchronization

```bash
php artisan spdx:sync-licenses       # Sync SPDX licenses
php artisan get-gcmd-science-keywords # Fetch GCMD Science Keywords
php artisan get-gcmd-platforms        # Fetch GCMD Platforms
php artisan get-gcmd-instruments      # Fetch GCMD Instruments
php artisan get-msl-keywords          # Fetch MSL Keywords
php artisan get-ror-ids               # Sync ROR Affiliations
```

## Docker Development Environment

ERNIE uses a two-speed local workflow:

- **Fast Mode** is the default daily workflow. It starts the core application stack only and keeps optional services out of the startup path.
- **Profile-based add-ons** let you enable assessment-specific or tooling-specific services only when you actually need them.

Detailed guides:

- [docs/local-development.md](docs/local-development.md) – local setup, Windows and WSL2 guidance, Docker profiles, and troubleshooting
- [docs/testing.md](docs/testing.md) – Pest, PHPStan, Vitest, Playwright, and local validation strategy

Canonical validation commands:

- `npm run check:backend` – start the required backend containers, run Pest, then run PHPStan
- `npm run check:frontend` – run ESLint, TypeScript, and one-shot Vitest
- `npm run check:parity` – boot the parity profile, run the MySQL-sensitive Pest slice, then run Playwright against the local dev stack

### Prerequisites

- **Docker Desktop** installed and running
- **OpenSSL** (included with Git for Windows or install separately)
- **WSL2 is recommended on Windows**. For best filesystem performance, keep your active checkout inside the WSL filesystem and use VS Code Remote - WSL.

### Quick Start (Fast Mode)

1. **Generate SSL certificates** (first time only):
   ```powershell
   # Windows PowerShell
   .\docker\generate-certs.ps1
   
   # Or using Git Bash / WSL
   ./docker/generate-certs.sh
   ```

2. **Create environment file**:
   ```powershell
   Copy-Item .env.docker.example .env.docker
   ```
   Edit `.env.docker` as needed (defaults work out of the box).

3. **Start the default development stack**:
   ```powershell
   npm run docker:dev:up
   ```

   Equivalent direct command:
   ```powershell
   docker compose --env-file .env.docker -f docker-compose.dev.yml up --build
   ```

4. **Access the application**:
   - **Application (recommended / stage-like)**: https://ernie.localhost:3333/
   - **Application (localhost fallback after host/session switch)**: https://localhost:3333/
   - **Traefik Dashboard**: http://localhost:8080

   If `ernie.localhost` does not resolve on your system, add it to your hosts file (Windows: `C:\Windows\System32\drivers\etc\hosts`) as `127.0.0.1 ernie.localhost`.

   Note: The dev stack uses `ERNIE_DEV_HOST=ernie.localhost` and `SESSION_DOMAIN=ernie.localhost` by default. If you experience `419 Page Expired` errors on login, a plain switch to `https://localhost:3333/` will not help until the cookie domain matches. For a localhost fallback, set `ERNIE_DEV_HOST=localhost` and `ERNIE_DEV_SESSION_DOMAIN=localhost` in `.env.docker`, keep `localhost:3333` in `ERNIE_DEV_STATEFUL_DOMAINS`, then restart the stack.

5. **Run initial setup**:
   ```powershell
   docker compose --env-file .env.docker -f docker-compose.dev.yml exec app php artisan add-user "Admin Name" admin@example.com SecurePassword
   docker compose --env-file .env.docker -f docker-compose.dev.yml exec app php artisan spdx:sync-licenses
   ```

   The development entrypoint already installs missing dependencies, runs migrations, and seeds baseline data when the database is empty.

### Development Stack

The default development stack includes:

| Service | Container | Purpose | Port |
|---------|-----------|---------|------|
| Traefik | `ernie-traefik` | Reverse proxy with SSL termination | 3333 (HTTPS), 8080 (Dashboard) |
| PHP-FPM | `ernie-app-dev` | Laravel application | 9000 (internal) |
| Nginx | `ernie-webserver-dev` | Web server | 80 (internal) |
| Vite | `ernie-vite-dev` | HMR dev server | 5173 (internal) |
| MySQL | `ernie-db-dev` | Database | 3306 |
| Redis | `ernie-redis-dev` | Cache & Sessions | 6379 |
| Queue | `ernie-queue-dev` | Background jobs | - |

Optional profiles:

- `assessment` starts the F-UJI container for assessment and FAIRness-specific workflows; set `FUJI_ENABLED=true` in `.env.docker` when you want the app to use it.
- `tools` adds CloudBeaver for database inspection.
- `parity` starts both optional profiles together for broader local verification; set `FUJI_ENABLED=true` in `.env.docker` if the app should use F-UJI during that run.

### URL Routing

The development environment uses subdomain-based routing:

- Recommended (stage/prod-like):
   - `https://ernie.localhost:3333/` → Main application

- Fallback:
   - `https://localhost:3333/` → Main application
   - `https://localhost:3333/api/v1/` → API endpoints
   - `https://localhost:3333/@vite/` → Vite HMR (proxied)

This mirrors the production URL `https://ernie.rz-vm182.gfz.de/`.

### Trust SSL Certificate (Windows)

To avoid browser security warnings:

1. Open `docker\traefik\certs\localhost.crt`
2. Click "Install Certificate"
3. Select "Local Machine"
4. Choose "Place all certificates in the following store"
5. Browse → "Trusted Root Certification Authorities"
6. Click Finish

Or run as Administrator:
```powershell
Import-Certificate -FilePath ".\docker\traefik\certs\localhost.crt" -CertStoreLocation Cert:\LocalMachine\Root
```

### Optional Profiles

Start Fast Mode with additional services only when you need them:

```powershell
# Start the F-UJI container locally (also set FUJI_ENABLED=true in .env.docker)
npm run docker:dev:assessment

# Enable CloudBeaver locally
npm run docker:dev:tools

# Start both optional profiles for broader local verification
# (also set FUJI_ENABLED=true in .env.docker if the app should use F-UJI)
npm run docker:dev:parity
```

### Development Commands

Use the npm wrappers when possible so Docker Compose always reads `.env.docker` consistently.

```powershell
# Start environment in foreground
npm run docker:dev:up

# Start environment in background
npm run docker:dev:up:d

# Stop environment
npm run docker:dev:down

# Reset volumes
npm run docker:dev:reset

# Canonical validation entry points
npm run check:backend
npm run check:frontend
npm run check:parity
```

For direct Compose usage:

```powershell
# View logs (all services)
docker compose --env-file .env.docker -f docker-compose.dev.yml logs -f

# View logs (specific service)
docker compose --env-file .env.docker -f docker-compose.dev.yml logs -f app

# Run artisan commands
docker compose --env-file .env.docker -f docker-compose.dev.yml exec app php artisan <command>

# Run composer commands
docker compose --env-file .env.docker -f docker-compose.dev.yml exec app composer <command>

# Run npm commands inside the Vite container when needed
docker compose --env-file .env.docker -f docker-compose.dev.yml exec vite npm <command>
```

#### Playwright E2E (local dev stack)

```powershell
npm run test:e2e:devstack
```

This uses [playwright.devstack.config.ts](playwright.devstack.config.ts) and does not affect CI.

### Xdebug Integration

Xdebug is pre-installed but disabled by default. To enable:

1. Edit `.env.docker`:
   ```env
   XDEBUG_MODE=debug
   ```

2. Restart the app container:
   ```powershell
   docker compose --env-file .env.docker -f docker-compose.dev.yml restart app
   ```

3. Configure VS Code with PHP Debug extension (default port: 9003)

### Troubleshooting

**Certificate errors in browser:**
Ensure you've trusted the self-signed certificate (see above).

**"Connection refused" errors:**
Wait for all containers to be healthy. Check status with:
```powershell
docker compose --env-file .env.docker -f docker-compose.dev.yml ps
```

**Database migration fails:**
The database needs time to initialize. The app entrypoint retries automatically, but you can rerun migrations manually:
```powershell
docker compose --env-file .env.docker -f docker-compose.dev.yml exec app php artisan migrate
```

**Hot reload not working:**
Ensure the Vite container is running and check its logs:
```powershell
docker compose --env-file .env.docker -f docker-compose.dev.yml logs vite
```

If `public/hot` is missing on the Windows host, recreate it from the Vite container:

```powershell
docker compose --env-file .env.docker -f docker-compose.dev.yml exec vite sh -c 'echo "https://ernie.localhost:3333" > /var/www/html/public/hot'
```

**F-UJI or CloudBeaver is unavailable:**
Those services are now opt-in. Start the matching profile with `npm run docker:dev:assessment`, `npm run docker:dev:tools`, or `npm run docker:dev:parity`. For F-UJI specifically, also set `FUJI_ENABLED=true` in `.env.docker` before restarting the stack.

---

## Docker Production Deployment

The project includes Docker configuration for production deployment with multi-stage builds and optimized images.

### Quick Start (Production)

1. Build the Docker image:
   ```bash
   docker-compose -f docker-compose.prod.yml build
   ```

2. Start the containers:
   ```bash
   docker-compose -f docker-compose.prod.yml up -d
   ```

3. Run initial setup inside the container:
   ```bash
   docker-compose -f docker-compose.prod.yml exec app php artisan migrate
   docker-compose -f docker-compose.prod.yml exec app php artisan add-user "Admin Name" admin@example.com SecurePassword
   docker-compose -f docker-compose.prod.yml exec app php artisan spdx:sync-licenses
   ```

### Production Docker Stack

The production Docker setup includes:
- **PHP-FPM 8.4+** with all required extensions
- **Nginx** web server with optimized configuration
- **MySQL 9.7** database server
- **Redis** for caching
- **Traefik** integration via labels (external Traefik required)
- **Persistent volumes** for storage and database
- **Health checks** for all services

### Production Container Management

View logs:
```bash
docker-compose -f docker-compose.prod.yml logs -f
```

Stop containers:
```bash
docker-compose -f docker-compose.prod.yml down
```

Remove volumes (⚠️ deletes data):
```bash
docker-compose -f docker-compose.prod.yml down -v
```

## Testing

See [docs/testing.md](docs/testing.md) for the full local testing strategy.

```bash
# PHP checks in the app container
npm run test:php
npm run phpstan:check

# Frontend checks on the host
npm run lint:check
npm run types
npm run test:run

# MySQL-sensitive backend slice
npm run test:php:mysql-sensitive

# Canonical validation entry points
npm run check:backend
npm run check:frontend

# Coverage or browser validation when needed
npm run test:coverage
npm run test:e2e:devstack
npm run check:parity
```

Notes:

- The default PHP test path is fast because `tests/pest/CreatesApplication.php` defaults to SQLite in memory unless `ERNIE_TEST_DB_CONNECTION` opts into the dedicated MySQL slice.
- `npm run test:php:mysql-sensitive` switches Pest to an isolated MySQL database named `ernie_test`, recreates that schema before each schema-mutating file, and runs the current explicit MySQL-sensitive migration file slice.
- Keep MySQL-backed verification focused on database-sensitive changes instead of moving the full suite to MySQL.
- Use `npm run lint` only when you want ESLint to apply automatic fixes.

## API Documentation

ERNIE provides a **read-only REST API** (OpenAPI 3.1.0) for integration with external systems like ELMO.

📖 **[View Interactive API Documentation](https://ernie.rz-vm182.gfz.de/api/v1/doc)**

The API includes:
- Metadata types (resource types, title types, licenses, languages, roles)
- NASA GCMD controlled vocabularies
- ROR affiliations and ORCID search endpoints
- API key authentication via `X-API-Key` header

## Contributing

1. Fork the repository and create a feature branch from `main`
2. Make changes following code style standards (PSR-12, ESLint + Prettier)
3. Run quality checks before committing:
   ```bash
   ./vendor/bin/pint                    # PHP code style
   npm run check:backend
   npm run check:frontend
   ```
4. Commit using [Conventional Commits](https://www.conventionalcommits.org/) (e.g., `feat: add feature`)
5. Open a Pull Request

All PRs are automatically checked for code style, static analysis, test coverage, and accessibility.

## License

This project is licensed under the GNU General Public License v3.0 or later (GPL-3.0-or-later).

## Version History

For detailed information about changes in each release, visit the [interactive changelog](/changelog) at `/changelog`.

## Acknowledgments

Developed at **GFZ German Research Centre for Geosciences** – Helmholtz Centre Potsdam.

This project integrates with:
- [DataCite](https://datacite.org/) metadata schema v4.7
- [NASA GCMD](https://earthdata.nasa.gov/earth-observation-data/find-data/gcmd) controlled vocabularies
- [Research Organization Registry (ROR)](https://ror.org/) for institutional identifiers
- [ORCID](https://orcid.org/) Public API for researcher identification
- [SPDX License List](https://spdx.org/licenses/) for standardized license identifiers
- [MSL](https://msl-vocabularies.tib.eu/) Materials Science and Engineering vocabulary

