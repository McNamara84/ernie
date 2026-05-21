# ERNIE Copilot Instructions

## Project Overview
ERNIE is a **metadata curation system** for research datasets at GFZ Helmholtz Centre. It implements the **DataCite Metadata Schema v4.7** for DOI registration of scientific publications and **IGSN** (International Generic Sample Number) registration for physical samples. The project also provides **landing pages** for registered DOIs and a **portal** for searching published datasets.

**Version:** 1.0.0

**Stack:** Laravel 13 (PHP 8.5) + Inertia.js + React 19 + shadcn/ui v4 + TailwindCSS 4 + Pest 4 + Playwright + Vitest

## Language Policy

**IMPORTANT:** All project content MUST be written in **English**, regardless of the conversation language with the user. This includes:
- Code comments (PHP, TypeScript, CSS)
- Changelog entries (`resources/data/changelog.json`)
- README and documentation files
- User documentation (`resources/js/pages/docs.tsx`)
- Commit messages
- PHPDoc and JSDoc annotations
- Test descriptions and assertions
- Error messages and toast notifications in code
- React components must use shadcn/ui exclusively

The only exception is the conversation with the user, which may be in any language.

## Local Development Environment

Decision defaults:

- Fast Mode is the daily default. Optional Docker profiles are opt-in.
- On Windows, prefer a WSL2 checkout with VS Code Remote - WSL.
- Keep PHP, Composer, Artisan, Pest, and PHPStan container-first.
- Keep ESLint, TypeScript, Vitest, and Playwright on the host.
- Use [docs/local-development.md](docs/local-development.md) and [docs/testing.md](docs/testing.md) for operational details.

Canonical commands:

```bash
npm run docker:dev:up
npm run check:backend
npm run check:frontend
npm run check:parity
```

## Architecture

### Backend (Laravel)

#### Controllers (`app/Http/Controllers/`)
- **Resource CRUD:** `ResourceController` – List/store/update/delete resources
- **Editor:** `EditorController` – Curation form with DataCite fields
- **IGSN:** `IgsnController`, `BatchIgsnController`, `BatchIgsnRegistrationController`, `UploadIgsnCsvController`, `IgsnMapController`
- **DataCite:** `DataCiteImportController`, `DoiValidationController`, `Api/DataCiteController`, `Api/DoiValidationController`
- **Landing Pages:** `LandingPageController`, `LandingPageDomainController`, `LandingPagePreviewController`, `LandingPagePublicController`
- **Portal:** `PortalController` – Public dataset search portal
- **Settings:** `Settings/EditorSettingsController`, `Settings/FontSizeController`, `Settings/PasswordController`, `Settings/PidSettingsController`, `Settings/ProfileController`, `Settings/ThesaurusSettingsController`
- **Users:** `UserController`, `RoleController`
- **Upload:** `UploadXmlController`, `UploadIgsnCsvController`
- **Vocabularies:** `VocabularyController`, `LanguageController`, `LicenseController`, `DateTypeController`, `TitleTypeController`, `ResourceTypeController`, `RelationTypeController`, `RelatedIdentifierTypeController`
- **External APIs:** `OrcidController`, `RorAffiliationController`
- **Other:** `ChangelogController`, `ContactMessageController`, `DocsController`, `LogController`, `OldDatasetController`, `OldDataStatisticsController`
- **Auth:** `Auth/` – Login, password reset, welcome, email verification

#### Services (`app/Services/`)
- **DataCite Export:** `DataCiteXmlExporter`, `DataCiteJsonExporter` – Export to DataCite XML/JSON; `DataCiteLinkedDataExporter` – Export to DataCite Linked Data JSON-LD; `SchemaOrgJsonLdExporter` – Generate Schema.org Dataset JSON-LD for SEO embedding
- **DataCite Registration:** `DataCiteRegistrationService`, `DataCiteApiService`, `DataCiteServiceInterface`, `FakeDataCiteRegistrationService` (test double)
- **DataCite Sync:** `DataCiteSyncService`, `DataCiteSyncResult` – Auto-sync metadata on save
- **DataCite Import:** `DataCiteImportService`, `DataCiteToResourceTransformer` – Import from DataCite API
- **DataCite Validation:** `DataCiteXmlValidator`, `JsonSchemaValidator` – Validate against DataCite Schema 4.7
- **Resource Storage:** `ResourceStorageService` – Save/update Resource with all relations
- **IGSN:** `IgsnCsvParserService` – Parse pipe-delimited CSV files; `IgsnStorageService` – Store parsed IGSN data
- **DOI:** `DoiSuggestionService` – Suggest DOI prefixes
- **Editor:** `Editor/EditorDataTransformer` – Transform data for editor form
- **Entities:** `Entities/AffiliationService`, `Entities/InstitutionService`, `Entities/PersonService` – Entity-specific business logic
- **Landing Pages:** `LandingPageResourceTransformer`, `SlugGeneratorService`
- **Portal:** `PortalSearchService` – Search published datasets
- **Vocabularies:** `VocabularyCacheService`, `MslVocabularyService`, `MslKeywordTransformer`, `OldDatasetKeywordTransformer`
- **ORCID/ROR:** `OrcidService`, `RorLookupService` (singleton)
- **Status:** `Pid4instStatusService`, `ThesaurusStatusService`
- **Logging:** `LogService`, `UploadLogService`
- **Cache:** `ResourceCacheService`
- **Legacy:** `OldDatasetEditorLoader` – Load old datasets into editor
- **Traits:** `Traits/DataCiteExporterHelpers` – Shared export logic

