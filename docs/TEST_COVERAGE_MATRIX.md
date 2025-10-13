# Test Coverage Matrix - Aktuelle Situation

> Dokumentiert am: 13. Oktober 2025
> Zweck: Identifikation von Redundanzen und Testlücken vor der Reorganisation

## Übersicht

| Test-Typ | Anzahl Dateien | Framework | Laufzeit (geschätzt) |
|----------|----------------|-----------|----------------------|
| **Unit Tests** | ~70 | Pest PHP + Vitest | ~2-3 Min (lokal) |
| **Feature Tests** | ~50 | Pest PHP | ~5-8 Min (CI) |
| **E2E Tests** | 14 | Playwright | ~15-20 Min (CI, 3 Browser) |
| **Gesamt** | ~134 | - | **~25-30 Min (CI)** |

---

## Feature-Bereich: Authentication & User Management

### ✅ Was wird getestet

| Feature | Pest Unit | Pest Feature | Vitest | Playwright | Status |
|---------|-----------|--------------|--------|------------|--------|
| Login Flow | - | ✅ `Auth/AuthenticationTest` | ✅ `login-routes.test.ts` | ✅ `login.spec.ts`, `login-success.spec.ts` | 🔴 **Redundant** |
| Password Reset | - | ✅ `Auth/PasswordResetTest` | ✅ `password-routes.test.ts` | - | ✅ Gut abgedeckt |
| Email Verification | - | ✅ `Auth/EmailVerificationTest` | ✅ `verification-routes.test.ts` | - | ✅ Gut abgedeckt |
| Profile Update | - | ✅ `Settings/ProfileUpdateTest` | ✅ `profile-routes.test.ts` | - | ✅ Gut abgedeckt |
| Password Update | - | ✅ `Settings/PasswordUpdateTest` | - | - | ✅ Gut abgedeckt |
| Registration Disabled | - | ✅ `Auth/RegistrationDisabledTest` | - | - | ✅ Gut abgedeckt |

**🔍 Analyse:**
- ❌ **Login wird 3x getestet** (Pest Feature + Vitest + Playwright) - **Redundanz**
- ❌ `debug-login.spec.ts` sollte entfernt werden (Debug-Datei)
- ✅ Password Reset und Email Verification gut auf Unit/Integration-Ebene getestet

---

## Feature-Bereich: Old Datasets (Legacy Database)

### ✅ Was wird getestet

| Feature | Pest Unit | Pest Feature | Vitest | Playwright | Status |
|---------|-----------|--------------|--------|------------|--------|
| **Sortierung** | ✅ `OldDatasetSortingTest` | ⚠️ `OldDatasetControllerTest` (auskommentiert) | ✅ `old-datasets-sorting.test.ts` | - | 🔴 **Redundant** |
| **Filterlogik** | ✅ `OldDatasetFilterLogicTest` | ⚠️ `OldDatasetControllerTest` (auskommentiert) | - | - | ⚠️ Nur Unit |
| **Filter Extraktion** | ✅ `OldDatasetControllerFiltersTest` | ⚠️ `OldDatasetControllerTest` (auskommentiert) | - | - | ⚠️ Nur Unit |
| **Dates Transformation** | ✅ `OldDatasetDatesTest` | ✅ `OldDatasetControllerDatesTest` | ✅ `old-datasets-dates.test.ts` | ✅ `old-datasets-dates.spec.ts` | 🔴 **4x getestet!** |
| **Authors Loading** | - | ✅ `OldDatasetControllerControlledKeywordsTest` | - | ✅ `old-datasets-authors.spec.ts` | ✅ Gut |
| **Contributors Loading** | - | - | - | ⚠️ `old-datasets-contributors.spec.ts` (SKIPPED) | ⚠️ Nicht aktiv |
| **Descriptions Loading** | - | - | - | ✅ `old-datasets-descriptions.spec.ts` | ⚠️ Nur E2E |
| **Keyword Transformation** | ✅ `OldDatasetKeywordTransformerTest` | - | - | - | ✅ Gut |
| **Free Keywords** | ✅ `OldDatasetFreeKeywordsParsingTest` | - | - | - | ✅ Gut |
| **Overview Page** | - | - | - | ✅ `old-datasets.spec.ts` | ⚠️ Nur E2E |

**🔍 Analyse:**
- 🔴 **Dates werden 4x getestet** - massive Redundanz!
  - Unit Test ✅ (behalten)
  - Feature Test ✅ (behalten)
  - Vitest ❌ (entfernen - dupliziert Pest Unit)
  - Playwright + `old-datasets-dates-mocked.spec.ts` → **konsolidieren**
  
