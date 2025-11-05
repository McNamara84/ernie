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

### User Management

- **Role-Based Access Control** – hierarchical permission system with four user roles:
  - **Admin** – full system access including user management, DOI registration (test/production), and all curation features
  - **Group Leader** – user management capabilities (can manage Curators and Beginners), DOI registration (test/production), full curation access
  - **Curator** – standard curation access with test DOI registration only
  - **Beginner** – limited curation access, restricted to test DOI registration for learning
- **User Administration** – admins and group leaders can manage users via `/users` interface with role changes, account deactivation/reactivation, and password reset capabilities
- **Command-Line User Creation** – secure user provisioning via `php artisan add-user {name} {email} {password}` (first user automatically becomes admin)
- **Account Security** – deactivated users cannot login, User ID 1 is system-protected from modifications, users cannot modify their own accounts

### Metadata Management

- **Resource Information** – configurable resource types, title types, and dataset languages
- **Authors & Contributors** – comprehensive management with ORCID support, role assignments, and ROR-affiliated institutions
- **Descriptions** – multiple description types per resource (Abstract, Methods, Technical Info, etc.)
- **Date Ranges** – temporal metadata with various DataCite date types (Created, Issued, Collected, etc.)
- **Funding References** – project funding information with funder identification and grant numbers
### Keywords & Controlled Vocabularies

- **Keywords** – comprehensive keyword system supporting:
  - NASA GCMD controlled vocabularies (Science Keywords, Platforms, Instruments)
  - MSL (Materials Science and Engineering) controlled vocabulary
  - Free-form keywords for flexible tagging
- **Spatial & Temporal Coverage** – interactive Google Maps integration for geographic coordinates with polygon/point support, date/time ranges with timezone support
- **Related Identifiers** – link to related publications, datasets, and resources with DataCite relation types
- **Licenses & Rights** – configurable licenses with automatic SPDX synchronization

### Data Import & Integration

- **XML Upload** – automatic extraction of metadata from DataCite XML files
- **Legacy Dataset Browser** – browse and import metadata from old datasets with intelligent field mapping
- **ROR Integration** – automatic synchronization with Research Organization Registry for institutional affiliations
- **ORCID Integration** – search and validate researcher identities via ORCID Public API
- **GCMD Vocabularies** – integration with NASA's Global Change Master Directory keyword system
- **MSL Vocabulary** – integration with Materials Science and Engineering controlled vocabulary

### User Interface

- **Resources Workspace** – browse, search, and manage curated resources with metadata completeness badges, status indicators, and quick actions
- **Interactive Curation Form** – comprehensive metadata editing with real-time validation, auto-completion, and drag-and-drop reordering
- **Dashboard** – statistics overview with resource metrics, recent activities, and quick access to key functions
- **Accessible Design** – built with Radix UI and Tailwind CSS following WCAG 2.1 AA guidelines, tested with Axe-core

### API & Documentation

- **REST API** – read-only REST API with OpenAPI 3.1.0 specification at `/api/v1/doc`
  - Interactive Swagger UI for exploring endpoints
  - Metadata types for ELMO integration (resource types, title types, licenses, languages, roles)
  - NASA GCMD controlled vocabularies (Science Keywords, Platforms, Instruments)
  - ROR affiliations and ORCID search endpoints
  - API key authentication via `X-API-Key` header or `api_key` query parameter
- **Changelog** – interactive version history accessible at `/changelog`
- **User Documentation** – comprehensive guides available at `/docs`

## Installation

### Prerequisites

- **PHP 8.2+** with required extensions:
  - Core: `ctype`, `curl`, `dom`, `fileinfo`, `filter`, `hash`, `iconv`, `json`, `libxml`, `mbstring`, `openssl`, `tokenizer`, `xml`, `xmlwriter`
  - Database: `pdo`, `pdo_mysql`
  - Additional: `intl`, `simplexml`, `sodium`, `xsl`
- **Node.js 18+** and npm
- **MySQL 8.0+** or **MariaDB 10.6+**
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

#### ELMO API Key (for External Service Integration)
Add to `.env`:
```env
ELMO_API_KEY=your_api_key_here
```

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

#### User Roles & Permissions

| Permission | Admin | Group Leader | Curator | Beginner |
|-----------|-------|--------------|---------|----------|
| Manage users (create, edit roles, deactivate) | ✅ | ✅ (Curator/Beginner only) | ❌ | ❌ |
| Promote to Group Leader | ✅ | ❌ | ❌ | ❌ |
| Register production DOI | ✅ | ✅ | ✅ | ❌ |
| Register test DOI | ✅ | ✅ | ✅ | ✅ |
| Full curation access | ✅ | ✅ | ✅ | ⚠️ Limited |
| Access user management page | ✅ | ✅ | ❌ | ❌ |

