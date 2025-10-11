# ERNIE - Earth Research Notary for Information & Editing

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

- **REST API** – read-only API with OpenAPI specification at `/api/v1/doc`
- **Changelog** – interactive version history accessible at `/changelog`
- **User Documentation** – comprehensive guides available at `/docs`

## Installation

### Prerequisites

- PHP 8.4+ with required extensions:
  `ctype`, `curl`, `dom`, `fileinfo`, `filter`, `hash`, `iconv`, `intl`, `json`,
  `libxml`, `mbstring`, `openssl`, `pdo`, `pdo_mysql`, `simplexml`,
  `sodium`, `tokenizer`, `xml`, `xmlwriter`, `xsl`
- Node.js 18+ and npm
- MySQL/MariaDB database
- Composer

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

6. Start the development server:
   ```bash
   composer run dev
   ```

### Optional Configuration

#### Google Maps API Key (for Spatial Coverage)
Add to `.env`:
```env
GOOGLE_MAPS_API_KEY=your_api_key_here
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
php artisan spdx:sync
```

#### Fetch GCMD Vocabularies
```bash
php artisan gcmd:fetch-science-keywords
php artisan gcmd:fetch-platforms
php artisan gcmd:fetch-instruments
```

#### Sync ROR Affiliations
```bash
php artisan ror:sync
```

### Create Admin User
```bash
php artisan user:create
```

## Testing

### Pest Unit Tests and Laravel Feature Tests

Run all PHP tests:

```bash
php artisan test
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

Run a specific test file or directory:

```bash
npx vitest run resources/js
```

### Playwright E2E Tests

Ensure the application server is running and the Playwright browsers are installed:

```bash
npx playwright install
npx playwright test
```

## API Endpoints

### Public Endpoints

- `GET /api/changelog` – changelog information (no authentication required)

### ELMO Integration Endpoints

The service exposes a read-only REST API for metadata types. These endpoints require an API key supplied via the `X-API-Key` header or the `api_key` query parameter.

#### Metadata Types
- `GET /api/v1/resource-types/elmo` – list resource types active for ELMO
- `GET /api/v1/title-types/elmo` – list title types active for ELMO
- `GET /api/v1/licenses/elmo` – list licenses active for ELMO
- `GET /api/v1/languages/elmo` – list languages active for ELMO

#### Roles
- `GET /api/v1/roles/authors/elmo` – list author roles active for ELMO
- `GET /api/v1/roles/contributor-persons/elmo` – list contributor person roles active for ELMO
- `GET /api/v1/roles/contributor-institutions/elmo` – list contributor institution roles active for ELMO

#### Documentation
- `GET /api/v1/doc` – OpenAPI specification for the ELMO endpoints

### Internal Endpoints (Authenticated)

#### Old Datasets
- `GET /old-datasets` – list old datasets with pagination
- `GET /old-datasets/{id}/authors` – fetch authors from old dataset
- `GET /old-datasets/{id}/contributors` – fetch contributors from old dataset
- `GET /old-datasets/{id}/descriptions` – fetch descriptions from old dataset
- `GET /old-datasets/{id}/dates` – fetch dates from old dataset
- `GET /old-datasets/{id}/controlled-keywords` – fetch GCMD keywords from old dataset
- `GET /old-datasets/{id}/free-keywords` – fetch free keywords from old dataset
- `GET /old-datasets/{id}/coverages` – fetch spatial/temporal coverage from old dataset

#### Vocabularies
- `GET /vocabularies/gcmd-science-keywords` – NASA GCMD Science Keywords hierarchy
- `GET /vocabularies/gcmd-platforms` – NASA GCMD Platforms hierarchy
- `GET /vocabularies/gcmd-instruments` – NASA GCMD Instruments hierarchy

#### ROR Affiliations
- `GET /api/v1/ror-affiliations` – search Research Organization Registry affiliations

## Sitemap

```text
/
├─ about                           # About page
├─ changelog                       # Version history
├─ legal-notice                    # Legal information
├─ confirm-password                # Password confirmation
├─ dashboard                       # Main dashboard (authenticated)
├─ docs                            # Documentation overview
│  └─ users                        # User documentation
├─ email
│  └─ verification-notification    # Email verification
├─ forgot-password                 # Password reset request
├─ login                           # User login
├─ logout                          # User logout
├─ reset-password
│  └─ {token}                      # Password reset with token
├─ settings                        # User settings (authenticated)
│  ├─ appearance                   # Appearance preferences
│  ├─ password                     # Change password
│  └─ profile                      # Edit profile
├─ resources                       # Browse curated resources (authenticated)
├─ old-datasets                    # Browse legacy datasets (authenticated)
├─ curation                        # Metadata curation form (authenticated)
├─ storage
│  └─ {path}                       # File storage
├─ up                              # Health check endpoint
└─ verify-email
   └─ {id}/{hash}                  # Email verification link
```

