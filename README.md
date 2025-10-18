# ERNIE - Earth Research Notary for Information Editing

![Laravel](https://img.shields.io/badge/Laravel-12-FF2D20?logo=laravel&logoColor=white)
![PHP](https://img.shields.io/badge/PHP-8-777BB4?logo=php&logoColor=white)
![React](https://img.shields.io/badge/React-19-61DAFB?logo=react&logoColor=white)
![TailwindCSS](https://img.shields.io/badge/TailwindCSS-4-06B6D4?logo=tailwindcss&logoColor=white)
![Pest](https://img.shields.io/badge/Pest-3-F24C6A?logo=pestphp&logoColor=white)
![Pest Coverage](https://github.com/McNamara84/ernie/blob/image-data/coverage.svg?raw=true)
![PHPStan](https://img.shields.io/badge/PHPStan-8-4B8BBE?logo=php&logoColor=white)
![Vitest Coverage](https://github.com/McNamara84/ernie/blob/image-data/vitest-coverage.svg?raw=true)

A metadata editor for reviewers of research data at GFZ Helmholtz Centre for Geosciences.

## Features

### Metadata Management

- **Resource Information** – configurable resource types, title types, and dataset languages
- **Authors & Contributors** – comprehensive management with ORCID support, role assignments, and ROR-affiliated institutions
- **Descriptions** – multiple description types per resource (Abstract, Methods, Technical Info, etc.)
- **Date Ranges** – temporal metadata with various DataCite date types (Created, Issued, Collected, etc.)
- **Keywords** – dual system supporting NASA GCMD controlled vocabularies (Science Keywords, Platforms, Instruments) and free-form keywords
- **Spatial & Temporal Coverage** – interactive Google Maps integration for geographic coordinates, date/time ranges with timezone support
- **Licenses & Rights** – configurable licenses with automatic SPDX synchronization

### Data Import & Integration

- **XML Upload** – automatic extraction of metadata from DataCite XML files
- **Legacy Dataset Browser** – browse and import metadata from old datasets with intelligent field mapping
- **ROR Integration** – automatic synchronization with Research Organization Registry for institutional affiliations
- **GCMD Vocabularies** – integration with NASA's Global Change Master Directory keyword system

### User Interface

- **Resources Workspace** – browse, edit, and manage curated resources with metadata badges and quick actions
- **Interactive Curation Form** – comprehensive metadata editing with validation and auto-completion
- **Dashboard** – statistics overview and quick access to key functions
- **Accessible Design** – built with Radix UI and Tailwind CSS following WCAG guidelines

### API & Documentation

- **REST API** – read-only REST API with OpenAPI 3.1.0 specification at `/api/v1/doc`
  - Interactive Swagger UI for exploring endpoints
  - Metadata types for ELMO integration (resource types, title types, licenses, languages, roles)
  - NASA GCMD controlled vocabularies (Science Keywords, Platforms, Instruments)
  - API key authentication via `X-API-Key` header or `api_key` query parameter
- **Changelog** – interactive version history accessible at `/changelog`
- **User Documentation** – comprehensive guides available at `/docs`

## Installation

### Prerequisites

- PHP 8.2+ with required extensions:
  `ctype`, `curl`, `dom`, `fileinfo`, `filter`, `hash`, `iconv`, `intl`, `json`,
  `libxml`, `mbstring`, `openssl`, `pdo`, `pdo_mysql`, `simplexml`,
  `sodium`, `tokenizer`, `xml`, `xmlwriter`, `xsl`
- Node.js 18+ and npm
- MySQL/MariaDB database
- Composer 2.x

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

6. Build frontend assets:
   ```bash
   npm run build
   ```

7. Start the development server:
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

#### ELMO API Key (for External Service Integration)
Add to `.env`:
```env
ELMO_API_KEY=your_api_key_here
```

### Data Synchronization

#### Sync SPDX Licenses
```bash
php artisan spdx:sync-licenses
```

#### Fetch GCMD Vocabularies
```bash
php artisan get-gcmd-science-keywords
php artisan get-gcmd-platforms
php artisan get-gcmd-instruments
```

#### Sync ROR Affiliations
```bash
php artisan get-ror-ids
```

## Docker Deployment

The project includes Docker configuration for production deployment:

1. Build the Docker image:
   ```bash
   docker-compose -f docker-compose.prod.yml build
   ```

2. Start the containers:
   ```bash
   docker-compose -f docker-compose.prod.yml up -d
   ```

3. Run migrations inside the container:
   ```bash
   docker-compose -f docker-compose.prod.yml exec app php artisan migrate
   ```

The Docker setup includes:
- PHP-FPM with all required extensions
- Nginx web server
- MariaDB database
- Persistent volumes for storage and database

## Development Tools

### Code Quality

#### PHP Linting & Formatting
```bash
./vendor/bin/pint          # Fix code style issues
```

#### JavaScript/TypeScript Linting & Formatting
```bash
npm run lint               # Run ESLint with auto-fix
npm run format            # Format code with Prettier
npm run format:check      # Check formatting without changes
npm run types             # Type check with TypeScript
```

#### Static Analysis
```bash
./vendor/bin/phpstan analyse  # Run PHPStan for static analysis
```

## Testing

### Pest Unit Tests and Laravel Feature Tests

Run all PHP tests:

```bash
php artisan test
# or
composer run test
```

Run with coverage:

```bash
php artisan test --coverage
```

Run only unit tests:

```bash
php artisan test --testsuite=Unit
```

Run only feature tests:

```bash
php artisan test --testsuite=Feature
```

### Vitest Unit Tests and Integration Tests

Run the JavaScript test suites:

```bash
npm test
```

Run with coverage:

```bash
npm test -- --coverage
```

Run in watch mode:

```bash
npm test -- --watch
```

Run a specific test file:

```bash
npx vitest run resources/js/path/to/test.test.ts
```

### Playwright E2E Tests

Install Playwright browsers (first time only):

```bash
npx playwright install
```

Run all E2E tests:

```bash
npx playwright test
```

Run tests in UI mode (interactive):

```bash
npx playwright test --ui
```

Run specific test file:

```bash
npx playwright test tests/playwright/login.spec.ts
```

View test report:

```bash
npx playwright show-report
```

**Note:** Ensure the application server is running before executing Playwright tests.

## Technology Stack

### Backend
- **Framework:** Laravel 12.x
- **Language:** PHP 8.2+
- **Database:** MySQL/MariaDB
- **Queue System:** Database-backed queues
- **Testing:** Pest 3.x with Laravel plugin

### Frontend
- **Framework:** React 19.x
- **SSR:** Inertia.js 2.x
- **Build Tool:** Vite 7.x
- **Styling:** Tailwind CSS 4.x
- **UI Components:** Radix UI primitives
- **Maps:** Google Maps via @vis.gl/react-google-maps
- **Testing:** Vitest 3.x, Playwright 1.x

### Development Tools
- **Code Quality:** Laravel Pint, ESLint, Prettier
- **Static Analysis:** PHPStan (Larastan), TypeScript
- **Package Management:** Composer, npm
- **Process Management:** Concurrently

## API Documentation

ERNIE provides a **read-only REST API** (OpenAPI 3.1.0) for integration with external systems like ELMO. The API offers access to metadata types, roles, and NASA GCMD controlled vocabularies.

**📖 [View Interactive API Documentation](https://env.rz-vm182.gfz.de/ernie/api/v1/doc)**

The API documentation includes:
- All available endpoints with request/response examples
- Authentication requirements (API key via `X-API-Key` header)
- Complete schema definitions
- Interactive Swagger UI for testing endpoints

For API access, contact the ERNIE team to obtain an API key.

## Project Structure

```
ernie/
├── app/                        # Application core
│   ├── Console/Commands/       # Artisan commands (GCMD, ROR, SPDX sync)
│   ├── Http/                   # Controllers, Middleware, Requests
│   ├── Models/                 # Eloquent models
│   ├── Providers/              # Service providers
│   ├── Services/               # Business logic services
│   └── Support/                # Helper classes
├── config/                     # Configuration files
├── database/                   # Migrations, factories, seeders
├── docker/                     # Docker configuration
├── public/                     # Web root & compiled assets
├── resources/                  # Frontend source code
│   ├── css/                    # Stylesheets
│   ├── js/                     # React components & TypeScript
│   │   ├── Components/         # Reusable UI components
│   │   ├── Layouts/            # Page layouts
│   │   ├── lib/                # Utility functions
│   │   ├── Pages/              # Inertia page components
│   │   └── types/              # TypeScript type definitions
│   └── views/                  # Blade templates
├── routes/                     # Route definitions
├── storage/                    # Application storage
├── tests/                      # Test suites
│   ├── Feature/                # Laravel feature tests
│   ├── Unit/                   # Laravel unit tests
│   ├── pest/                   # Pest-based tests
│   ├── playwright/             # E2E tests
│   └── vitest/                 # Frontend unit tests
└── vendor/                     # Composer dependencies
```

## Application Routes

### Public Routes
- `/` – Homepage
- `/about` – About page
- `/changelog` – Version history
- `/legal-notice` – Legal information
- `/login` – User login
- `/forgot-password` – Password reset request
- `/reset-password/{token}` – Password reset with token
- `/up` – Health check endpoint

### Authenticated Routes
- `/dashboard` – Main dashboard with statistics
- `/resources` – Browse and manage curated resources
- `/old-datasets` – Browse legacy datasets for import
- `/curation` – Metadata curation form
- `/docs` – Documentation overview
- `/docs/users` – User documentation
- `/settings` – User settings
  - `/settings/profile` – Edit profile
  - `/settings/password` – Change password
  - `/settings/appearance` – Appearance preferences

### API Routes
- `/api/changelog` – Changelog data (public, no authentication required)
- `/api/v1/doc` – [Interactive API documentation](https://env.rz-vm182.gfz.de/ernie/api/v1/doc) (ELMO integration endpoints)

## Contributing

### Workflow

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Make your changes following the code style standards
4. Run quality checks (see below)
5. Commit your changes using [Conventional Commits](https://www.conventionalcommits.org/)
6. Push to your branch (`git push origin feature/amazing-feature`)
7. Open a Pull Request

### Code Style Standards

- **PHP:** PSR-12 via Laravel Pint
- **JavaScript/TypeScript:** ESLint + Prettier with organized imports
- **Commits:** [Conventional Commits](https://www.conventionalcommits.org/) specification

### Quality Checks

Before committing, ensure all quality checks pass:

```bash
# PHP Code Style & Analysis
./vendor/bin/pint                    # Fix code style
./vendor/bin/phpstan analyse         # Static analysis

# JavaScript/TypeScript
npm run lint                         # Lint & fix
npm run format                       # Format code
npm run types                        # Type check

# Tests
composer run test                    # PHP tests
npm test                             # JavaScript tests
npx playwright test                  # E2E tests (server must be running)
```

## License

This project is licensed under the GNU General Public License v3.0 or later (GPL-3.0-or-later).

## Acknowledgments

Developed at **GFZ German Research Centre for Geosciences** – Helmholtz Centre Potsdam.

This project integrates with:
- [DataCite](https://datacite.org/) metadata schema
- [NASA GCMD](https://earthdata.nasa.gov/earth-observation-data/find-data/gcmd) vocabularies
- [Research Organization Registry (ROR)](https://ror.org/)
- [SPDX License List](https://spdx.org/licenses/)