#### Models (`app/Models/`)
- **Central:** `Resource` – 20+ relationships (titles, creators, subjects, geoLocations, rights, instruments, etc.)
- **IGSN:** `IgsnMetadata`, `IgsnClassification`, `IgsnGeologicalAge`, `IgsnGeologicalUnit`
- **Persons:** `Person`, `ResourceCreator`, `ResourceContributor`, `Affiliation`, `Institution`
- **Metadata:** `Title`, `TitleType`, `Description`, `DescriptionType`, `Subject`, `ResourceDate`, `DateType`
- **Identifiers:** `AlternateIdentifier`, `RelatedIdentifier`, `RelationType`, `IdentifierType`, `IdentifierTypePattern`
- **Rights/Funding:** `Right`, `FundingReference`, `FunderIdentifierType`
- **Geo/Format:** `GeoLocation`, `Format`, `Size`, `Language`, `ResourceType`
- **Publishing:** `Publisher`, `LandingPage`, `LandingPageDomain`, `ContributorType`
- **System:** `User`, `Setting`, `PidSetting`, `ThesaurusSetting`, `ContactMessage`, `OldDataset`, `ResourceInstrument`

#### Enums (`app/Enums/`)
- `UserRole` – ADMIN, GROUP_LEADER, CURATOR, BEGINNER hierarchy
- `CacheKey` – Centralized cache key management with TTL values
- `UploadErrorCode` – Standardized error codes for XML/CSV upload failures

#### Authorization
- **Policies:** `ResourcePolicy`, `UserPolicy`, `LandingPagePolicy` – Model-level authorization
- **Gates** (defined in `AppServiceProvider`):
  - `access-logs` – Admin only
  - `access-old-datasets` – Admin only
  - `access-statistics` – Admin, Group Leader
  - `access-users` – Admin, Group Leader
  - `access-editor-settings` – Admin, Group Leader
  - `manage-users` – Admin, Group Leader
  - `register-production-doi` – All except Beginner
  - `delete-logs` – Admin only
  - `manage-thesauri` – Admin, Group Leader
  - `delete-all-resources` – Admin only
  - `manage-landing-pages` – Admin, Group Leader, Curator

#### Jobs (`app/Jobs/`)
- `ImportFromDataCiteJob` – Import metadata from DataCite API
- `UpdatePidJob` – Update PID instrument registry
- `UpdateThesaurusJob` – Update GCMD/MSL vocabularies

#### Observers
- `ResourceObserver` – Reacts to Resource model events (cache invalidation, etc.)

#### Custom Rules (`app/Rules/`)
- `SafeUrl` – Validates URLs for security
- `SafeDomainUrl` – Validates domain-specific URLs

#### Custom Artisan Commands (`app/Console/Commands/`)
- `GetGcmdScienceKeywords`, `GetGcmdInstruments`, `GetGcmdPlatforms` – Fetch NASA GCMD vocabularies
- `GetMslKeywords` – Fetch MSL vocabularies
- `GetPid4instInstruments` – Fetch B2INST PID instruments
- `GetRorIds` – Fetch ROR institution identifiers
- `SyncSpdxLicenses` – Sync SPDX license list
- `UpdateLicenseUsageCount` – Update license usage statistics
- `ValidateLandingPageDois` – Validate DOIs on landing pages
- `ClearApplicationCache` – Clear all application caches

#### Support (`app/Support/`)
- `NameParser`, `BooleanNormalizer`, `UrlNormalizer`
- `GcmdVocabularyParser`, `GcmdUriHelper`, `UriHelper`
- `FunderIdentifierTypeDetector`, `XmlKeywordExtractor`
- `MslLaboratoryService` – MSL laboratory lookups
- `UploadError` – Upload error handling

### Frontend (React/Inertia)

#### Pages (`resources/js/pages/`)
- **Core:** `editor.tsx`, `resources.tsx`, `dashboard.tsx`
- **IGSN:** `igsns/index.tsx`, `igsns/map.tsx`
- **Landing Pages:** `LandingPages/default_gfz.tsx`, `LandingPages/default_gfz_igsn.tsx`
- **Portal:** `portal.tsx` – Public dataset search
- **Settings:** `settings/index.tsx`, `settings/profile.tsx`, `settings/password.tsx`, `settings/appearance.tsx`
- **Admin:** `Users/Index.tsx`, `Logs/Index.tsx`, `old-datasets.tsx`, `old-statistics.tsx`
- **Public:** `welcome.tsx`, `about.tsx`, `changelog.tsx`, `docs.tsx`, `legal-notice.tsx`
- **Auth:** `auth/login.tsx`, `auth/forgot-password.tsx`, `auth/reset-password.tsx`, `auth/welcome.tsx`, etc.