- 🔴 **Sortierung wird 2x getestet** (Pest Unit + Vitest) - **Redundanz**
  - Pest Unit ✅ (behalten - testet Controller-Logik)
  - Vitest ❌ (entfernen oder auf Frontend-spezifische Logik reduzieren)

- ⚠️ **Feature Tests auskommentiert** wegen Mockery-Problemen
  - **Handlungsbedarf**: Controller refactoren für Dependency Injection

- ⚠️ **6 separate Playwright-Dateien** für einzelne Old Datasets Features
  - → **Konsolidieren in 1 Workflow-Datei**

---

## Feature-Bereich: XML Upload & Processing

### ✅ Was wird getestet

| Feature | Pest Unit | Pest Feature | Vitest | Playwright | Status |
|---------|-----------|--------------|--------|------------|--------|
| XML Upload Flow | - | ✅ `XmlUploadTest` | - | ✅ `xml-upload.spec.ts` | 🔴 **Redundant** |
| XML Parsing | ✅ `XmlFreeKeywordsExtractionTest` | ✅ `UploadXmlControllerTest` | - | - | ✅ Gut |
| Full Example | - | ✅ `UploadXmlFullExampleTest` | - | - | ✅ Gut |
| Coverage | - | ✅ `UploadXmlCoverageTest` | - | - | ✅ Gut |
| ORCID Normalization | - | ✅ `UploadXmlOrcidNormalizationTest` | ✅ `orcid-website-normalization.test.ts` | - | ✅ Gut abgedeckt |
| Funding Reference | - | ✅ `XmlUploadFundingReferenceTest` | - | ✅ (in `xml-upload.spec.ts`) | 🔴 **Redundant** |

**🔍 Analyse:**
- 🔴 **XML Upload wird 2x getestet** (Pest Feature + Playwright)
  - Pest Feature ✅ (behalten - detaillierte Validierung)
  - Playwright ✅ (behalten - aber reduzieren auf Happy Path)
  
- ✅ XML Parsing gut auf Unit/Feature-Ebene getestet
- ✅ ORCID Normalization gut auf Frontend + Backend getestet

---

## Feature-Bereich: Curation Form

### ✅ Was wird getestet

| Feature | Pest Unit | Pest Feature | Vitest | Playwright | Status |
|---------|-----------|--------------|--------|------------|--------|
| **Authors/Contributors** | - | ✅ `CurationTest` | ✅ `contributors.test.ts` | ✅ `curation-authors.spec.ts` | ✅ Gut abgedeckt |
| **Titles** | - | ✅ `CurationTest` | - | ✅ `curation-titles.spec.ts` | ✅ Gut |
| **Controlled Vocabularies** | ✅ `ResourceControlledKeywordTest` | - | ✅ `controlled-vocabularies.test.ts` | ✅ `curation-controlled-vocabularies.spec.ts` | 🔴 **Redundant** |
| **ROR Affiliations** | - | - | ✅ `use-ror-affiliations.test.ts` | ✅ `ror-affiliations.spec.ts` | ✅ Gut |
| **Language Resolver** | - | - | ✅ `language-resolver.test.ts` | - | ✅ Gut |
| **Name Parser** | ✅ (implizit) | - | ✅ `nameParser.test.ts` | - | ✅ Gut |
| **Multiple Affiliations** | - | - | ✅ `multiple-affiliations-loading.test.ts` | - | ✅ Gut |
| **Curation Query** | - | - | ✅ `curation-query.test.ts` | - | ✅ Gut |

**🔍 Analyse:**
- 🔴 **Controlled Vocabularies 3x getestet** (Unit + Vitest + Playwright)
  - Unit ✅ (behalten - Backend-Validierung)
  - Vitest ✅ (behalten - Frontend-Logik)
  - Playwright → **reduzieren auf Integration-Test**

- ✅ Gute Verteilung zwischen Unit (Logik) und E2E (UI)
- ✅ ROR Affiliations gut auf beiden Ebenen getestet

---

## Feature-Bereich: Resources Management

### ✅ Was wird getestet

| Feature | Pest Unit | Pest Feature | Vitest | Playwright | Status |
|---------|-----------|--------------|--------|------------|--------|
| **Resource CRUD** | - | ✅ `ResourceControllerTest` (1058 Zeilen!) | - | - | ⚠️ Sehr groß |
| **Funding Reference** | - | ✅ `ResourceControllerFundingReferenceTest` | - | - | ✅ Gut |
| **Free Keywords** | ✅ `FreeKeywordsParsingTest` | ✅ `ResourceFreeKeywordsTest` | - | - | ✅ Gut |
| **Coverage** | - | ✅ `ResourceCoverageTest` | - | - | ✅ Gut |
| **Related Work** | - | ✅ `RelatedWorkTest` | - | - | ✅ Gut |
| **Related Identifier** | ✅ `RelatedIdentifierTest` | - | - | - | ✅ Gut |

