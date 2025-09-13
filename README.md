# ERNIE - Earth Research Notary for Information & Editing

![Laravel](https://img.shields.io/badge/Laravel-12-FF2D20?logo=laravel&logoColor=white)
![PHP](https://img.shields.io/badge/PHP-8-777BB4?logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-8-4479A1?logo=mysql&logoColor=white)
![React](https://img.shields.io/badge/React-19-61DAFB?logo=react&logoColor=white)
![TailwindCSS](https://img.shields.io/badge/TailwindCSS-4-06B6D4?logo=tailwindcss&logoColor=white)
![Pest](https://img.shields.io/badge/Pest-3-F24C6A?logo=pestphp&logoColor=white)
![Pest Coverage](https://github.com/McNamara84/ernie/blob/image-data/coverage.svg?raw=true)
![Vitest Coverage](https://github.com/McNamara84/ernie/blob/image-data/vitest-coverage.svg?raw=true)

A metadata editor for reviewers of research data at GFZ Helmholtz Centre for Geosciences.

## Features

- Configurable resource and title types
- Configurable licenses with automatic SPDX updates
- REST API with OpenAPI documentation at `/api/v1/doc`
- Accessible interface built with Radix UI and Tailwind CSS

## Tech Stack

- **Backend:** Laravel 12 (PHP 8)
- **Database:** MySQL 8
- **Frontend:** React 19 with TypeScript via Inertia.js and Vite
- **Styling:** Tailwind CSS 4
- **Testing:** Pest for PHP, Vitest for JavaScript, Playwright for end-to-end tests

## Installation

- Clone the repository and switch to the project directory
- Ensure the required PHP extensions are installed and enabled:
  `ctype`, `curl`, `dom`, `fileinfo`, `filter`, `hash`, `iconv`, `intl`, `json`,
  `libxml`, `mbstring`, `openssl`, `pdo`, `pdo_mysql`, `simplexml`,
  `sodium`, `tokenizer`, `xml`, `xmlwriter`, `xsl`
- Install PHP dependencies: `composer install`
- Install Node dependencies: `npm install`
- Copy `.env.example` to `.env` and adjust settings
- Generate the application key: `php artisan key:generate`
- Run database migrations: `php artisan migrate`
- Start the development server: `composer run dev`

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

The service exposes a read-only REST API for metadata types.

- `GET /api/v1/resource-types` – list available resource types
- `GET /api/v1/title-types` – list available title types
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

