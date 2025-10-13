# Vorschlag: Test-Reorganisation und Beschleunigung

## Aktuelle Situation (Analyse)

### Teststruktur
- **Pest PHP Tests**: ~70 Dateien (Unit + Feature Tests)
- **Vitest Tests**: 27 Dateien (TypeScript/React Unit Tests)
- **Playwright Tests**: 14 Dateien (E2E Tests)

### Identifizierte Probleme

#### 1. **Redundanzen zwischen Testebenen**
- Viele Funktionen werden auf mehreren Ebenen getestet (Unit, Integration, E2E)
- **Beispiel**: `old-datasets` Filter/Sortierung werden getestet in:
  - `tests/pest/Unit/OldDatasetSortingTest.php` (Unit)
  - `tests/pest/Unit/OldDatasetControllerFiltersTest.php` (Unit)
  - `tests/pest/Feature/OldDatasetControllerTest.php` (Feature - auskommentiert wegen Mockery-Problemen)
  - `tests/playwright/old-datasets.spec.ts` (E2E)
  - `tests/vitest/old-datasets-sorting.test.ts` (Frontend Unit)

#### 2. **Playwright Tests zu granular**
- Separate Dateien für `old-datasets-authors.spec.ts`, `old-datasets-contributors.spec.ts`, `old-datasets-dates.spec.ts`, `old-datasets-descriptions.spec.ts`
- Jede Datei startet eigene Browser-Session und führt Login durch
- Sehr zeitintensiv, da Browser-Setup für jeden Test wiederholt wird

#### 3. **GitHub Workflow Probleme**
- Playwright läuft mit Matrix Strategy (3 Browser) = jeder Test 3x
- Tests laufen sequenziell statt parallel wo möglich
- Keine Test-Selektion basierend auf geänderten Dateien
- DB-Setup wird für jeden Workflow komplett neu aufgebaut

#### 4. **Strukturelle Probleme**
- Mockery-Probleme in CI (siehe `OldDatasetControllerTest.php`)
- Viele `.skip()` Tests in Playwright
- Keine klare Trennung zwischen: Smoke Tests, Integration Tests, E2E Tests

---

## Vorgeschlagene Lösung

### Philosophie: Testing Pyramid optimiert anwenden

```
           /\           Playwright E2E (Workflows)
          /  \          - Wenige, wichtige User Journeys
         /____\         - ~8-10 kritische Pfade
        /      \        
       /  Pest  \       Pest Feature Tests (Integration)
      /  Feature \      - API Endpoints
     /____________\     - Controller Logik mit DB
    /              \    
   /   Unit Tests   \   Pest Unit + Vitest (Schnell, isoliert)
  /  (Pest + Vitest) \  - Business Logik
 /____________________\ - Helper Functions, Transformer, etc.
```

---

## Detaillierte Reorganisation

### 1. **Neue Test-Ordnerstruktur**

