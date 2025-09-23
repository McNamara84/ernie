# ERNIE - Earth Research Notary for Information & Editing

![Laravel](https://img.shields.io/badge/Laravel-12-FF2D20?logo=laravel&logoColor=white)
![PHP](https://img.shields.io/badge/PHP-8-777BB4?logo=php&logoColor=white)
![MariaDB](https://img.shields.io/badge/MariaDB-11-003545?logo=mariadb&logoColor=white)
![React](https://img.shields.io/badge/React-19-61DAFB?logo=react&logoColor=white)
![TailwindCSS](https://img.shields.io/badge/TailwindCSS-4-06B6D4?logo=tailwindcss&logoColor=white)
![Pest](https://img.shields.io/badge/Pest-3-F24C6A?logo=pestphp&logoColor=white)
![Pest Coverage](https://github.com/McNamara84/ernie/blob/image-data/coverage.svg?raw=true)
![Vitest Coverage](https://github.com/McNamara84/ernie/blob/image-data/vitest-coverage.svg?raw=true)

A metadata editor for reviewers of research data at GFZ Helmholtz Centre for Geosciences.

## Features

- Configurable resource and title types
- Configurable licenses with automatic SPDX updates
- Configurable dataset languages
- REST API with OpenAPI documentation at `/api/v1/doc`
- Accessible interface built with Radix UI and Tailwind CSS

## Installation

### Prerequisites

- PHP 8.2 or newer with the following extensions enabled: `ctype`, `curl`,
  `dom`, `fileinfo`, `filter`, `hash`, `iconv`, `intl`, `json`, `libxml`,
  `mbstring`, `openssl`, `pdo`, `pdo_mysql`, `simplexml`, `sodium`,
  `tokenizer`, `xml`, `xmlwriter`, `xsl`
- Node.js 20+ and npm 10+
- MariaDB 11 (or compatible MySQL server)
- [pnpm](https://pnpm.io/) (optional) for faster JavaScript dependency installs

### Local setup

1. Clone the repository and switch to the project directory
2. Install PHP dependencies: `composer install`
3. Install Node dependencies: `npm install` (or `pnpm install`)
4. Copy `.env.example` to `.env`
5. Adjust database credentials and mail settings in `.env`
6. Generate the application key: `php artisan key:generate`
7. Run database migrations: `php artisan migrate`
8. Seed the default configuration (optional): `php artisan db:seed`
9. Start the development server: `composer run dev`

### Docker quick start

The project ships with a production-like Docker Compose configuration.

```bash
docker compose -f docker-compose.prod.yml up --build
```

Once the containers are healthy, ERNIE is available at
[`http://localhost:8080`](http://localhost:8080). The first startup performs the
migration automatically; subsequent restarts reuse the persisted database from
`storage/docker`.

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

### Static analysis and formatting

Lint, type-check, and format code before submitting changes:

```bash
composer lint
composer analyse
npm run lint
npm run format
```

These commands are also executed by CI to ensure consistent code quality.

## Development workflow

- Run `npm run dev` to start Vite and Tailwind in watch mode for instant UI
  feedback.
- Use `php artisan migrate:fresh --seed` to reset the database while iterating
  on new features.
- Keep accessibility in mind: verify keyboard navigation, focus states, and
  high-contrast themes when introducing UI changes.
- Prefer component-driven development. UI additions should be implemented as
  Radix/Tailwind components inside `resources/js/components`.

## Accessibility & UX principles

ERNIE is designed for research data curators and reviewers. To maintain a
modern, accessible experience:

- Provide descriptive labels, helper text, and live-region feedback for dynamic
  UI updates.
- Ensure color combinations pass WCAG 2.2 AA contrast checks and support both
  light and dark modes.
- Validate complex forms with inline guidance and retain focus on the first
  invalid field.
- Prefer semantic HTML and ARIA attributes only when necessary.
- Offer keyboard-accessible shortcuts for high-frequency reviewer workflows.

## Contributing

We welcome contributions from the GFZ community. Before opening a pull request:

1. Create an issue describing the change and gather feedback from reviewers.
2. Follow the coding standards enforced by the tooling listed above.
3. Update or add unit tests that cover the behaviour under change.
4. Document new configuration options or workflows in this README.
5. Verify all CI commands succeed locally prior to submission.

## API Endpoints

The service exposes a read-only REST API for metadata types.

- `GET /api/v1/resource-types` – list available resource types
- `GET /api/v1/title-types` – list available title types
- `GET /api/v1/languages` – list available languages
- `GET /api/v1/languages/ernie` – list languages active for ERNIE
- `GET /api/v1/languages/elmo` – list languages active for ELMO
- `GET /api/v1/doc` – OpenAPI specification
- `GET /api/changelog` – changelog information

## Sitemap

```
/
├─ confirm-password
├─ dashboard
├─ docs
├─ email
│  └─ verification-notification
├─ forgot-password
├─ login
├─ logout
├─ reset-password
│  └─ {token}
├─ settings
│  ├─ appearance
│  ├─ password
│  └─ profile
├─ storage
│  └─ {path}
├─ up
└─ verify-email
   └─ {id}/{hash}
```

