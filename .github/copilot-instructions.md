# ERNIE Development Guide for AI Agents

ERNIE is a **DataCite v4.5+ metadata editor** for research data curation at GFZ Helmholtz Centre. It's a Laravel 12 + React 19 + Inertia.js SSR application managing complex, multi-relational metadata with external API integrations (ORCID, ROR, GCMD, MSL).

## Language Requirements

**CRITICAL**: All code, comments, documentation, commit messages, and technical communication MUST be in **English**.

- ✅ **Code**: Variable names, function names, class names, method names
- ✅ **Comments**: Inline comments, PHPDoc blocks, JSDoc annotations
- ✅ **Documentation**: README files, markdown docs, API documentation
- ✅ **Commit messages**: Git commit messages and PR descriptions
- ✅ **Tests**: Test descriptions, assertions, test data labels
- ✅ **Configuration**: Config file comments and documentation

**Exception**: User-facing UI text must be in English as appropriate for the target audience, but all underlying code remains in English.

## Architecture Overview

### Tech Stack
- **Backend**: Laravel 12, PHP 8.2+, MySQL/MariaDB
- **Frontend**: React 19, Inertia.js 2 (SSR), TypeScript 5, Vite 7, Tailwind CSS 4, Radix UI
- **Testing**: Pest 3 (PHP), Vitest 3 (JS/TS), Playwright 1 (E2E with accessibility checks)
- **Quality**: PHPStan Level 8 (Larastan), Laravel Pint (PSR-12), ESLint 9, Prettier 3

### Core Domain Model
The central entity is `Resource` (research dataset) with:
- **Polymorphic authors/contributors**: `ResourceAuthor` → `Person` OR `Institution` via `morphTo('authorable')`
- **Many-to-many roles**: A single `Person` can be both "Author" and "Contact Person" via `resource_author_role` pivot
- **Complex relationships**: titles, licenses, descriptions, dates, keywords (free + controlled GCMD/MSL), spatial-temporal coverages, funding references, related identifiers
- **Export formats**: DataCite JSON and XML (v4.6) via `DataCiteJsonExporter` and `DataCiteXmlExporter` services
- **DOI registration**: Direct integration with DataCite REST API v2 for DOI minting and metadata updates via `DataCiteRegistrationService`

**Key polymorphic pattern**: Authors and contributors share the same `resource_authors` table but are differentiated by roles. The `authorable_type` can be `App\Models\Person` or `App\Models\Institution`. MSL laboratories are `Institution` with `identifier_type = 'labid'`.

### User Management & Authorization
ERNIE uses a **closed application model** with role-based access control:
- **User roles**: Enum-based hierarchy (admin > group_leader > curator > beginner) stored in `users.role` column
- **User creation**: Command-line only via `php artisan add-user {name} {email} {password}` (first user auto-becomes admin)
- **Authorization**: `UserPolicy` enforces hierarchical permissions with `UserRole` enum helper methods
- **Middleware**: `can.manage.users` protects user management routes (admin + group_leader access)
- **Account status**: `users.is_active` boolean with `deactivated_at` timestamp and `deactivated_by` FK
- **System protection**: User ID 1 cannot be modified, deactivated, or have password reset
- **Self-modification prevention**: Users cannot change their own role, deactivate themselves, or reset own password
- **DOI restrictions**: Beginners forced to use DataCite test mode via `DataCiteRegistrationService` check
- **Deactivated login block**: `LoginRequest` validates `is_active` before authentication

**Permission matrix**:
- **Admin**: Full access (manage all users, production DOI, all curation features)
- **Group Leader**: Manage curator/beginner users (NOT promote to group_leader/admin), production DOI, full curation
- **Curator**: Standard curation, test DOI only, NO user management
- **Beginner**: Limited curation, test DOI only (forced), NO user management