```
tests/
├── Pest.php (unverändert)
│
├── pest/
│   ├── Unit/                    # Schnelle, isolierte Tests
│   │   ├── Helpers/             # Test Helpers & Factories
│   │   ├── Models/              # Model Unit Tests
│   │   ├── Services/            # Service Layer Tests
│   │   ├── Transformers/        # Data Transformer Tests
│   │   └── Validators/          # Validation Logic Tests
│   │
│   ├── Feature/                 # Integration Tests (mit DB)
│   │   ├── Api/                 # API Endpoint Tests
│   │   │   ├── Vocabularies/    # GCMD, ROR, Languages, etc.
│   │   │   ├── Resources/       # Resource API Tests
│   │   │   └── Elmo/            # ELMO-spezifische APIs
│   │   │
│   │   ├── Auth/                # Authentication Flow Tests
│   │   ├── Resources/           # Resource CRUD Tests
│   │   ├── OldDatasets/         # Legacy Dataset Tests
│   │   ├── XmlUpload/           # XML Upload & Processing
│   │   ├── Commands/            # Artisan Commands
│   │   └── Settings/            # User Settings Tests
│   │
│   └── TestCase.php
│
├── vitest/
│   ├── components/              # React Component Tests
│   │   ├── curation/            # Curation Form Components
│   │   ├── resources/           # Resource Display Components
│   │   └── shared/              # Shared UI Components
│   │
│   ├── hooks/                   # React Hooks Tests
│   ├── lib/                     # Library & Utility Tests
│   ├── pages/                   # Page-Level Tests
│   └── config/                  # Config Tests
│
└── playwright/
    ├── workflows/               # **NEU**: End-to-End User Journeys
    │   ├── 01-authentication.spec.ts
    │   ├── 02-old-datasets-workflow.spec.ts
    │   ├── 03-xml-upload-workflow.spec.ts
    │   ├── 04-curation-workflow.spec.ts
    │   ├── 05-resources-management.spec.ts
    │   └── 06-settings-workflow.spec.ts
    │
    ├── critical/                # **NEU**: Smoke Tests (schnell)
    │   └── smoke.spec.ts        # Kritische Pfade: Login, Dashboard, Navigation
    │
    ├── helpers/                 # Test Helpers & Page Objects
    │   ├── page-objects/        # Page Object Models
    │   └── fixtures/            # Test Fixtures
    │
    └── constants.ts             # Test Konstanten
```

---

### 2. **Playwright Test-Konzept überarbeitet**

#### **Neue Workflow-Struktur** (Beispiel: `02-old-datasets-workflow.spec.ts`)

```typescript
test.describe('Old Datasets: Complete User Workflow', () => {
  test.beforeEach(async ({ page }) => {
    // Login nur einmal pro Describe-Block
    await loginAsTestUser(page);
  });

  test('Complete workflow: Browse → Filter → Sort → Load to Curation', async ({ page }) => {
    await test.step('Navigate to old datasets', async () => {
      await page.goto('/old-datasets');
      await expect(page.getByRole('heading', { name: 'Old Datasets' })).toBeVisible();
    });

    await test.step('Apply filters', async () => {
      await page.getByLabel('Resource Type').selectOption('Dataset');
      await page.getByLabel('Status').selectOption('published');
      // Verify filtered results appear
      await expect(page.getByTestId('dataset-table')).toBeVisible();
    });

    await test.step('Sort by publication year', async () => {
      await page.getByRole('columnheader', { name: 'Year' }).click();
      // Verify sort order
      const firstYear = await page.locator('[data-testid="dataset-year"]').first().textContent();
      expect(Number(firstYear)).toBeGreaterThan(2020);
    });

    await test.step('Load authors from dataset', async () => {
      await page.getByRole('button', { name: 'Load Authors' }).first().click();
      await expect(page).toHaveURL(/\/curation/);
      // Verify authors populated in curation form
      await expect(page.getByLabel('Last name').first()).not.toBeEmpty();
    });

    await test.step('Load dates from dataset', async () => {
      await page.goto('/old-datasets');
      await page.getByRole('button', { name: 'Load Dates' }).first().click();
      await expect(page).toHaveURL(/\/curation/);
      // Verify dates populated
    });

    await test.step('Load descriptions from dataset', async () => {
      await page.goto('/old-datasets');
      await page.getByRole('button', { name: 'Load Descriptions' }).first().click();
      await expect(page).toHaveURL(/\/curation/);
    });
  });
});
```

#### **Kritische Smoke Tests** (`critical/smoke.spec.ts`)
- Nur kritischste Pfade
- Laufen vor allen anderen Tests
- Maximal 2-3 Minuten Laufzeit
- Bei Fehler: Stop gesamte Pipeline

```typescript
test.describe('Critical Smoke Tests', () => {
  test('User can login and access dashboard', async ({ page }) => {
    // ...
  });

  test('Main navigation works', async ({ page }) => {
    // ...
  });

  test('Can create new resource with minimal data', async ({ page }) => {
    // ...
  });
});
```

---

### 3. **Test-Zuordnung nach Testing Pyramid**

