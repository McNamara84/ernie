# Implementation Plan: Chronostrat Keywords (Issue #557)

## Overview

Add **International Chronostratigraphic Chart (ICS)** keywords as a new thesaurus vocabulary to ERNIE/ELMO. This enables researchers to tag datasets with geological time periods (e.g., "Jurassic", "Cretaceous", "Holocene") using the official ICS vocabulary.

**Data Source:** [ARDC Linked Data API](https://vocabs.ardc.edu.au/repository/api/lda/csiro/international-chronostratigraphic-chart/geologic-time-scale-2020/concept.json) (Geologic Time Scale 2020)

## Design Decisions

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Data source | ARDC Linked Data API | Paginiert, JSON, offizieller Linked Data Endpunkt |
| Concepts | Only intervals (no boundaries) | Boundaries sind keine typischen Subject-Keywords |
| Hierarchy depth | All 5 levels (Eon > Era > Period > Epoch > Age) | Maximale Flexibilität für Forscher |
| Subject scheme | `International Chronostratigraphic Chart` | Offizieller ICS-Name |
| Scheme URI | `http://resource.geosciml.org/vocabulary/timescale/gts2020` | Offizieller URI des Vokabulars |
| Tab name | `Chronostratigraphy` | Wissenschaftlich korrekt |
| Languages | English only | Konsistent mit GCMD |
| Scope | Complete (Phanerozoic + Precambrian) | Vollständigkeit |

## Data Structure

### Hierarchy (5 levels)

```
Phanerozoic (Eon)
├── Cenozoic (Era)
│   ├── Quaternary (Period)
│   │   ├── Holocene (Epoch)
│   │   │   ├── Meghalayan (Age)
│   │   │   ├── Northgrippian (Age)
│   │   │   └── Greenlandian (Age)
│   │   └── Pleistocene (Epoch)
│   │       ├── ... (Ages)
│   ├── Neogene (Period)
│   │   └── ...
│   └── Paleogene (Period)
│       └── ...
├── Mesozoic (Era)
│   ├── Cretaceous (Period)
│   ├── Jurassic (Period)
│   └── Triassic (Period)
└── Paleozoic (Era)
    └── ...
Precambrian (Supereon)
├── Proterozoic (Eon)
├── Archean (Eon)
└── Hadean (Eon)
```

### ARDC API Response Format

```json
{
  "result": {
    "items": [
      {
        "_about": "http://resource.geosciml.org/classifier/ics/ischart/Aalenian",
        "broader": ["http://resource.geosciml.org/classifier/ics/ischart/MiddleJurassic"],
        "narrower": [...],
        "notation": "a1.1.2.2.2.4",
        "prefLabel": [
          {"_value": "Aalenian", "_lang": "en"},
          {"_value": "Aalénium", "_lang": "de"},
          ...
        ]
      }
    ],
    "itemsPerPage": 10,
    "next": "...?_page=1",
    "page": 0
  }
}
```

### Target JSON Format (stored in `storage/app/chronostrat-timescale.json`)

Same hierarchical format as GCMD vocabularies:

```json
{
  "lastUpdated": "2026-03-09 12:00:00",
  "data": [
    {
      "id": "http://resource.geosciml.org/classifier/ics/ischart/Phanerozoic",
      "text": "Phanerozoic",
      "language": "en",
      "scheme": "International Chronostratigraphic Chart",
      "schemeURI": "http://resource.geosciml.org/vocabulary/timescale/gts2020",
      "description": "",
      "children": [
        {
          "id": "http://resource.geosciml.org/classifier/ics/ischart/Cenozoic",
          "text": "Cenozoic",
          "language": "en",
          "scheme": "International Chronostratigraphic Chart",
          "schemeURI": "http://resource.geosciml.org/vocabulary/timescale/gts2020",
          "description": "",
          "children": [...]
        }
      ]
    }
  ]
}
```

## Implementation Tasks

### Phase 1: Backend – Model & Database

#### 1.1 Add Chronostrat constant to `ThesaurusSetting` model

**File:** `app/Models/ThesaurusSetting.php`

- Add `TYPE_CHRONOSTRAT = 'chronostratigraphy'` constant
- Update `getFilePath()` → return `'chronostrat-timescale.json'`
- Update `getArtisanCommand()` → return `'get-chronostrat-timescale'`
- Add new method `getRemoteApiUrl()` or update `getVocabularyType()` for non-NASA types
- Update `getValidTypes()` to include `TYPE_CHRONOSTRAT`

**Note:** Since Chronostrat uses ARDC API (not NASA KMS), the `getVocabularyType()` method is not needed for this type. The `ThesaurusStatusService` will need a separate code path for Chronostrat update checks.

#### 1.2 Update `ThesaurusSettingSeeder`

**File:** `database/seeders/ThesaurusSettingSeeder.php`

Add new entry:
```php
[
    'type' => ThesaurusSetting::TYPE_CHRONOSTRAT,
    'display_name' => 'ICS Chronostratigraphy',
],
```

#### 1.3 Create database migration

**File:** `database/migrations/YYYY_MM_DD_XXXXXX_seed_chronostrat_thesaurus_setting.php`

A simple migration that calls the seeder to insert the new thesaurus setting row (uses `firstOrCreate` so it's safe to run multiple times).

---

### Phase 2: Backend – Artisan Command

#### 2.1 Create `GetChronostratTimescale` artisan command

**File:** `app/Console/Commands/GetChronostratTimescale.php`

This command **cannot** extend `BaseGcmdCommand` because the ARDC API format differs from NASA KMS. It will be a standalone command.

**API Details:**
- **Base URL:** `https://vocabs.ardc.edu.au/repository/api/lda/csiro/international-chronostratigraphic-chart/geologic-time-scale-2020/concept.json`
- **Pagination:** `?_pageSize=200&_page=0` (up to ~250 concepts total, so 2 pages with pageSize=200)
- **Format:** JSON (not RDF/XML)

**Logic:**
1. Fetch all pages from ARDC API
2. Filter out boundary concepts (URIs containing `Base` prefix in the concept name, e.g., `BaseMiddleJurassic`, `BaseBajocian`)
3. Extract English labels only (`_lang === "en"`)
4. Build parent-child hierarchy using `broader` relationships
5. Set `scheme = "International Chronostratigraphic Chart"` and `schemeURI = "http://resource.geosciml.org/vocabulary/timescale/gts2020"`
6. Save as `storage/app/chronostrat-timescale.json`
7. Clear vocabulary caches

**Boundary filtering approach:**
- Boundaries have URIs like `http://resource.geosciml.org/classifier/ics/ischart/BaseMiddleJurassic`
- Boundaries have labels starting with `"Base of ..."` or containing `"GSSP"` / `"Stratigraphic point"`
- Filter via: if `prefLabel` (en) starts with `"Base of "` → skip

#### 2.2 Create `ChronostratVocabularyParser` support class

**File:** `app/Support/ChronostratVocabularyParser.php`

Responsible for:
- Parsing ARDC Linked Data API JSON responses
- Extracting English labels from multi-language `prefLabel` arrays
- Building hierarchical tree from flat `broader`/`narrower` relationships
- Filtering out boundary/GSSP concepts
- Counting total concepts

---

### Phase 3: Backend – Services

#### 3.1 Update `ThesaurusStatusService`

**File:** `app/Services/ThesaurusStatusService.php`

- `getRemoteConceptCount()`: Add branch for `TYPE_CHRONOSTRAT` that queries ARDC API instead of NASA KMS
  - ARDC API concept count: Paginate through `concept.json?_pageSize=1&_page=0` and read `totalResults` or count manually
  - Alternative: Fetch page 0 with `_pageSize=1` and extract total from response metadata
- `compareWithRemote()`: Works generically, no changes needed
- `getLocalStatus()`: Works generically via `getFilePath()`, no changes needed

**ARDC concept count approach:**
The ARDC API doesn't provide a direct `totalHits` like NASA KMS. Options:
1. Fetch first page and estimate from items (unreliable)
2. Fetch all pages and count (expensive for just a check)
3. **Recommended:** Store concept count in the JSON file metadata (`"conceptCount": 123`) during the fetch command, then compare local count with a known reference or simply offer manual update trigger

**Recommended approach:** For Chronostrat, skip the automatic remote count check (the ICS vocabulary changes very rarely – once every few years) and just provide a manual "Re-download" button. The `compareWithRemote` method can return `updateAvailable: false` for Chronostrat by default, while still allowing manual re-download.

#### 3.2 Update `VocabularyCacheService`

No changes needed – the service already handles generic vocabulary caching via `CacheKey`.

---

### Phase 4: Backend – Controller & Routes

#### 4.1 Update `VocabularyController`

**File:** `app/Http/Controllers/VocabularyController.php`

Add new method:
```php
public function chronostratTimescale(): JsonResponse
{
    if (!$this->isThesaurusActive(ThesaurusSetting::TYPE_CHRONOSTRAT)) {
        return response()->json(['error' => 'Thesaurus is disabled'], 404);
    }

    return $this->getCachedVocabulary(
        CacheKey::CHRONOSTRAT_TIMESCALE,
        'chronostrat-timescale.json',
        'php artisan get-chronostrat-timescale'
    );
}
```

#### 4.2 Add cache key

**File:** `app/Enums/CacheKey.php`

Add:
```php
case CHRONOSTRAT_TIMESCALE = 'vocabularies:chronostrat:timescale';
```

Update `ttl()` and `tags()` match arms to include the new case.

#### 4.3 Add routes

**File:** `routes/web.php`
```php
Route::get('vocabularies/chronostrat-timescale', [VocabularyController::class, 'chronostratTimescale'])
    ->name('vocabularies.chronostrat-timescale');
```

**File:** `routes/api.php`
```php
Route::middleware('ernie.api-key')->get('/v1/vocabularies/chronostrat-timescale', [VocabularyController::class, 'chronostratTimescale']);
```

#### 4.4 Update `UpdateThesaurusJob`

**File:** `app/Jobs/UpdateThesaurusJob.php`

- Update `getArtisanCommand()` match to include `TYPE_CHRONOSTRAT => 'get-chronostrat-timescale'`
- Update progress message for Chronostrat (not "NASA KMS API" but "ARDC API")

---

### Phase 5: Frontend – Types & State

#### 5.1 Update TypeScript types

**File:** `resources/js/types/gcmd.ts`

- Add `'chronostrat'` to `GCMDVocabularyType` union type:
  ```ts
  export type GCMDVocabularyType = 'science' | 'platforms' | 'instruments' | 'msl' | 'chronostrat';
  ```
- Update `getVocabularyTypeFromScheme()`:
  ```ts
  if (normalized.includes('chronostratigraphic')) return 'chronostrat';
  ```
- Update `getSchemeFromVocabularyType()`:
  ```ts
  case 'chronostrat': return 'International Chronostratigraphic Chart';
  ```
- Update `GCMDVocabularies` interface to add `chronostrat`:
  ```ts
  export interface GCMDVocabularies {
      science: GCMDVocabulary;
      platforms: GCMDVocabulary;
      instruments: GCMDVocabulary;
      chronostrat: GCMDVocabulary;
  }
  ```

#### 5.2 Update `EditorProps` and `DataCiteFormProps`

**File:** `resources/js/pages/editor.tsx`

- Add `chronostratKeywords` to `EditorProps` (same format as `gcmdKeywords`)

**File:** `resources/js/components/curation/types/datacite-form-types.ts`

- Add `initialChronostratKeywords` to `DataCiteFormProps`

#### 5.3 Update `EditorController` to pass chronostrat keywords

**File:** `app/Http/Controllers/EditorController.php`

- Add `'chronostratKeywords'` to XML session required array keys
- Pass `chronostratKeywords` in all 4 editor render paths (XML session, old dataset, resource, query params)

#### 5.4 Update `EditorDataTransformer`

**File:** `app/Services/Editor/EditorDataTransformer.php`

- Add `transformChronostratKeywords()` method (filter subjects by scheme `International Chronostratigraphic Chart`)
- Or: The existing `transformGcmdKeywords()` already includes ALL subjects with a scheme, so Chronostrat keywords are already included. The frontend separates them by `scheme` name. **No backend transformer changes needed** if we use the same `gcmdKeywords` prop for all controlled keywords including Chronostrat.

**Important:** After analysis, the current architecture stores **all** controlled keywords (GCMD + MSL) in the same `gcmdKeywords` prop. The frontend uses `getVocabularyTypeFromScheme()` to sort them into the correct tabs. So **no changes needed** to EditorController or EditorDataTransformer – Chronostrat keywords will automatically flow through the existing `gcmdKeywords` channel if stored with `subject_scheme = "International Chronostratigraphic Chart"`.

---

### Phase 6: Frontend – Controlled Vocabularies Field

#### 6.1 Update `ControlledVocabulariesField`

**File:** `resources/js/components/curation/fields/controlled-vocabularies-field.tsx`

Changes:
- Add `chronostratVocabulary` prop (type `GCMDKeyword[]`)
- Add `showChronostratTab` visibility control via `enabledThesauri`
- Update `ThesauriAvailability` interface:
  ```ts
  interface ThesauriAvailability {
      science_keywords: boolean;
      platforms: boolean;
      instruments: boolean;
      chronostratigraphy: boolean;
  }
  ```
- Add `Chronostratigraphy` tab:
  ```tsx
  {showChronostratTab && (
      <TabsTrigger value="chronostrat" className="relative">
          Chronostratigraphy
          {hasKeywords('chronostrat') && (
              <span className="ml-1 inline-block h-2 w-2 rounded-full bg-green-500" />
          )}
      </TabsTrigger>
  )}
  ```
- Update grid column calculation to handle 5 tabs
- Add `chronostrat` case to `currentKeywords` switch for tree rendering

#### 6.2 Update `DataCiteForm`

**File:** `resources/js/components/curation/datacite-form.tsx`

- Fetch Chronostrat vocabulary from `/vocabularies/chronostrat-timescale`
- Pass `chronostratVocabulary` data to `ControlledVocabulariesField`
- Update thesauri availability fetch to include `chronostratigraphy`

---

### Phase 7: Frontend – Settings

#### 7.1 No changes needed in settings UI

The **ThesaurusCard** component and **Editor Settings page** (`settings/index.tsx`) already render thesaurus settings dynamically from the `thesauri` array. Adding a new `ThesaurusSetting` row in the database will automatically:

- Show the new "ICS Chronostratigraphy" row in the Thesaurus Settings section
- Provide ERNIE/ELMO enable/disable checkboxes
- Allow "Check for Updates" and "Update Now" functionality

The only requirement is that `ThesaurusSettingsController` and `ThesaurusStatusService` support the new type (covered in Phase 3).

---

### Phase 8: Backend – Storage (storeSubjects)

#### 8.1 No changes needed in `ResourceStorageService`

The `storeSubjects()` method already handles all controlled keywords generically. Chronostrat keywords will be submitted as part of the `gcmdKeywords` array (with `scheme = "International Chronostratigraphic Chart"`) and stored correctly in the `subjects` table.

---

### Phase 9: API Documentation

#### 9.1 Update API documentation

**File:** `routes/api.php` (route comments)

Add documentation for the new endpoint:
```
GET /api/v1/vocabularies/chronostrat-timescale
```

**File:** Update the API doc controller/page if one exists to document the new endpoint.

---

### Phase 10: DataCite XML/JSON Export

#### 10.1 Verify export compatibility

**Files:**
- `app/Services/DataCiteXmlExporter.php`
- `app/Services/DataCiteJsonExporter.php`

Chronostrat keywords are stored as `Subject` records with:
- `value` = keyword text (e.g., "Jurassic")
- `subject_scheme` = "International Chronostratigraphic Chart"
- `scheme_uri` = "http://resource.geosciml.org/vocabulary/timescale/gts2020"
- `value_uri` = concept URI (e.g., "http://resource.geosciml.org/classifier/ics/ischart/Jurassic")

These will be exported correctly by the existing DataCite exporters which iterate over all subjects. **No changes needed.**

---

### Phase 11: Documentation & Changelog

#### 11.1 Update changelog

**File:** `resources/data/changelog.json`

Add entry at the top:
```json
{
    "version": "1.0.0rc3",
    "date": "2026-03-XX",
    "features": [
        {
            "title": "Chronostratigraphic Keywords",
            "description": "Added support for the International Chronostratigraphic Chart (ICS) as a new thesaurus vocabulary. Researchers can now tag datasets with geological time periods (Eon, Era, Period, Epoch, Age) from the official ICS timescale."
        }
    ]
}
```

#### 11.2 Update user documentation

**File:** `resources/js/pages/docs.tsx`

Add section describing:
- What Chronostratigraphic Keywords are
- How to select them in the editor
- How admins can enable/disable them

#### 11.3 Update copilot instructions

**File:** `.github/copilot-instructions.md`

Add Chronostrat references where GCMD is mentioned.

---

### Phase 12: Testing

#### 12.1 Pest PHP Tests

| Test File | Description |
|-----------|-------------|
| `tests/pest/Feature/Commands/GetChronostratTimescaleTest.php` | Test artisan command: API mocking, JSON output, hierarchy building |
| `tests/pest/Unit/Support/ChronostratVocabularyParserTest.php` | Test parser: label extraction, hierarchy, boundary filtering |
| `tests/pest/Feature/Controllers/VocabularyControllerChronostratTest.php` | Test API endpoint with enabled/disabled states |
| `tests/pest/Feature/Settings/ThesaurusSettingsChronostratTest.php` | Test enable/disable Chronostrat thesaurus |

#### 12.2 Vitest Tests

| Test File | Description |
|-----------|-------------|
| `tests/vitest/components/curation/fields/controlled-vocabularies-field-chronostrat.test.tsx` | Test new tab rendering and visibility |
| `tests/vitest/types/gcmd-chronostrat.test.ts` | Test type helpers for Chronostrat scheme mapping |

---

## File Change Summary

### New Files (7)

| # | File | Description |
|---|------|-------------|
| 1 | `app/Console/Commands/GetChronostratTimescale.php` | Artisan command to fetch ICS vocabulary |
| 2 | `app/Support/ChronostratVocabularyParser.php` | Parser for ARDC Linked Data API JSON |
| 3 | `database/migrations/YYYY_seed_chronostrat_setting.php` | Migration to seed new thesaurus setting |
| 4 | `tests/pest/Feature/Commands/GetChronostratTimescaleTest.php` | Command tests |
| 5 | `tests/pest/Unit/Support/ChronostratVocabularyParserTest.php` | Parser tests |
| 6 | `tests/pest/Feature/Controllers/VocabularyControllerChronostratTest.php` | API endpoint tests |
| 7 | `tests/pest/Feature/Settings/ThesaurusSettingsChronostratTest.php` | Settings tests |

### Modified Files (14)

| # | File | Changes |
|---|------|---------|
| 1 | `app/Models/ThesaurusSetting.php` | Add `TYPE_CHRONOSTRAT`, update match expressions |
| 2 | `database/seeders/ThesaurusSettingSeeder.php` | Add chronostrat seed row |
| 3 | `app/Services/ThesaurusStatusService.php` | Add ARDC remote count logic for chronostrat |
| 4 | `app/Http/Controllers/VocabularyController.php` | Add `chronostratTimescale()` method |
| 5 | `app/Enums/CacheKey.php` | Add `CHRONOSTRAT_TIMESCALE` cache key |
| 6 | `app/Jobs/UpdateThesaurusJob.php` | Add chronostrat to artisan command match |
| 7 | `routes/web.php` | Add vocabulary route |
| 8 | `routes/api.php` | Add API route |
| 9 | `resources/js/types/gcmd.ts` | Add `chronostrat` type, helpers |
| 10 | `resources/js/components/curation/fields/controlled-vocabularies-field.tsx` | Add Chronostratigraphy tab |
| 11 | `resources/js/components/curation/datacite-form.tsx` | Fetch chronostrat vocabulary |
| 12 | `resources/data/changelog.json` | Add changelog entry |
| 13 | `resources/js/pages/docs.tsx` | Add user documentation |
| 14 | `.github/copilot-instructions.md` | Update references |

### No Changes Needed (verified)

| File | Reason |
|------|--------|
| `app/Http/Controllers/EditorController.php` | Chronostrat keywords flow through existing `gcmdKeywords` channel |
| `app/Services/Editor/EditorDataTransformer.php` | All controlled keywords with a scheme already included |
| `app/Services/ResourceStorageService.php` | Generic controlled keyword storage already handles new schemes |
| `app/Services/DataCiteXmlExporter.php` | Generic subject export already handles new schemes |
| `app/Services/DataCiteJsonExporter.php` | Generic subject export already handles new schemes |
| `resources/js/pages/settings/index.tsx` | Settings UI renders thesauri dynamically from database |
| `resources/js/components/settings/thesaurus-card.tsx` | Already generic, works for any thesaurus type |

## Implementation Order

```
Phase 1  → Model & Database (ThesaurusSetting, Migration, Seeder)
Phase 2  → Artisan Command + Parser (GetChronostratTimescale, ChronostratVocabularyParser)
Phase 3  → Services (ThesaurusStatusService)
Phase 4  → Controller & Routes (VocabularyController, CacheKey, web.php, api.php, UpdateThesaurusJob)
Phase 5  → Frontend Types (gcmd.ts)
Phase 6  → Frontend Editor (controlled-vocabularies-field.tsx, datacite-form.tsx)
Phase 7  → Settings (automatic – no changes)
Phase 8  → Storage (automatic – no changes)
Phase 9  → API Documentation
Phase 10 → Export (automatic – no changes)
Phase 11 → Documentation & Changelog
Phase 12 → Testing
```

## Acceptance Criteria Mapping (from Issue #557)

| Criterion | Implementation |
|-----------|---------------|
| ✅ Chronostrat keywords are provided like other vocabularies | Phase 1-4: Same architecture as GCMD |
| ✅ API that can be requested from ELMO | Phase 4: `/api/v1/vocabularies/chronostrat-timescale` with API key |
| ✅ Keyword control is consistent with implemented keywords | Phase 5-6: Same tab pattern, same tree component, same storage |
| ✅ API documentation added | Phase 9: Route documentation |
| ✅ Changelog updated | Phase 11: `changelog.json` entry |
| ✅ User documentation added | Phase 11: `docs.tsx` section |