**Role Hierarchy** (descending privilege order):
1. Admin (highest privileges)
2. Group Leader
3. Curator
4. Beginner (most restricted)

**Restrictions:**
- Group Leaders **cannot** promote users to `group_leader` or `admin` roles
- Beginners are **always** forced to use DataCite test mode (regardless of system setting)
- Deactivated users **cannot** log in to the system
- Users **cannot** modify their own accounts (must ask another admin/group leader)
- User ID 1 **cannot** be modified, deactivated, or have password reset

#### User Administration Interface

Admins and Group Leaders can access the user management interface at `/users` to:
- View all users with their roles and status
- Change user roles (within permission constraints)
- Deactivate/reactivate user accounts
- Send password reset links via email

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

#### Download MSL Vocabulary
```bash
php artisan download-msl-vocabulary
```

#### Sync ROR Affiliations
```bash
php artisan get-ror-ids
```

## Docker Deployment

The project includes Docker configuration for production deployment with multi-stage builds and optimized images.

### Quick Start

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

### Docker Stack

The Docker setup includes:
- **PHP-FPM 8.2+** with all required extensions
- **Nginx** web server with optimized configuration
- **MariaDB 10.6+** database server
- **Persistent volumes** for storage and database
- **Health checks** for all services

### Container Management

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

## Development Tools

### Code Quality

#### PHP Linting & Formatting
```bash
./vendor/bin/pint          # Fix code style issues (PSR-12 standard)
```

#### JavaScript/TypeScript Linting & Formatting
```bash
npm run lint               # Run ESLint with auto-fix
npm run format             # Format code with Prettier
npm run format:check       # Check formatting without changes
npm run types              # Type check with TypeScript (no emit)
```

#### Static Analysis
```bash
./vendor/bin/phpstan analyse  # Run PHPStan for static analysis (Level 8)
```

### Development Server

Start the integrated development environment:
```bash
composer run dev           # Starts Laravel server, queue worker, and Vite
composer run dev:ssr       # Same as above + SSR support with Inertia.js
```