#### **Unit Tests** (Pest Unit + Vitest) - 60% der Tests
**Ziel**: Schnell, isoliert, keine DB, keine Browser

| Was wird getestet | Framework | Beispiel |
|-------------------|-----------|----------|
| PHP Business Logic | Pest Unit | `OldDatasetKeywordTransformer`, `BooleanNormalizer` |
| PHP Helpers | Pest Unit | `GcmdUriHelper` |
| Datenvalidierung | Pest Unit | `StoreResourceRequestControlledKeywordsTest` |
| React Components | Vitest | Curation Form Components, Buttons, Inputs |
| React Hooks | Vitest | `useRorAffiliations`, `useMobile` |
| TypeScript Utils | Vitest | `nameParser`, `contributors`, `utils` |

#### **Integration Tests** (Pest Feature) - 30% der Tests
**Ziel**: API-Tests, Controller mit echter DB

| Was wird getestet | Beispiel |
|-------------------|----------|
| API Endpoints | `GcmdScienceKeywordsApiTest`, `LanguageApiTest` |
| CRUD Operations | `ResourceControllerTest` (mit DB) |
| XML Upload & Processing | `UploadXmlControllerTest` |
| Auth Flows | `AuthenticationTest`, `PasswordResetTest` |
| Artisan Commands | `GetRorIdsCommandTest` |

#### **E2E Tests** (Playwright) - 10% der Tests
**Ziel**: Kritische User Journeys, Cross-Browser

| Workflow | Umfang |
|----------|--------|
| Authentication | Login, Logout, Password Reset |
| Old Datasets | Browse → Filter → Sort → Load to Form |
| XML Upload | Upload → Parse → Populate Form → Save |
| Curation | Create Resource with all field types |
| Resources | List → Edit → Delete |
| Settings | Update Profile, Change Password |

---

### 4. **Redundanzen eliminieren**

#### **Eliminieren**:
- ❌ Einzelne Playwright-Dateien pro Feature-Detail (authors, dates, contributors, descriptions)
  - **Ersetzen durch**: 1 Workflow-Datei die den gesamten Flow testet
  
- ❌ Doppelte Filter/Sort Tests auf Unit + E2E Ebene
  - **Behalten**: Unit Test für Logik, 1 E2E Test für Integration
  
- ❌ Auskommentierte Tests (`OldDatasetControllerTest.php`)
  - **Refactoren**: Controller mit Dependency Injection statt statische Methoden

#### **Konsolidieren**:
```
Vorher (14 Playwright-Dateien):
- login.spec.ts
- debug-login.spec.ts  
- login-success.spec.ts
- old-datasets.spec.ts
- old-datasets-authors.spec.ts
- old-datasets-contributors.spec.ts
- old-datasets-dates.spec.ts
- old-datasets-dates-mocked.spec.ts
- old-datasets-descriptions.spec.ts
- curation-authors.spec.ts
- curation-titles.spec.ts
- curation-controlled-vocabularies.spec.ts
- xml-upload.spec.ts
- ror-affiliations.spec.ts

Nachher (8 Workflow-Dateien + 1 Smoke):
✅ critical/smoke.spec.ts (schnell, kritisch)
✅ workflows/01-authentication.spec.ts
✅ workflows/02-old-datasets-workflow.spec.ts (konsolidiert 5 Dateien)
✅ workflows/03-xml-upload-workflow.spec.ts
✅ workflows/04-curation-workflow.spec.ts (konsolidiert 3 Dateien)
✅ workflows/05-resources-management.spec.ts
✅ workflows/06-settings-workflow.spec.ts
✅ workflows/07-vocabularies-integration.spec.ts (ROR, GCMD)
✅ helpers/page-objects/ (shared logic)
```

**Einsparung**: ~50% weniger Playwright Tests, ~60% weniger Browser-Starts

---

### 5. **GitHub Workflow Optimierungen**

#### **A. Parallele Ausführung optimieren**