**Key implementation files**:
- `app/Enums/UserRole.php`: Enum with `canManageUsers()`, `canPromoteToGroupLeader()`, `canRegisterProductionDoi()`
- `app/Policies/UserPolicy.php`: Authorization with `update()`, `updateRole()`, `deactivate()`, `reactivate()`, `resetPassword()`
- `app/Http/Controllers/UserController.php`: CRUD endpoints (`index`, `updateRole`, `deactivate`, `reactivate`, `resetPassword`)
- `app/Http/Middleware/EnsureUserCanManageUsers.php`: Route protection middleware
- `resources/js/Pages/Users/Index.tsx`: User management interface with role changes, status management
- `resources/js/components/user-role-badge.tsx`: Visual role indicator with color variants

### Data Flow Patterns
1. **Editor workflow**: `editor.tsx` (React) → `POST /editor/resources` → `ResourceController@store` → DB transaction saving 10+ related tables
2. **Legacy import**: Browse old datasets → lazy-load metadata via API → pre-populate editor form → **manual enrichment in editor** → save as new `Resource` (not an automated sync!)
3. **External sync**: Artisan commands (`GetGcmdScienceKeywords`, `GetGcmdPlatforms`, `GetGcmdInstruments`, `GetMslKeywords`, `GetRorIds`, `SyncSpdxLicenses`) populate controlled vocabularies
4. **Export**: `Resource` model → `DataCiteXmlExporter` service → validate against XSD → download XML with validation warnings in header
5. **DOI registration**: `Resource` → `DataCiteRegistrationService` → DataCite REST API v2 (test/production endpoints) → save DOI to `resources.doi` column
6. **DOI metadata update**: Existing DOI → `DataCiteRegistrationService@updateMetadata` → PUT to DataCite API → sync latest metadata

### Request/Response Conventions
- **Inertia pages**: Controllers return `Inertia::render('page', $props)` for SSR
- **Infinite scroll APIs**: `loadMore` endpoints return JSON with `{resources: [], pagination: {}}` structure
- **Form submissions**: Use `StoreResourceRequest` validation with snake_case backend ↔ camelCase frontend transformation in controllers
- **Error handling**: Laravel validation errors automatically formatted for Inertia forms; 500s logged with context

## Critical Developer Knowledge

### Build & Run Commands
```bash
# Development (concurrent: server + queue + Vite HMR)
composer run dev                 # Standard mode
composer run dev:ssr            # With SSR + Pail logs

# Testing (always run before commits)
composer run test               # Pest PHP tests
npm test                        # Vitest JS/TS unit tests  
npm run test:e2e               # Playwright E2E (auto-starts server)

# Code quality (CI requirements)
./vendor/bin/pint              # Fix PHP code style (PSR-12)
./vendor/bin/phpstan analyse   # Static analysis Level 8
npm run lint                   # ESLint + Prettier auto-fix
npm run types                  # TypeScript type checking

# External vocabulary sync (run periodically)
php artisan get-gcmd-science-keywords  # Fetch NASA GCMD Science Keywords (3669 concepts, 2 pages)
php artisan get-gcmd-platforms         # Fetch NASA GCMD Platforms (1289 concepts, 1 page)
php artisan get-gcmd-instruments       # Fetch NASA GCMD Instruments (2082 concepts, 2 pages)
php artisan get-msl-keywords           # Fetch MSL Materials Science keywords (11 top-level concepts)
php artisan get-ror-ids                # Sync ROR organization identifiers
php artisan spdx:sync-licenses         # Update SPDX license database
```

**Important**: The `composer run dev` script uses `concurrently` to run 3 processes. Queue worker is essential for background jobs (ORCID validation, ROR sync).

### Testing Best Practices
- **Pest tests**: Use `RefreshDatabase` trait, live in `tests/pest/{Unit,Feature}/`
- **Vitest**: Test React components with `@testing-library/react`, mock API calls with MSW patterns (see `tests/vitest/`)
- **Playwright**: Organized by priority (`critical/`, `workflows/`, `accessibility/`). Run with `start-server-and-test` for isolation. Uses custom `page-objects/` helpers.
- **Coverage targets**: Pest exports to `clover.xml`, Vitest to `coverage/`, both integrated in CI