The `dev` command runs three concurrent processes:
- **Laravel server** on http://127.0.0.1:8000
- **Queue worker** for background jobs
- **Vite dev server** with HMR (Hot Module Replacement)

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
npm run test:e2e
```
*Note: This automatically starts the Laravel server, runs tests, and stops the server.*

Run tests in UI mode (interactive):

```bash
npm run test:e2e:ui
```

Run tests in headed mode (visible browser):

```bash
npm run test:e2e:headed
```

Run specific test suites:

```bash
npm run test:e2e:changelog    # Changelog tests
npm run test:e2e:a11y         # Accessibility tests
```

Run specific test file manually:

```bash
npx playwright test tests/playwright/login.spec.ts
```

View test report:

```bash
npx playwright show-report
```

## Technology Stack

### Backend
- **Framework:** Laravel 12.x
- **Language:** PHP 8.2+
- **Database:** MySQL/MariaDB
- **Queue System:** Database-backed queues
- **Testing:** Pest 3.x with Laravel plugin
- **HTTP Client:** Saloon PHP for external API integrations

### Frontend
- **Framework:** React 19.x
- **SSR:** Inertia.js 2.x
- **Build Tool:** Vite 7.x
- **Styling:** Tailwind CSS 4.x
- **UI Components:** Radix UI primitives
- **Maps:** Google Maps via @vis.gl/react-google-maps
- **Animations:** Framer Motion
- **Charts:** Recharts
- **Testing:** Vitest 3.x, Playwright 1.x, React Testing Library

### Development Tools
- **Code Quality:** Laravel Pint, ESLint 9.x, Prettier 3.x
- **Static Analysis:** PHPStan (Larastan 3.x), TypeScript 5.x
- **Package Management:** Composer 2.x, npm
- **Process Management:** Concurrently
- **E2E Testing:** Playwright with Axe-core for accessibility

## API Documentation

ERNIE provides a **read-only REST API** (OpenAPI 3.1.0) for integration with external systems like ELMO. The API offers access to metadata types, roles, controlled vocabularies, and researcher identification services.

**📖 [View Interactive API Documentation](https://env.rz-vm182.gfz.de/ernie/api/v1/doc)**

The API documentation includes:
- All available endpoints with request/response examples
- Authentication requirements (API key via `X-API-Key` header for protected endpoints)
- Complete schema definitions for all data types
- Interactive Swagger UI for testing endpoints
- Access to NASA GCMD vocabularies, ROR affiliations, and ORCID search

For API key access to protected endpoints, contact the ERNIE development team.

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
- `/health` – Health check endpoint (JSON status response)

### Authenticated Routes
- `/dashboard` – Main dashboard with statistics
- `/resources` – Browse and manage curated resources
- `/old-datasets` – Browse legacy datasets for import
- `/old-statistics` – Statistics overview of old datasets
- `/curation` – Metadata curation form
- `/users` – User management interface (admin/group leader only)
- `/docs` – Documentation overview
- `/docs/users` – User documentation
- `/settings` – User settings
  - `/settings/profile` – Edit profile
  - `/settings/password` – Change password
  - `/settings/appearance` – Appearance preferences

### API Routes
- `/api/changelog` – Changelog data (public, no authentication required)
- `/api/v1/doc` – [Interactive API documentation](https://env.rz-vm182.gfz.de/ernie/api/v1/doc) (OpenAPI/Swagger UI)
- `/api/v1/resource-types` – Resource type definitions
- `/api/v1/title-types` – Title type definitions
- `/api/v1/licenses` – License information
- `/api/v1/languages` – Language codes and labels
- `/api/v1/roles/authors` – Author role definitions
- `/api/v1/roles/contributors` – Contributor role definitions
- `/api/v1/ror-affiliations` – ROR organization affiliations
- `/api/v1/orcid/search` – ORCID researcher search
- `/api/v1/gcmd/*` – NASA GCMD controlled vocabularies

*Note: Most `/elmo` endpoints require API key authentication via the `X-API-Key` header.*

## Contributing

### Workflow

1. **Fork the repository** and clone your fork
2. **Create a feature branch** from `main`:
   ```bash
   git checkout -b feature/amazing-feature
   ```
3. **Make your changes** following the code style standards
4. **Run quality checks** (see below) to ensure code quality
5. **Write or update tests** for your changes
6. **Commit your changes** using [Conventional Commits](https://www.conventionalcommits.org/):
   ```bash
   git commit -m "feat: add amazing feature"
   ```
7. **Push to your branch**:
   ```bash
   git push origin feature/amazing-feature
   ```
8. **Open a Pull Request** with a clear description of your changes

### Code Style Standards

- **PHP:** PSR-12 via Laravel Pint with custom Laravel preset
- **JavaScript/TypeScript:** ESLint 9.x + Prettier 3.x with:
  - Simple Import Sort plugin for organized imports
  - React and React Hooks plugins
  - Prettier plugin for Tailwind CSS class sorting
- **Commits:** [Conventional Commits](https://www.conventionalcommits.org/) specification
  - Types: `feat`, `fix`, `docs`, `style`, `refactor`, `test`, `chore`
  - Example: `feat(curation): add funding reference support`

### Quality Checks

Before committing, ensure all quality checks pass:

```bash
# PHP Code Style & Analysis
./vendor/bin/pint                    # Fix code style (PSR-12)
./vendor/bin/phpstan analyse         # Static analysis (Level 8)

# JavaScript/TypeScript
npm run lint                         # Lint & fix with ESLint
npm run format                       # Format code with Prettier
npm run types                        # Type check with TypeScript

# Tests
composer run test                    # PHP tests (Pest + Laravel)
npm test                             # JavaScript tests (Vitest)
npm run test:e2e                     # E2E tests (Playwright with server)
```

### Continuous Integration

All pull requests are automatically checked for:
- Code style compliance (Pint, ESLint, Prettier)
- Static analysis (PHPStan, TypeScript)
- Test coverage (Pest, Vitest, Playwright)
- Type safety (TypeScript strict mode)
- Accessibility (Axe-core in E2E tests)

## License

This project is licensed under the GNU General Public License v3.0 or later (GPL-3.0-or-later).

## Acknowledgments

Developed at **GFZ German Research Centre for Geosciences** – Helmholtz Centre Potsdam.

This project integrates with:
- [DataCite](https://datacite.org/) metadata schema v4.5
- [NASA GCMD](https://earthdata.nasa.gov/earth-observation-data/find-data/gcmd) controlled vocabularies
- [Research Organization Registry (ROR)](https://ror.org/) for institutional identifiers
- [ORCID](https://orcid.org/) Public API for researcher identification
- [SPDX License List](https://spdx.org/licenses/) for standardized license identifiers
- [MSL](https://msl-vocabularies.tib.eu/) Materials Science and Engineering vocabulary