**🔍 Analyse:**
- ⚠️ **`ResourceControllerTest` ist 1058 Zeilen lang** - sollte aufgeteilt werden
- ✅ Gute Trennung zwischen Unit und Feature Tests
- ❌ **Keine E2E Tests für Resources** - Lücke!

---

## Feature-Bereich: API Endpoints

### ✅ Was wird getestet

| API Endpoint | Pest Unit | Pest Feature | Vitest | Playwright | Status |
|--------------|-----------|--------------|--------|------------|--------|
| **Languages** | ✅ `LanguageSeederTest` | ✅ `LanguageApiTest` | - | - | ✅ Gut |
| **Licenses** | - | ✅ `LicenseApiTest`, `LicenseSeederTest` | - | - | ✅ Gut |
| **Resource Types** | ✅ `ResourceTypeSeederTest` | ✅ `ResourceTypeApiTest`, `AllResourceTypeApiTest`, `ElmoResourceTypeApiTest` | - | - | ✅ Gut |
| **Title Types** | ✅ `TitleTypeSeederTest` | ✅ `TitleTypeApiTest`, `AllTitleTypeApiTest`, `ElmoTitleTypeApiTest` | - | - | ✅ Gut |
| **Roles** | ✅ `RoleSeederTest` | ✅ `RoleControllerTest` | - | - | ✅ Gut |
| **GCMD Science Keywords** | - | ✅ `GcmdScienceKeywordsApiTest` | - | - | ✅ Gut |
| **GCMD Platforms** | - | ✅ `GcmdPlatformsApiTest` | - | - | ✅ Gut |
| **GCMD Instruments** | - | ✅ `GcmdInstrumentsApiTest` | - | - | ✅ Gut |
| **ROR Affiliations** | - | ✅ `RorAffiliationControllerTest` | - | - | ✅ Gut |
| **Changelog** | - | ✅ `ChangelogApiTest` | - | - | ✅ Gut |

**🔍 Analyse:**
- ✅ Alle APIs gut auf Feature-Ebene getestet
- ✅ Seeder Tests sichern Datenbankinitialisierung
- ✅ Keine E2E Tests notwendig (reine API-Endpoints)

---

## Feature-Bereich: Commands & Scheduled Tasks

### ✅ Was wird getestet

| Command | Pest Unit | Pest Feature | Status |
|---------|-----------|--------------|--------|
| **GetRorIds** | - | ✅ `GetRorIdsCommandTest`, `Console/Commands/GetRorIdsTest` | 🔴 **Redundant** (2x) |
| **SyncSpdxLicenses** | - | ✅ `SyncSpdxLicensesCommandTest` | ✅ Gut |
| **Scheduled Tasks** | - | ✅ `ScheduleTest` | ✅ Gut |

**🔍 Analyse:**
- 🔴 **GetRorIds wird 2x getestet** - Redundanz
- ✅ Commands gut auf Feature-Ebene getestet

---

## Feature-Bereich: UI/UX & Utilities

### ✅ Was wird getestet

| Feature | Pest Unit | Pest Feature | Vitest | Playwright | Status |
|---------|-----------|--------------|--------|------------|--------|
| **Wayfinder** | - | - | ✅ `wayfinder.test.ts` | - | ✅ Gut |
| **Routes** | - | - | ✅ `routes.test.ts` | - | ✅ Gut |
| **Base Path** | - | - | ✅ `base-path.test.ts` | - | ✅ Gut |
| **CSRF Token** | - | - | ✅ `csrf-token.test.ts` | - | ✅ Gut |
| **Appearance Hook** | - | ✅ `Settings/AppearanceTest` | ✅ `use-appearance.test.ts` | - | ✅ Gut |
| **Mobile Hook** | - | - | ✅ `use-mobile.test.ts`, `use-mobile-navigation.test.ts` | - | ✅ Gut |
| **Initials Hook** | - | - | ✅ `use-initials.test.ts` | - | ✅ Gut |
| **Utils** | - | - | ✅ `utils.test.ts` | - | ✅ Gut |
| **Version** | - | - | ✅ `version.test.ts` | - | ✅ Gut |
| **Vite Config** | - | - | ✅ `vite-config.test.ts` | - | ✅ Gut |
| **ESLint Config** | - | - | ✅ `eslint-config.test.ts` | - | ✅ Gut |