### Frontend Patterns (React + Inertia)
- **Page components**: `resources/js/Pages/*.tsx` receive props from Inertia controllers
- **Shared state**: Use Inertia's `usePage().props` for global state (user, flash messages)
- **Path helpers**: Always use `withBasePath()` from `@/lib/base-path` for Laravel routing (supports subdirectory deployments)
- **CSRF tokens**: Automatically handled by `buildCsrfHeaders()` in Axios interceptors (see `app.tsx`)
- **Form validation**: Use Inertia's `useForm()` hook with server-side Laravel validation
- **UI components**: Radix UI primitives in `Components/ui/`, composed with `cn()` (tailwind-merge + clsx)

### Database & Migrations
**Key tables**:
- `users`: Authentication with `role` (enum: admin, group_leader, curator, beginner), `is_active` boolean, `deactivated_at`, `deactivated_by`
- `resources`: Main entity with `resource_type_id`, `language_id`, `created_by_user_id`, `updated_by_user_id`, `doi` (nullable string for registered DOIs)
- `resource_authors`: Polymorphic junction with `authorable_type/id`, `position`, `email`, `website`
- `resource_author_role`: Many-to-many pivot for roles (enables same person as author + contact)
- `affiliations`: Child of `resource_authors` with `ror_id` for ROR-linked institutions
- `resource_controlled_keywords`: GCMD/MSL keywords with `scheme` discriminator (`gcmd:sciencekeywords`, `gcmd:platforms`, `msl`)
- `landing_pages`: Public landing pages for resources, required before DOI registration (status: draft/published)

**Migration pattern**: Timestamped migrations in `database/migrations/`, always include `Schema::dropIfExists()` in `down()` method.

### API Integrations
- **ORCID**: Public API (no auth) via `OrcidService` for researcher lookup (`/v1/orcid/search`, `/v1/orcid/{orcid}`)
- **ROR**: Synced via `GetRorIds` command, searchable via `RorAffiliationController`
- **GCMD**: NASA vocabularies fetched via `GetGcmdScienceKeywords`, `GetGcmdPlatforms`, `GetGcmdInstruments`, cached in DB, exposed at `/v1/vocabularies/gcmd-*`
- **MSL**: Materials Science keywords from TIB, downloaded via `GetMslKeywords`, transformed by `MslKeywordTransformer`
- **DataCite**: REST API v2 integration for DOI registration and metadata updates
  - **Test mode**: `api.test.datacite.org` with test prefixes (10.83279, 10.83186, 10.83114)
  - **Production mode**: `api.datacite.org` with production prefixes (10.5880, 10.26026, 10.14470)
  - **Authentication**: HTTP Basic Auth with credentials from `config/datacite.php`
  - **Retry logic**: 3 attempts with exponential backoff for network errors
  - **Validation**: Requires landing page before DOI registration
- **ELMO**: External consumer API protected by `elmo.api-key` middleware (see `routes/api.php`)