#### Components (`resources/js/components/`)
- **Curation:** `curation/datacite-form.tsx`, `curation/fields/` (author, contributor, date, description, keywords, license, etc.), `curation/form-fields/` (reusable form input wrappers), `curation/modals/`, `curation/types/`, `curation/utils/`
- **IGSN:** `igsns/bulk-actions-toolbar.tsx`, `igsns/igsn-filters.tsx`, `igsns/igsn-search-input.tsx`, `igsns/status-badge.tsx`
- **Docs:** `docs/docs-section.tsx`, `docs/docs-sidebar.tsx`, `docs/docs-tabs.tsx`, `docs/docs-code-block.tsx`, `docs/workflow-steps.tsx`
- **Portal:** `portal/PortalFilters.tsx`, `portal/PortalMap.tsx`, `portal/PortalResultCard.tsx`, `portal/PortalResultList.tsx`
- **Statistics:** `statistics/` – 25+ chart/analysis components (recharts-based)
- **Landing Pages:** `landing-pages/modals/`
- **Settings:** `settings/` – PID settings, thesaurus cards, license/resource-type popovers
- **UI Library:** `ui/` – 50+ shadcn/ui components (see UI Component Guidelines)
- **Layout:** `app-sidebar.tsx`, `app-header.tsx`, `app-footer.tsx`, `app-shell.tsx`, `breadcrumbs.tsx`, `nav-*.tsx`

#### Hooks (`resources/js/hooks/`)
- `use-doi-validation.ts` – DOI validation with debounce
- `use-ror-affiliations.ts` – ROR institution lookup
- `use-orcid-autofill.ts` – ORCID author autofill
- `use-affiliations-tagify.ts` – Tagify-based affiliation input
- `use-pid4inst-instruments.ts` – B2INST instrument search
- `use-msl-laboratories.ts` – MSL laboratory lookup
- `use-portal-filters.ts` – Portal filter state management
- `use-form-validation.ts`, `use-funding-reference-validation.ts`, `use-identifier-validation.ts`
- `use-font-size.tsx`, `use-appearance.tsx`, `use-session-warmup.ts`, `use-debounce.ts`, `use-scroll-spy.ts`

#### Layouts (`resources/js/layouts/`)
- `app-layout.tsx` – Main authenticated layout with sidebar
- `auth-layout.tsx` – Authentication pages layout
- `portal-layout.tsx` – Public portal layout
- `public-layout.tsx` – Public pages (welcome, about, legal-notice)
- `LandingPageLayout.tsx` – Landing page template layout

#### Type-safe Routing
- Laravel Wayfinder generates routes → `resources/js/routes/` (organized by feature: `api/`, `editor/`, `igsns/`, `resources/`, `users/`, `settings/`, etc.)

#### Types (`resources/js/types/`)
- `index.d.ts` – Shared Inertia page props
- Feature-specific: `resources.ts`, `portal.ts`, `landing-page.ts`, `affiliations.ts`, `gcmd.ts`, `upload.ts`, `old-datasets.ts`, `docs.ts`

### Data Flow
1. **XML Upload** → `UploadXmlController` → Session storage → Editor page
2. **Editor Form** → `ResourceController@store` → `ResourceStorageService` → Database
3. **DOI Registration** → `DataCiteRegistrationService` → DataCite API
4. **DataCite Sync on Save** → `DataCiteSyncService` → Updates DataCite if DOI already registered
5. **DataCite Import** → `DataCiteImportController` → `ImportFromDataCiteJob` → `DataCiteImportService` → Database
6. **IGSN CSV Upload** → `UploadIgsnCsvController` → `IgsnCsvParserService` → `IgsnStorageService` → Database
7. **IGSN Batch Registration** → `BatchIgsnRegistrationController` → DataCite API
8. **Landing Page Generation** → `LandingPageController` → `LandingPageResourceTransformer` → Rendered page
9. **Portal Search** → `PortalController` → `PortalSearchService` → Public search results
10. **JSON-LD Export** → `ResourceController@exportJsonLd` / `IgsnController@exportJsonLd` / `LandingPagePublicController@exportJsonLd` → `DataCiteLinkedDataExporter` → `.jsonld` download
11. **Schema.org Embedding** → `LandingPagePublicController@renderLandingPage` → `SchemaOrgJsonLdExporter` → Inertia prop → `<script type="application/ld+json">` in `<Head>`

## Development Commands

```bash
# Start the default Docker development stack
npm run docker:dev:up

# Start only the backend services needed for PHP checks
npm run docker:dev:backend:d

# Canonical validation entry points
npm run check:backend
npm run check:frontend
npm run check:parity

# Code quality
./vendor/bin/pint               # PHP formatting (Laravel preset)
npm run phpstan:check
npm run lint:check
npm run lint
npm run types
```

## Testing Patterns