**🔍 Analyse:**
- ✅ Frontend Utilities gut mit Vitest getestet
- ✅ Keine Redundanzen
- ✅ Gute Unit-Test-Abdeckung

---

## Feature-Bereich: Static Pages & Navigation

### ✅ Was wird getestet

| Feature | Pest Unit | Pest Feature | Vitest | Playwright | Status |
|---------|-----------|--------------|--------|------------|--------|
| **Dashboard** | - | ✅ `DashboardTest` | - | ✅ (in Login Tests) | ✅ Gut |
| **Static Pages** | - | ✅ `StaticPagesTest` | - | - | ✅ Gut |
| **Docs** | - | ✅ `DocsTest` | - | - | ✅ Gut |
| **API Doc Endpoint** | - | ✅ `ApiDocEndpointTest` | - | - | ✅ Gut |

**🔍 Analyse:**
- ✅ Gut auf Feature-Ebene getestet
- ✅ Keine E2E Tests notwendig

---

## Identifizierte Redundanzen (Priorität: Hoch)

### 🔴 Kritische Redundanzen (sofort angehen)

1. **Old Datasets Dates** - 4x getestet!
   - ✅ Behalten: `OldDatasetDatesTest` (Unit)
   - ✅ Behalten: `OldDatasetControllerDatesTest` (Feature)
   - ❌ Entfernen: `vitest/pages/old-datasets-dates.test.ts`
   - ✅ Konsolidieren: Playwright Tests in Workflow

2. **Login** - 3x getestet
   - ✅ Behalten: `Auth/AuthenticationTest` (Feature)
   - ❌ Entfernen: Vitest Routes Tests (duplizieren Pest)
   - ✅ Behalten: 1 Playwright Test (E2E)
   - ❌ Entfernen: `debug-login.spec.ts`, `login-success.spec.ts`

3. **Sortierung Old Datasets** - 2x getestet
   - ✅ Behalten: `OldDatasetSortingTest` (Unit)
   - ❌ Reduzieren: `vitest/old-datasets-sorting.test.ts` (nur Frontend-spezifisch)

4. **Controlled Vocabularies** - 3x getestet
   - ✅ Behalten: `ResourceControlledKeywordTest` (Unit)
   - ✅ Behalten: `vitest/controlled-vocabularies.test.ts` (Frontend)
   - ✅ Reduzieren: Playwright nur Integration-Test

5. **GetRorIds Command** - 2x getestet
   - ✅ Behalten: `GetRorIdsCommandTest`
   - ❌ Entfernen: `Console/Commands/GetRorIdsTest`

### ⚠️ Mittlere Redundanzen

6. **XML Upload** - 2x getestet
   - ✅ Behalten: Pest Feature (detailliert)
   - ✅ Reduzieren: Playwright (nur Happy Path)

7. **Playwright: 6 separate Old Datasets Files**
   - → **Konsolidieren in 1 Workflow-Datei**

---

## Testlücken (Priorität: Mittel)

1. ❌ **Resources Management** - Keine E2E Tests
   - → Workflow-Test hinzufügen: Create, Edit, Delete

2. ⚠️ **Settings** - Nur Feature Tests
   - → Workflow-Test hinzufügen (optional)

3. ⚠️ **Old Datasets Contributors** - Test ist SKIPPED
   - → Entweder aktivieren oder entfernen

---

## Refactoring-Bedarf

1. 🔴 **`OldDatasetControllerTest`** - Auskommentiert wegen Mockery
   - → Controller mit Dependency Injection refactoren

2. ⚠️ **`ResourceControllerTest`** - 1058 Zeilen
   - → In kleinere Test-Klassen aufteilen

---

## Zusammenfassung

### Aktuelle Probleme
- 🔴 **15-20 redundante Tests** identifiziert
- 🔴 **6 Playwright-Dateien** sollten zu 1 konsolidiert werden
- 🔴 **Mockery-Probleme** bei OldDatasetController
- ⚠️ **2-3 Testlücken** bei E2E Tests

### Nach Reorganisation
- ✅ **~20% weniger Tests** (durch Redundanzen-Eliminierung)
- ✅ **~50% weniger Playwright-Dateien**
- ✅ **~60% weniger Browser-Starts**
- ✅ **Klarere Struktur** nach Testing Pyramid

### Geschätzte Zeit-Einsparung
- **Vorher**: ~25-30 Min CI-Laufzeit
- **Nachher**: ~10-15 Min CI-Laufzeit
- **Einsparung**: **~50%**