```yaml
# .github/workflows/tests.yml (Pest)
jobs:
  pest-unit:  # Schnell, keine DB
    runs-on: ubuntu-latest
    steps:
      - # ... setup
      - name: Run Unit Tests
        run: ./vendor/bin/pest --testsuite=Unit --parallel

  pest-feature:  # Langsamer, mit DB
    runs-on: ubuntu-latest
    needs: pest-unit  # Erst wenn Unit Tests grün
    steps:
      - # ... setup mit DB
      - name: Run Feature Tests
        run: ./vendor/bin/pest --testsuite=Feature --parallel
```

#### **B. Playwright: Critical First**

```yaml
# .github/workflows/playwright.yml
jobs:
  smoke-tests:
    name: Smoke Tests (Critical)
    runs-on: ubuntu-latest
    steps:
      - # ... setup
      - name: Run Smoke Tests
        run: npx playwright test tests/playwright/critical --project=chromium
      # Nur Chromium für Smoke Tests
  
  workflow-tests:
    name: Workflow Tests (${{ matrix.browser }})
    needs: smoke-tests  # Nur wenn Smoke Tests grün
    strategy:
      matrix:
        browser: [chromium, firefox, webkit]
    steps:
      - # ... setup
      - name: Run Workflow Tests
        run: npx playwright test tests/playwright/workflows --project=${{ matrix.browser }}
```

#### **C. Test-Selektion basierend auf geänderten Dateien**

```yaml
# Nur betroffene Tests laufen
- name: Detect changes
  id: changes
  uses: dorny/paths-filter@v2
  with:
    filters: |
      backend:
        - 'app/**'
        - 'routes/**'
        - 'database/**'
      frontend:
        - 'resources/js/**'
        - 'resources/css/**'

- name: Run Backend Tests
  if: steps.changes.outputs.backend == 'true'
  run: ./vendor/bin/pest

- name: Run Frontend Tests
  if: steps.changes.outputs.frontend == 'true'
  run: npm test
```

#### **D. Caching optimieren**

```yaml
# Cache für Playwright Browsers
- name: Cache Playwright Browsers
  uses: actions/cache@v4
  with:
    path: ~/.cache/ms-playwright
    key: ${{ runner.os }}-playwright-${{ hashFiles('package-lock.json') }}

# Cache für Composer Dependencies
- name: Cache Composer
  uses: actions/cache@v4
  with:
    path: vendor
    key: ${{ runner.os }}-composer-${{ hashFiles('composer.lock') }}
```

---

### 6. **Erwartete Beschleunigung**

| Metrik | Vorher | Nachher | Verbesserung |
|--------|--------|---------|--------------|
| **Playwright Tests** | 14 Dateien | 9 Dateien | -36% Dateien |
| **Browser-Starts** | ~42 (14×3) | ~24 (8×3) | -43% |
| **Login-Vorgänge** | ~42 | ~9 | -79% |
| **Parallele Pest** | Sequenziell | Parallel | ~2-3x schneller |
| **Smoke → Stop Early** | Nein | Ja | Fehler früher erkannt |
| **Geschätzte Gesamtzeit** | ~25-30 Min | ~10-15 Min | **~50% schneller** |

---

## Implementierungsplan

### Phase 1: Vorbereitung (1-2 Tage)
- [ ] Testabdeckung dokumentieren (was wird wo getestet)
- [ ] Page Object Models erstellen für Playwright
- [ ] Test Helpers konsolidieren

### Phase 2: Pest Tests reorganisieren (2-3 Tage) ✅ COMPLETED
- [x] Unit Tests in Unterordner verschieben (7 Ordner: Config, Controllers, Models, Seeders, Services, Transformers, Validators)
- [x] Feature Tests in Unterordner gruppieren (7 Ordner: Api, Auth, Commands, OldDatasets, Resources, Settings, XmlUpload)
- [x] Redundante Tests identifizieren und entfernen (3 Tests gelöscht)
- [x] phpunit.xml verifiziert (bereits optimal)
- [ ] `OldDatasetControllerTest` refactoren (Mockery → DI) - DEFERRED (zu komplex für diese Phase)