### Pest PHP Tests
- Located in `tests/pest/` with subdirectories: `Feature/`, `Unit/`, `Arch/`, `Browser/`, `Datasets/`, `Helpers/`
- Default local behavior is intentionally fast: `tests/pest/CreatesApplication.php` defaults to `APP_ENV=testing`, `DB_CONNECTION=sqlite`, and `DB_DATABASE=:memory:` unless the MySQL-sensitive slice opts in via `ERNIE_TEST_DB_*`.
- Use `npm run test:php` for the fast default loop.
- Use `npm run test:php:mysql-sensitive` for the explicit MySQL-sensitive migration file slice; this path uses an isolated `ernie_test` schema inside Docker MySQL and resets that schema between files.
- Keep MySQL-backed verification targeted to driver-sensitive changes.

### Pest Browser Tests (Pest v4 + Playwright)
- **Preferred for E2E tests** – Uses `pestphp/pest-plugin-browser` (Pest v4)
- Located in `tests/pest/Browser/` – PHP-based E2E tests with Playwright under the hood
- Direct access to Laravel factories, `RefreshDatabase`, and other Laravel features
- Run with: `./vendor/bin/pest tests/pest/Browser/`
- Example pattern:
```php
it('validates DOI on blur', function () {
    $user = User::factory()->create();
    
    visit('/editor')
        ->asUser($user)
        ->fill('#doi', '10.5880/test.2026.001')
        ->blur('#doi')
        ->waitForText('DOI bereits vergeben');
});
```
- **Advantages over JS Playwright:**
  - Same language as backend (PHP)
  - Direct database seeding with factories
  - `RefreshDatabase` works seamlessly
  - Unified test suite with `composer test`

### Vitest (React)
- Located in `tests/vitest/` – mirrors component structure with: `components/`, `hooks/`, `layouts/`, `lib/`, `pages/`, `schemas/`, `services/`, `types/`, `utils/`
- Setup in `vitest.setup.ts` includes ResizeObserver/IntersectionObserver mocks
- Use `@testing-library/react` for component testing
- `npm run test` is watch mode. For one-shot local validation, prefer `npm run test:run`.

### Playwright E2E (Legacy/JavaScript)
- Located in `tests/playwright/` – organized by feature (critical/, accessibility/, workflows/, stage/)
- Multiple configs: `playwright.config.ts` (local), `playwright.devstack.config.ts`, `playwright.docker.config.ts`, `playwright.stage.config.ts`, `playwright.stage-local.config.ts`
- **Note:** For new E2E tests, prefer Pest Browser Tests (PHP) over JavaScript Playwright tests

## Key Conventions

### PHP/Laravel
- **Prefer modern PHP 8.5 features** wherever possible:
  - Property hooks (`get` / `set` in class properties)
  - Asymmetric visibility (`public private(set)`)
  - `readonly` classes and properties
  - Enums with methods and interfaces
  - `match` expressions over `switch`
  - Named arguments for clarity
  - First-class callable syntax (`strlen(...)`) and `Closure::fromCallable()`
  - Fibers for async patterns where applicable
  - Pipe operator (`|>`) for functional-style chaining
  - `array_find()`, `array_find_key()`, `array_any()`, `array_all()` over manual loops
  - Union types, intersection types, and `never`/`null`/`true`/`false` standalone types
  - `#[Override]` attribute on overridden methods
- Strict typing: `declare(strict_types=1)` in all PHP files
- PHPDoc annotations for relationships with generic types: `@return BelongsTo<ResourceType, static>`
- Form Requests in `app/Http/Requests/` for validation
- Cache keys defined in `app/Enums/CacheKey.php`

### React/TypeScript
- Import sorting enforced via `eslint-plugin-simple-import-sort`
- Types in `resources/js/types/` – share with backend via Inertia props
- Session warmup pattern (`lib/session-warmup.ts`) prevents CSRF issues on fresh containers

### Database
- Migrations in `database/migrations/` – include `down()` methods for rollback support
- SQLite for testing, MySQL/MariaDB for production
- Resource relations use pivot tables with position ordering
- **ER Diagrams:** When modifying the database schema (new tables, columns, relationships, or constraint changes), you MUST update both ER diagrams:
  - `database/er-diagram.md` (Mermaid syntax)
  - `database/er-diagram-plantuml.md` (PlantUML syntax)

## Design Philosophy & UX Guidelines

### Modern UX Design

**IMPORTANT:** All user-facing interfaces MUST follow modern UX design principles. This includes:

- **Clean, minimal layouts** – Avoid visual clutter; prioritize whitespace and content hierarchy
- **Responsive design** – All pages must work seamlessly on desktop, tablet, and mobile
- **Smooth transitions & animations** – Use Framer Motion or CSS transitions for state changes, page transitions, and micro-interactions
- **Accessible by default** – Follow WCAG 2.1 AA standards (keyboard navigation, screen readers, color contrast)
- **Consistent visual language** – Use the design system (shadcn/ui + TailwindCSS 4) uniformly across all pages
- **Intuitive interactions** – Prefer progressive disclosure, inline editing, and contextual actions over modal-heavy workflows
- **Clear feedback** – Every user action should have visible feedback (loading states, success/error toasts, skeleton placeholders)
- **Dark mode support** – Respect system preferences and provide proper dark mode styling via TailwindCSS