### Common Pitfalls & Solutions
1. **Polymorphic relation updates**: When updating `resource_authors`, always delete old MSL labs separately (they're institutions with `identifier_type = 'labid'`)
2. **CSRF token issues**: Use Axios interceptor pattern from `app.tsx`; 419 errors trigger page reload to refresh token
3. **Inertia SSR**: Enable `ssr` in `config/inertia.php` and run `php artisan inertia:start-ssr` for server-side rendering (port 13714)
4. **Path aliases**: `@/` maps to `resources/js/`, `@data/` to `resources/data/`, `@tests/` to `tests/` (see `vite.config.ts`)
5. **Date formatting**: Backend expects `Y-m-d` (HTML date inputs), converts to `datetime` in DB. Frontend transforms via `Carbon::parse()`
6. **Controlled keywords**: Use `scheme` field to distinguish GCMD vs MSL keywords (was `vocabularyType` in old code)
7. **Subdirectory deployment**: Production runs with `/ernie` prefix via Portainer - ALWAYS use `withBasePath()` for routes, never hardcode paths!
8. **DataCite API mocking**: In tests, use `Http::fake(['*datacite.org/*' => ...])` with wildcard to catch both test and production endpoints
9. **PHPStan nullsafe operators**: Laravel's `RequestException->response` can be null at runtime despite PHPDoc indicating otherwise - use explicit null checks with `@phpstan-ignore notIdentical.alwaysTrue` comments
10. **Resource factory DOI**: By default, `Resource::factory()` generates a DOI - set `'doi' => null` explicitly in tests if testing DOI registration
11. **First user admin assignment**: User role logic belongs in `add-user` command (checks `User::count() === 0`), NOT in migrations (data doesn't exist yet)
12. **User ID 1 protection**: Always check `$user->id === 1` in policies before allowing modifications - this user is system-critical
13. **Self-modification prevention**: Policies must check `$authUser->id !== $targetUser->id` for role changes, deactivation, password reset
14. **Beginner DOI restrictions**: Check user role in `DataCiteRegistrationService` - beginners MUST use test mode regardless of config

## Project-Specific Conventions

### Language & Communication Standards
**All technical artifacts MUST be in English**:
- Code identifiers (variables, functions, classes, methods)
- Code comments (inline, block, PHPDoc, JSDoc, TSDoc)
- Documentation files (README, guides, API docs)
- Commit messages and PR descriptions
- Test names and descriptions
- Configuration file comments
- Error messages and logging output
- Database migration/seeder comments

**Only user-facing strings** (UI labels, form text, help text) may be translated to German.

### Naming Conventions
- **Routes**: Kebab-case (`old-datasets`, `resources.load-more`)
- **Controllers**: StudlyCase methods, snake_case request params, validate via FormRequest classes
- **Models**: Eloquent naming (singular model, plural table), explicit PHPStan annotations for relations
- **Frontend**: camelCase props/state, PascalCase components, kebab-case file/route names
- **All names**: English words only (no German abbreviations or terms)

### Code Organization
- **Services**: Business logic in `app/Services/` (e.g., `DataCiteXmlExporter`, `DataCiteRegistrationService`, `OrcidService`)
- **Support classes**: Helpers in `app/Support/` (e.g., `BooleanNormalizer`)
- **Requests**: Form validation in `app/Http/Requests/` with `StoreResourceRequest` handling complex nested data, `RegisterDoiRequest` for DOI prefix validation
- **Commands**: Artisan commands in `app/Console/Commands/` for external sync tasks
- **Enums**: PHP enums in `app/Enums/` (e.g., `UserRole`) with helper methods for permissions and labels
- **Policies**: Authorization logic in `app/Policies/` (e.g., `UserPolicy`) using `AuthorizesRequests` trait in controllers

### DataCite Export Rules
- **Creators vs Contributors**: Authors with "Author" role → DataCite `creators`, others → `contributors`
- **Mandatory fields**: DOI, year, creators (≥1), titles (≥1), publisher (fixed: GFZ), resourceType, version
- **XML validation**: `DataCiteXmlValidator` validates against XSD v4.6, returns warnings in `X-Validation-Warning` header
- **Namespace**: Always use `http://datacite.org/schema/kernel-4` with XSD `kernel-4.6/metadata.xsd`

### React Component Patterns
- **Editor form**: `editor.tsx` uses 10+ sub-forms (`curation/fields/*`) with drag-and-drop reordering (`@dnd-kit`)
- **Infinite scroll**: Resources/old-datasets use `loadMore` API with `IntersectionObserver` pattern
- **Modal dialogs**: Radix `Dialog` components with controlled state, close on success/escape
- **Controlled vocabularies**: Tagify for MSL keywords, custom autocomplete for GCMD (hierarchical paths)
- **DOI registration workflow**: 
  - `RegisterDoiModal.tsx`: Main modal for DOI registration with prefix selection
  - `DoiStatusBadge.tsx`: Interactive badge showing DOI status with copy-to-clipboard and DataCite link
  - State management via `useCallback` for performance optimization
  - Optimistic UI updates with immediate feedback
- **Documentation components**: Modular, reusable components in `components/docs/`
  - `DocsSidebar.tsx`: Sticky navigation with scroll-spy, mobile-responsive FAB menu
  - `DocsSection.tsx`: Section wrapper with icons and anchor links
  - `DocsCodeBlock.tsx`: Code snippets with copy-to-clipboard
  - `WorkflowSteps.tsx`: Visual step-by-step guides with timeline design

## Documentation & Support

### In-App Documentation (`/docs`)
ERNIE has a **unified, role-based documentation system** at `/docs`:
- **Single-page architecture**: All documentation on one page with table of contents navigation
- **Role-based filtering**: Content sections visible based on user role (beginner, curator, group_leader, admin)
- **7 main sections**:
  1. Quick Start Guide (all roles)
  2. Curation Workflow (beginner+) - Complete XML upload → save workflow
  3. Landing Pages (beginner+) - Create, preview, publish landing pages
  4. DOI Registration (beginner+) - Test/production DOI with role-specific restrictions
  5. User Management (group_leader+) - CLI user creation, role management
  6. System Administration (admin only) - External service sync, configuration
  7. API Documentation (all roles) - Link to interactive Swagger UI
- **Modern UX features**:
  - Table of contents with clickable cards for quick navigation to sections
  - Smooth scroll navigation with offset for fixed header
  - Copy-to-clipboard for all code snippets
  - Visual workflow step-by-step guides with timeline design
  - Mobile-responsive grid layout for TOC cards
  - Dark mode support
- **Implementation**: Route passes `userRole` prop, frontend filters visible sections based on role hierarchy
- **Components location**: `resources/js/components/docs/`
  - `DocsSection.tsx`: Section wrapper with icons and anchor links
  - `DocsCodeBlock.tsx`: Code snippets with copy-to-clipboard
  - `WorkflowSteps.tsx`: Visual step-by-step workflow guides

### Other Documentation
- **API docs**: Interactive OpenAPI 3.1 at `/api/v1/doc` (Swagger UI via `swagger.tsx`)
- **Changelog**: Interactive timeline at `/changelog` (data from `resources/data/changelog.json`)
- **Code comments**: PHPStan-strict annotations for all model relations, complex business logic explained inline

**Important**: The `/docs/users` route was removed - all user documentation is now integrated into the main `/docs` page with role-based visibility.

## Environment Setup Notes
- **Google Maps**: Set `GM_API_KEY` in `.env` for spatial coverage editor
- **Old database**: Configure `DB_OLD_*` variables for legacy data import (one-time migration with manual enrichment)
- **DataCite API**: Configure test and production credentials in `.env`:
  - `DATACITE_TEST_MODE=true` for development
  - `DATACITE_TEST_USERNAME`, `DATACITE_TEST_PASSWORD`, `DATACITE_TEST_ENDPOINT`
  - `DATACITE_PRODUCTION_USERNAME`, `DATACITE_PRODUCTION_PASSWORD`, `DATACITE_PRODUCTION_ENDPOINT`
  - See `config/datacite.php` for full configuration structure
- **ELMO API**: Set `ELMO_API_KEY` for external service integration
- **Queue**: Database-backed queue requires `php artisan queue:listen` (included in `composer run dev`)
- **Production deployment**: Uses Portainer + Docker with `/ernie` subdirectory prefix (see `docker-compose.prod.yml`)

When making changes, always consider:
1. **Language consistency**: All code, comments, docs, and commit messages in English only
2. **Data integrity**: Use DB transactions for multi-table saves (see `ResourceController@store`)
3. **Type safety**: Add PHPStan annotations, run `npm run types` for TS checks
4. **Accessibility**: Follow WCAG 2.1 AA (Radix UI helps), test with Playwright `axe-core`
5. **Performance**: Eager-load relations to avoid N+1 queries, paginate with `perPage` limits
6. **Security**: Validate with FormRequests, use CSRF tokens, sanitize XML/JSON exports
7. **Code quality**: Run `composer run test`, `npm test`, and linters before committing