**Ergebnis:** 214 Unit Tests + 152 Feature Tests alle bestanden

### Phase 3: Playwright Tests neu schreiben (3-4 Tage) ✅ COMPLETED
- [x] Critical Smoke Tests implementieren (4 Tests)
- [x] Workflow 01: Authentication (7 Tests)
- [x] Workflow 02: Old Datasets (10 Tests - konsolidiert 5 alte Tests)
- [x] Workflow 03: XML Upload (8 Tests)
- [x] Workflow 04: Curation (10 Tests - konsolidiert 3 alte Tests)
- [x] Workflow 05: Resources Management (10 Tests - NEU, füllt Test-Lücke)
- [x] Workflow 06: Settings (13 Tests)
- [ ] Workflow 07: Vocabularies - NOT NEEDED (abgedeckt durch andere Workflows)

**Ergebnis:** 62 Playwright Tests, 14 → 9 Dateien (36% Reduktion), 43% weniger Browser-Starts

**Details:** Siehe [docs/PHASE_3_SUMMARY.md](docs/PHASE_3_SUMMARY.md)

### Phase 4: GitHub Workflows optimieren (1-2 Tage)
- [ ] Pest Workflow aufteilen (Unit → Feature)
- [ ] Playwright Workflow aufteilen (Smoke → Workflows)
- [ ] Parallele Ausführung konfigurieren
- [ ] Caching optimieren
- [ ] Change Detection implementieren (optional)

### Phase 5: Alte Tests entfernen (1 Tag)
- [ ] Alte Playwright-Dateien löschen
- [ ] Debug/Mocked Tests entfernen
- [ ] Auskommentierte Tests löschen
- [ ] Dokumentation aktualisieren

**Gesamt: ~8-12 Tage Implementierung**

---

## Risiken & Mitigation

| Risiko | Wahrscheinlichkeit | Mitigation |
|--------|-------------------|------------|
| Testabdeckung sinkt | Mittel | Coverage Reports vorher/nachher vergleichen |
| Neue Tests finden Bugs nicht | Niedrig | Schrittweise Migration, alte Tests erst löschen wenn neue grün |
| Playwright Workflows zu komplex | Mittel | Kleine, fokussierte test.step() verwenden |
| CI Pipeline bricht | Niedrig | Feature Branch testen vor Merge |

---

## Erfolgsmetriken

Nach Implementierung sollten folgende Verbesserungen messbar sein:

✅ **Geschwindigkeit**
- GitHub Actions Laufzeit: <15 Minuten (vorher ~25-30 Min)
- Lokale Pest Tests: <2 Minuten (Unit), <5 Minuten (Feature)

✅ **Wartbarkeit**
- Weniger Test-Dateien (-30%)
- Klare Struktur (Unit/Feature/E2E)
- Keine auskommentierten Tests

✅ **Zuverlässigkeit**
- Keine Mockery-Fehler mehr
- Keine flaky Tests durch `.skip()`
- Schnelleres Feedback durch Smoke Tests

✅ **Testabdeckung**
- Code Coverage: ≥80% (unverändert oder besser)
- Alle User Journeys abgedeckt
- Kritische Pfade mehrfach getestet

---

## Nächste Schritte

Wenn dieser Vorschlag akzeptiert wird:

1. **Review & Feedback**: Änderungswünsche besprechen
2. **Feature Branch**: `test/streamline-all-tests` (existiert bereits)
3. **Schrittweise Implementierung**: Nach Phasenplan
4. **Pull Request**: Mit detaillierter Beschreibung der Änderungen

---

## Fragen zur Diskussion

1. Sind die vorgeschlagenen Workflow-Kombinationen sinnvoll?
2. Sollen weitere Tests konsolidiert werden?
3. Priorität: Geschwindigkeit vs. Testabdeckung vs. Wartbarkeit?
4. Soll Change Detection implementiert werden?