### shadcn/ui v4 as Primary UI Library

**MANDATORY:** All UI elements MUST use **shadcn/ui v4** components from `resources/js/components/ui/`. shadcn/ui is the single source of truth for the component library. Never build custom components when a shadcn/ui component exists for the use case. When a component is not yet available in the project, install it via `npx shadcn@latest add <component>`.

#### shadcn/ui v4 Component Patterns

All components MUST follow the **v4 pattern**. Key differences from v3:

- **No `React.forwardRef`** – Use plain function components with `ref` as a regular prop (React 19 native ref forwarding)
- **No `.displayName`** – Not needed without `forwardRef`
- **`data-slot` attribute** – Every component root element must have a `data-slot` attribute for styling hooks (e.g., `data-slot="button"`)
- **`radix-ui` imports** – Import from `radix-ui` package, not `@radix-ui/react-*` (e.g., `import { Dialog as DialogPrimitive } from "radix-ui"`)

Example v4 component:
```tsx
function MyComponent({ className, ref, ...props }: React.ComponentProps<"div">) {
  return <div data-slot="my-component" className={cn("...", className)} ref={ref} {...props} />
}
```

❌ **v3 pattern (DO NOT USE):**
```tsx
const MyComponent = React.forwardRef<HTMLDivElement, Props>((props, ref) => (
  <div ref={ref} {...props} />
))
MyComponent.displayName = "MyComponent"
```

## UI Component Guidelines (shadcn/ui)

### Required Components

All UI elements should use shadcn/ui components from `resources/js/components/ui/` if possible:

| Element | Component | Import |
|---------|-----------|--------|
| Buttons | `<Button>` | `@/components/ui/button` |
| Form inputs | `<Input>`, `<Textarea>`, `<Select>` | `@/components/ui/input`, etc. |
| Modals | `<Dialog>` | `@/components/ui/dialog` |
| Side panels | `<Sheet>` | `@/components/ui/sheet` |
| Notifications | `toast()` from Sonner | `sonner` |
| Loading spinner | `<Spinner>` | `@/components/ui/spinner` |
| Loading button | `<LoadingButton>` | `@/components/ui/loading-button` |
| Loading placeholder | `<Skeleton>` | `@/components/ui/skeleton` |
| Data tables | `<DataTable>` | `@/components/ui/data-table` |
| Forms with validation | `<Form>`, `<FormField>` | `@/components/ui/form` |

### Button Variants

Use the correct variant for each context:

```tsx
// Primary action (Save, Submit, Create)
<Button>Save</Button>

// Secondary/Cancel action
<Button variant="outline">Cancel</Button>
<Button variant="secondary">Back</Button>

// Destructive action (Delete, Remove)
<Button variant="destructive">Delete</Button>

// Icon-only or subtle buttons
<Button variant="ghost" size="icon"><X /></Button>

// Link-style button
<Button variant="link">Learn more</Button>
```

### Dialog Structure

All dialogs must follow this structure:

```tsx
<Dialog open={open} onOpenChange={setOpen}>
    <DialogContent>
        <DialogHeader>
            <DialogTitle>Title</DialogTitle>
            <DialogDescription>Optional description</DialogDescription>
        </DialogHeader>
        
        {/* Content */}
        
        <DialogFooter className="gap-2">
            <Button variant="outline" onClick={onClose}>Cancel</Button>
            <Button onClick={onConfirm}>Confirm</Button>
        </DialogFooter>
    </DialogContent>
</Dialog>
```

### Card Structure

```tsx
<Card>
    <CardHeader>
        <CardTitle>Title</CardTitle>
        <CardDescription>Optional description</CardDescription>
    </CardHeader>
    <CardContent>
        {/* Main content */}
    </CardContent>
    <CardFooter>
        {/* Optional actions */}
    </CardFooter>
</Card>
```

### Forms with Validation

For forms requiring client-side validation, use react-hook-form with Zod:

```tsx
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { Form, FormField, FormItem, FormLabel, FormControl, FormMessage } from '@/components/ui/form';

const form = useForm({
    resolver: zodResolver(schema),
    defaultValues: { ... },
});

<Form {...form}>
    <form onSubmit={form.handleSubmit(onSubmit)}>
        <FormField
            control={form.control}
            name="fieldName"
            render={({ field }) => (
                <FormItem>
                    <FormLabel>Label</FormLabel>
                    <FormControl>
                        <Input {...field} />
                    </FormControl>
                    <FormMessage />
                </FormItem>
            )}
        />
    </form>
</Form>
```

### Form Error Messages

**Preferred:** Use shadcn/ui `FormMessage` with react-hook-form and Zod validation:

```tsx
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { Form, FormField, FormItem, FormLabel, FormControl, FormMessage } from '@/components/ui/form';
import { loginSchema, type LoginInput } from '@/lib/validations/user';

const form = useForm<LoginInput>({
    resolver: zodResolver(loginSchema),
    defaultValues: { email: '', password: '' },
});

<Form {...form}>
    <form onSubmit={form.handleSubmit(onSubmit)}>
        <FormField
            control={form.control}
            name="email"
            render={({ field }) => (
                <FormItem>
                    <FormLabel>Email</FormLabel>
                    <FormControl>
                        <Input {...field} />
                    </FormControl>
                    <FormMessage />
                </FormItem>
            )}
        />
    </form>
</Form>
```

**Handling Inertia Server Errors:** When using react-hook-form with Inertia, merge server-side errors into the form state:

```tsx
import { router } from '@inertiajs/react';

const onSubmit = (data: LoginInput) => {
    router.post('/login', data, {
        onError: (errors) => {
            Object.entries(errors).forEach(([key, message]) => {
                form.setError(key as keyof LoginInput, { message });
            });
        },
    });
};
```

### GFZ Branding Colors

Use the CSS custom properties for GFZ branding:

```tsx
// Background
className="bg-gfz-primary"

// Text
className="text-gfz-primary-foreground"

// Combined (e.g., badges)
className="bg-gfz-primary text-gfz-primary-foreground"

// With hover state
className="bg-gfz-primary hover:bg-gfz-primary/90"
```

Defined in `resources/css/app.css`:
- `--gfz-primary: #0C2A63` (GFZ dark blue)
- `--gfz-primary-foreground: #ffffff`

### Forbidden Patterns

❌ **Do NOT use:**
- Native `<button>` elements – use `<Button>` component
- Manual `{processing && <Spinner />}` in buttons – use `<LoadingButton loading={processing}>` from `@/components/ui/loading-button`
- Inline `animate-spin` – use `<Spinner>` component
- Custom modal implementations – use `<Dialog>` or `<AlertDialog>`
- Direct Radix imports – use wrappers from `components/ui/`
- Inline `style={{ backgroundColor: '#...' }}` – use Tailwind classes or CSS variables
- `bg-[#hexcode]` for repeated colors – define CSS variables instead
- `React.forwardRef` / `.displayName` – use v4 function components with `ref` prop instead
- `@radix-ui/react-*` imports – use `radix-ui` unified package instead

### Loading States

```tsx
// Inline loading (buttons) – use LoadingButton
import { LoadingButton } from '@/components/ui/loading-button';
<LoadingButton loading={processing}>Save</LoadingButton>

// Content placeholder loading
<Skeleton className="h-4 w-[200px]" />

// Reusable skeleton components
import { TableSkeleton } from '@/components/ui/skeletons/table-skeleton';
import { CardSkeleton } from '@/components/ui/skeletons/card-skeleton';
import { FormSkeleton } from '@/components/ui/skeletons/form-skeleton';
import { StatSkeleton } from '@/components/ui/skeletons/stat-skeleton';
import { FilterSkeleton } from '@/components/ui/skeletons/filter-skeleton';

// Full-page loading
<div className="flex items-center justify-center p-8">
    <Spinner size="lg" />
</div>
```

### Page Transitions & Animations

The main layouts (`app-sidebar-layout`, `portal-layout`, `public-layout`, `auth-layout`) include `<PageTransition>` (fade, ~200ms) and `useNProgress()` (progress bar).
Animation constants are centralized in `resources/js/lib/animations.ts`.
All animations respect `prefers-reduced-motion` via the `useReducedMotion()` hook.

### Feedback Strategy (Toast-Centric)

| Scenario | Feedback Type | Example |
|----------|--------------|---------|
| Field validation error | **Inline** (`<FormMessage>`) | "Email is required" |
| Form submission success | **Toast success** | `feedback.saved('Resource')` |
| Form submission error | **Toast error** + inline | `feedback.error(msg)` + `form.setError()` |
| Destructive action prompt | **AlertDialog** | "Delete this resource?" |
| Destructive action done | **Toast success** | `feedback.deleted('Resource')` |
| Background job started | **Toast info** | `feedback.importStarted('DataCite')` |
| Network/session error | **Toast error** | `feedback.sessionExpired()` |

Use `feedback.*` helpers from `@/lib/feedback.ts` for consistent toast messages.

### Validation Schemas

Zod schemas are located in `resources/js/lib/validations/`:
- `user.ts` – User-related forms (createUser, login, password reset, updatePassword, updateProfile, deleteAccount)

Available schemas:
| Schema | Purpose |
|--------|---------|
| `loginSchema` | Login form |
| `forgotPasswordSchema` | Password reset request |
| `resetPasswordSchema` | Setting new password |
| `confirmPasswordSchema` | Password confirmation |
| `welcomePasswordSchema` | Welcome/activation form |
| `updatePasswordSchema` | Settings password change |
| `updateProfileSchema` | Settings profile update |
| `deleteAccountSchema` | Account deletion confirmation |
| `createUserSchema` | Admin user creation |

Add new schemas following the pattern:
```tsx
export const mySchema = z.object({
    field: z.string().min(1, 'Field is required'),
});

export type MyInput = z.infer<typeof mySchema>;
```

## External Integrations

- **DataCite API:** DOI registration (test/production modes via `config/datacite.php`)
- **ORCID API:** Author validation (`OrcidController`, rate-limited at 30 requests/minute)
- **ROR API:** Institution lookup (`RorAffiliationController`, `RorLookupService` singleton)
- **NASA GCMD:** Controlled vocabularies for science keywords, instruments, platforms
- **B2INST PID:** Instrument registry for physical instruments (via `config/b2inst.php`)
- **MSL (Multi-Scale Laboratories):** Laboratory and keyword vocabularies (via `config/msl.php`)
- **SPDX:** License list synchronization

## IGSN (Physical Samples) Support

### Overview
IGSN (International Generic Sample Number) is used for identifying physical samples like rock cores, sediment samples, etc. IGSNs are stored as Resources with `resource_type = PhysicalObject` and extended metadata in `igsn_metadata` table.

### Key Components
- **Controller:** `IgsnController.php` – List/delete IGSNs with sorting/pagination
- **Upload Controller:** `UploadIgsnCsvController.php` – CSV upload with validation
- **Parser Service:** `IgsnCsvParserService.php` – Parse pipe-delimited CSV files
- **Storage Service:** `IgsnStorageService.php` – Store IGSNs with all relations
- **Model:** `IgsnMetadata.php` – IGSN-specific fields (sample_type, material, depth, etc.)
- **Frontend:** `resources/js/pages/igsns/index.tsx` – IGSN list with DataTable

### CSV Format
- Pipe-delimited (`|`) with headers in first row
- Required fields: `igsn`, `title`, `name`
- Multi-value fields use semicolon (`;`) separator
- Supports hierarchical parent-child relationships via `parent_igsn` field

### Validation Rules
- IGSNs must be **globally unique** (enforced by database constraint on `resources.doi`)
- Duplicate IGSNs in upload are rejected with 422 status
- Required field validation before storage

### Status Workflow
IGSNs have upload status tracking via `IgsnMetadata::STATUS_*` constants:
- `pending` → `uploaded` → `validating` → `validated` → `registering` → `registered`
- `error` state for failures with error message

### Database
- `igsn_metadata` table with 1:1 relationship to `resources`
- Parent-child hierarchy via `parent_resource_id` (uses `nullOnDelete` – children persist when parent deleted)

## File Naming Conventions

- PHP: PascalCase (`ResourceController.php`, `UserRole.php`)
- React components: kebab-case (`datacite-form.tsx`, `app-sidebar.tsx`)
- Test files: `.test.ts/.tsx` (Vitest), `.spec.ts` (Playwright)

## Changelog Management

The changelog is displayed at route `/changelog` and powered by:
- **Data source:** `resources/data/changelog.json` – Array of release objects
- **API endpoint:** `GET /api/changelog` → `ChangelogController@index`
- **Frontend:** `resources/js/pages/changelog.tsx` – Animated timeline with Framer Motion

### Adding Changelog Entries

**IMPORTANT:** When implementing new features, improvements, or bug fixes, you MUST add an entry to `resources/data/changelog.json`. This is a mandatory step before completing any task that modifies application functionality.

Format for entries:

```json
{
    "version": "1.0.0",
    "date": "YYYY-MM-DD",
    "features": [{ "title": "...", "description": "..." }],
    "improvements": [{ "title": "...", "description": "..." }],
    "fixes": [{ "title": "...", "description": "..." }]
}
```

- **features**: New functionality
- **improvements**: Enhancements to existing features
- **fixes**: Bug fixes

Add new releases at the **top** of the array. Include only the relevant categories (all three are optional).

## User Documentation

The user-facing documentation is located at route `/docs` and implemented in:
- **Page:** `resources/js/pages/docs.tsx` – React component with role-based sections
- **Components:** `resources/js/components/docs/` – Reusable documentation components (DocsSection, WorkflowSteps, DocsCodeBlock)

### Updating User Documentation

**IMPORTANT:** When implementing new user-facing features, you MUST update the documentation in `docs.tsx`. This includes:
- New workflow capabilities (e.g., DOI validation, new form fields)
- Changed user interactions or UI elements
- New administrative commands or settings
- Updates to existing features that change user behavior

Documentation sections are role-based (`minRole` property). Ensure new content is visible to the appropriate user roles:
- `beginner`: All users can see
- `curator`: Curators and above
- `group_leader`: Group leaders and admins
- `admin`: Admins only

## Test Suite Guidelines

### General Test Strategy

| Test Type | Purpose | When to Run |
|-----------|---------|-------------|
| **Pest** | Backend logic, API endpoints, services | After PHP changes |
| **Pest Browser** | E2E user workflows (preferred) | Before commits/PRs |
| **Vitest** | React components, hooks, utilities | After frontend changes |
| **Playwright (JS)** | Legacy E2E tests, stage bug reproduction | Only for existing tests or stage issues |

### Pest Browser Tests (Recommended for E2E)

✅ **Preferred approach for new E2E tests.** Uses `pestphp/pest-plugin-browser` v4.2+.

Benefits:
- Write E2E tests in PHP with full Laravel integration
- Use `RefreshDatabase`, factories, and seeders directly
- Single unified test command: `composer test`
- Playwright runs under the hood (headless by default)

### Playwright Stage Tests (JavaScript)

⚠️ **Important:** Run Playwright tests against the stage environment (`playwright.stage.config.ts`) **only** when:
- Explicitly asked to reproduce a bug reported from stage
- Verifying a stage-specific issue that cannot be reproduced locally
- The user specifically requests stage testing

For regular development, always use Pest Browser tests or the local Playwright config (`playwright.config.ts`).

### Test Commands Summary

```bash
# Backend (Pest)
npm run test:php                             # Fast default path
npm run test:php:mysql-sensitive             # MySQL-backed driver-sensitive slice
npm run check:backend                        # Pest plus PHPStan

# Frontend (Vitest)
npm run test:run                              # One-shot run
npm run check:frontend                        # ESLint plus TS plus Vitest

# E2E (Legacy Playwright - JS)
npm run test:e2e:devstack                     # Against Docker devstack
npm run check:parity                          # Parity profile plus MySQL slice plus Playwright
npm run test:e2e:stage                        # Stage - only for bug reproduction
```

### Test Organization

- `tests/pest/Feature/` – Backend feature tests (HTTP, services, controllers)
- `tests/pest/Unit/` – Backend unit tests (models, support, enums)
- `tests/pest/Arch/` – Architecture tests (dependency rules)
- `tests/pest/Browser/` – Pest Browser E2E tests (PHP + Playwright)
- `tests/pest/Datasets/` – Shared test datasets
- `tests/pest/Helpers/` – Test helper utilities
- `tests/vitest/` – React component tests (components/, hooks/, layouts/, lib/, pages/, schemas/, services/, types/, utils/)
- `tests/playwright/` – Legacy JS Playwright tests (critical/, accessibility/, workflows/, stage/)

## OpenAPI Documentation

**MANDATORY:** The file `resources/data/openapi.json` contains the OpenAPI 3.2 specification for all public API endpoints. This documentation **MUST** be kept in sync with the actual API routes.

When making API changes, update `resources/data/openapi.json` accordingly:
- **New endpoints:** Add path entry with summary, description, security (if ELMO), request/response schemas
- **Removed endpoints:** Remove the corresponding path entry
- **Changed endpoints:** Update description, parameters, request body, or response schema
- **New response models:** Add schema to `components/schemas`

All entries must follow the existing patterns (tags, `$ref` for schemas, `ElmoApiKey` security for `/elmo` endpoints, `UnauthorizedError` response reference).

## Pre-Completion Checklist

⚠️ **MANDATORY:** Before completing any PHP code changes, always run PHPStan to ensure static analysis passes:

```bash
npm run phpstan:check
```

For the standard backend validation loop, prefer:

```bash
npm run check:backend
```

PHPStan is configured at **level 8** (strictest). All errors must be resolved before considering a task complete. This applies to:
- New PHP files (Controllers, Services, Models, etc.)
- Modifications to existing PHP code
- Refactoring or bug fixes in backend code

**CI/CD Integration (Backend):** PHPStan runs automatically in the GitHub Actions workflow. If PHPStan fails locally, the PR workflow will also fail. Always verify locally before pushing.

⚠️ **MANDATORY:** Before completing any frontend code changes in TypeScript, JavaScript, TSX, JSX, or frontend tests, always run ESLint and resolve all reported issues:

```bash
npm run lint:check
```

For the standard frontend validation loop, prefer:

```bash
npm run check:frontend
```

This applies to:
- React components and pages in `resources/js/`
- frontend hooks, utilities, schemas, and types
- Vitest test files in `tests/vitest/`
- any other JavaScript or TypeScript files touched by the change

**CI/CD Integration (Frontend):** ESLint runs automatically in CI for frontend changes. If ESLint fails locally, the PR workflow can also fail. Always verify locally before pushing frontend updates.

Do NOT skip these steps or mark a task as complete if any required local check reports errors.

## Code Review Guidelines

⚠️ **MANDATORY:** When receiving code review feedback, **always evaluate each point for validity** before implementing:

### Evaluation Criteria

1. **Security Issues** → Usually valid, prioritize fixes (e.g., SQL injection, XSS, enumeration attacks)
2. **Contradicts Project Guidelines** → Reject and explain (e.g., suggesting `React.forwardRef` / `displayName` for new UI components when the shadcn/ui v4 pattern uses `ref` as a regular prop)
3. **Best Practice Suggestions** → Evaluate effort vs. benefit
4. **Overengineering** → Reject if complexity outweighs benefit for current scope
5. **Already Implemented** → Point to existing implementation

### Response Format

Before implementing, provide analysis table:

| # | Punkt | Valide? | Begründung |
|---|-------|---------|------------|
| 1 | Description | ✅/❌/⚠️ | Reasoning |

Then implement only the valid points.
