# Refactoring Plan — God-Controller Split + Form Request Migration

**Branch:** `chore/refactoring`
**Scope:** Vorschläge 1 + 2 (siehe Maintainability-Audit)
**Goal:** Drastisch verbesserte Wartbarkeit von `UploadXmlController` (2.319 LOC) und `ResourceController` (1.300 LOC) sowie projektweite Konsolidierung der HTTP-Eingangsvalidierung auf Form Requests. Backend-konventionskonform nach Laravel 13, PHP 8.5, PHPStan Level 8.

> **Backward Compatibility:** Kleine Änderungen am API-Response-Shape sind erlaubt (konsistentere Naming-Konvention via API Resources). Frontend wird im selben PR angepasst.

---

## 1. Ziele & Erfolgskriterien

| Kriterium | Soll-Wert |
|-----------|-----------|
| `UploadXmlController` LOC | < 200 (reiner HTTP-Entry-Point) |
| `ResourceController` LOC | < 250 |
| Methoden mit `Illuminate\Http\Request` als Eingang | **0** projektweit (außer wo semantisch sinnvoll, z. B. `Auth\AuthenticatedSessionController::destroy`) |
| Inline `$request->validate([…])`-Aufrufe in Controllern | **0** |
| PHPStan | Level 8, keine neuen Errors, keine neue Baseline |
| Test-Coverage (neue/geänderte Lines) | ≥ 75 % (Codecov-Gate) |
| Pest-Suite (alle Tests inkl. Browser) | grün |
| Vitest-Suite | grün |
| Wayfinder TS-Routen | regeneriert, FE kompiliert ohne TS-Errors |
| API-Compat | Konsumenten (FE-Hooks/Komponenten + Wayfinder-Aufrufe) im selben PR migriert |

---

## 2. Zielarchitektur

### 2.1 Controller-Split — `ResourceController`

| Neuer Controller | Methoden | Routen (URL bleibt!) |
|---|---|---|
| `ResourceController` | `index`, `store`, `storeDraft`, `destroy`, `destroyAll` | `GET /resources`, `POST /editor/resources`, `POST /editor/resources/draft`, `DELETE /resources/{resource}`, `DELETE /resources/all` |
| `ResourceFilterController` | `loadMore`, `getFilterOptions` | `GET /resources/load-more`, `GET /resources/filter-options` |
| `ResourceExportController` | `exportDataCiteJson`, `exportDataCiteXml`, `exportJsonLd` | `GET /resources/{resource}/export-datacite-json`, `…-xml`, `…-jsonld` |
| `ResourceDoiRegistrationController` | `registerDoi`, `getDataCitePrefixes` | `POST /resources/{resource}/register-doi`, `GET /api/datacite/prefixes` |

> Begründung: Funktionale Kohäsion, jede Klasse hat einen klaren Verantwortungsbereich. URLs bleiben unverändert → keine Breaking Changes für externe Consumer.

### 2.2 Service-Layer für `UploadXmlController`

```
app/Services/Xml/
├── DataCiteXmlImportParser.php          # Orchestrator (façade)
├── DataCiteXmlImportResult.php          # readonly DTO (final result)
└── Sections/
    ├── AuthorSectionParser.php
    ├── ContributorSectionParser.php
    ├── DescriptionSectionParser.php
    ├── DateSectionParser.php
    ├── CoverageSectionParser.php
    ├── KeywordSectionParser.php
    ├── RelatedItemSectionParser.php
    ├── RelatedWorkAndInstrumentSectionParser.php
    ├── FundingReferenceSectionParser.php
    ├── IdentifierSectionParser.php       # DOI / version / language / publicationYear
    └── IsoContactSectionParser.php       # ISO 19115 contact info
app/Support/Xml/
├── XmlElementHelpers.php                 # childElements, firstChildElement, scalarChild, localName
└── XmlValueExtractor.php                 # extractFirstStringFromQuery, …
```

- Orchestrator nimmt einen `XmlReader` entgegen und ruft die Sub-Parser sequentiell auf, wobei Output von `AuthorSectionParser` + `ContributorSectionParser` (ContactPersons) in `IsoContactSectionParser`-Output zusammengeführt wird (wie aktuell in Zeile ~135 ff.).
- Jeder Sub-Parser ist eine `final readonly class` mit `parse(XmlReader|Element $input): array` (oder typed DTO).
- `UploadXmlController` reduziert sich auf:
  ```php
  public function __invoke(UploadXmlRequest $request): JsonResponse
  {
      $result = $this->importParser->parse(
          $request->validated('file')->get(),
          $request->validated('file')->getClientOriginalName(),
      );
      $this->uploadLogService->logSuccess('xml', $result->filename);
      return response()->json($result->toArray());
  }
  ```
- Fehlerpfade über typed Exceptions (`XmlImportException` mit `UploadErrorCode`-Mapping), gefangen in einem schlanken `try/catch`-Block oder `app/Exceptions/Handler.php`.

### 2.3 Eloquent API Resources

```
app/Http/Resources/
├── ResourceResource.php
├── ResourceListItemResource.php          # leichtgewichtige Variante für index/loadMore
├── ResourceCollection.php                # mit pagination meta
├── FilterOptionsResource.php             # für getFilterOptions
└── DataCitePrefixResource.php
```

`serializeResource()` (109 LOC) wird komplett von `ResourceListItemResource` ersetzt. Alle JSON-Responses des refactorten Scopes laufen über API Resources.

### 2.4 Form Requests — projektweite Migration

Aktuell mit `Illuminate\Http\Request`:

**Eigener Scope (Pflicht-Migration):**
| Controller@Methode | Neuer Form Request |
|---|---|
| `ResourceController@index` | `IndexResourcesRequest` |
| `ResourceController@destroy` | `DestroyResourceRequest` |
| `ResourceController@destroyAll` | `DestroyAllResourcesRequest` |
| `ResourceFilterController@loadMore` | `LoadMoreResourcesRequest` |
| `ResourceExportController@*` | `ExportResourceRequest` (Single, gateway via Policy) |

**Projektweiter Scope (alle anderen `Request $request`):**

Aus dem Audit: 40+ Stellen. Migration in *einem dedizierten Commit pro Controller-Cluster*:

- `Auth/*` (5 Methoden) → `Auth/*Request` (z. B. `LogoutRequest`, `ResendEmailVerificationRequest`)
- `Api/DataCiteController@getCitation`, `@getAuthors` → `GetCitationRequest`, `GetAuthorsRequest`
- `Api/CitationLookupController` → `CitationLookupRequest`
- `Assistance*` → `IndexAssistanceRequest`, `CheckAssistanceRequest`, `StatusAssistanceRequest`, `AcceptSuggestionRequest`, `DeclineSuggestionRequest`
- `BatchIgsnController`, `BatchIgsnRegistrationController`, `BatchResourceExportController`, `BatchResourceRegistrationController` → je `Batch*Request`
- `ContactMessageController@store`, `@storeDraft`, `processContactMessage` → `StoreContactMessageRequest`
- `Datacenter`, `DoiValidation`, `IgsnImport`, `LandingPage*`, `RelatedItem`, `Settings/Thesaurus*` → analog

**Form Request Pattern (verbindlich):**
```php
final class IndexResourcesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Resource::class) ?? false;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'page'           => ['integer', 'min:1'],
            'per_page'       => ['integer', 'min:1', 'max:100'],
            'sort_key'       => ['string', Rule::in(self::ALLOWED_SORT_KEYS)],
            'sort_direction' => ['string', Rule::in(['asc', 'desc'])],
            'filters.*'      => ['array'],
            // …
        ];
    }

    /** Typed accessor — array shape for PHPStan strict. */
    /** @return array{page:int, perPage:int, sortKey:string, sortDirection:string, filters:array<string,mixed>} */
    public function toCriteria(): array { /* … */ }
}
```

Vorteile: Authorization, Validation und Daten-Extraction landen am richtigen Ort; Controller-Methoden werden zu reinen Orchestratoren. PHP 8.5 array shapes machen `$request->toCriteria()` typed-safe.

### 2.5 Architektur-Tests (Pest Arch)

Neu in `tests/pest/Arch/HttpLayerTest.php`:

```php
arch('controllers do not type-hint generic Request')
    ->expect('App\Http\Controllers')
    ->not->toUse(Illuminate\Http\Request::class)
    ->ignoring([
        // dokumentierte Ausnahmen
        Auth\AuthenticatedSessionController::class.'::destroy',
    ]);

arch('controllers do not call $request->validate()')
    ->expect('App\Http\Controllers')->not->toUse('Illuminate\Foundation\Http\FormRequest::validate');

arch('xml section parsers are final readonly')
    ->expect('App\Services\Xml\Sections')->toBeFinal()->toBeReadonly();

arch('controllers under 250 LOC')
    ->expect('App\Http\Controllers')
    ->classes()->toHaveLineCountLessThan(250); // soft fail with allow-list initially
```

---

## 3. Implementierungsphasen

> Jede Phase ist ein logischer Commit. Tests werden **innerhalb** ihrer Phase mitgeschrieben (nicht am Ende). PHPStan + Pest laufen nach jeder Phase grün.

### Phase 0 — Vorbereitung
- [ ] Branch ist bereits `chore/refactoring`. Sub-Branch nicht nötig.
- [ ] Baseline-Run: `composer test`, `npm run test`, `./vendor/bin/phpstan` notieren.
- [ ] PHPStan-Baseline einfrieren mit `phpstan analyse --generate-baseline=phpstan-baseline-pre-refactor.neon` (nur lokal, **nicht** committen) als Vergleich.
- [ ] Coverage-Baseline notieren (Codecov-Report main branch).

### Phase 1 — XML Import: Helper-Extraction
- [ ] `app/Support/Xml/XmlElementHelpers.php` — übernimmt `childElements`, `firstChildElement`, `scalarChild`, `localName`, `containsElements`, `stringOrNull`, `intOrNull` (Zeilen 690–800 von `UploadXmlController`).
- [ ] `app/Support/Xml/XmlValueExtractor.php` — übernimmt `extractFirstStringFromQuery`, `extractFirstElementFromQuery`.
- [ ] `UploadXmlController` ruft sie als statische Methoden / injizierte Services auf.
- [ ] **Tests:** `tests/pest/Unit/Support/Xml/XmlElementHelpersTest.php` (gegen XML-Fixtures aus `tests/pest/Datasets/`), Coverage 100 % für die neuen Helpers.
- [ ] PHPStan grün.

### Phase 2 — XML Section Parsers
Pro Sub-Parser ein eigener Commit. Jeweils:
1. Klasse erstellen (`final readonly class …SectionParser`).
2. Logik aus `UploadXmlController` 1:1 übernehmen, Public API: `parse(XmlReader $reader): array` oder `parse(Element $element): ?array`.
3. Unit-Test gegen Fixture-XMLs unter `tests/pest/Datasets/Xml/` (existierende DataCite-Beispiele wiederverwenden).
4. Controller delegiert, alte `private`-Methode löschen.

Reihenfolge (nach Komplexität, einfach zuerst):
- [ ] `IdentifierSectionParser` (DOI, year, version, language)
- [ ] `DescriptionSectionParser`
- [ ] `DateSectionParser` + `CoverageSectionParser`
- [ ] `KeywordSectionParser`
- [ ] `FundingReferenceSectionParser`
- [ ] `AuthorSectionParser`
- [ ] `ContributorSectionParser` (inkl. MSL Laboratories + ContactPersons)
- [ ] `IsoContactSectionParser`
- [ ] `RelatedItemSectionParser`
- [ ] `RelatedWorkAndInstrumentSectionParser`

Nach jeder Section: `composer test --filter XmlUpload` muss grün bleiben.

### Phase 3 — XML Import Orchestrator
- [ ] `app/Services/Xml/DataCiteXmlImportResult.php` — readonly DTO, `toArray()` für JSON-Response.
- [ ] `app/Services/Xml/DataCiteXmlImportParser.php` — Orchestrator, kombiniert alle Section-Parser. Public API: `parse(string $xml, string $filename): DataCiteXmlImportResult`.
- [ ] Eigene Exception-Hierarchie: `app/Exceptions/XmlImportException.php` mit `fromCode(UploadErrorCode)`.
- [ ] **Tests:**
  - `tests/pest/Unit/Services/Xml/DataCiteXmlImportParserTest.php` (Orchestrator gegen alle Fixtures).
  - Bestehende Feature-Tests in `tests/pest/Feature/XmlUpload/` bleiben unverändert und müssen grün bleiben (Vertrags-Verifikation).
- [ ] `UploadXmlController` reduziert sich auf < 200 Zeilen.
- [ ] **Pest Browser Test** (`tests/pest/Browser/XmlUploadFlowTest.php`): User lädt eine Beispiel-XML im Editor hoch → Editor wird mit den richtigen Feldern befüllt.

### Phase 4 — `ResourceController` Vorbereitung: API Resources
- [ ] `app/Http/Resources/ResourceListItemResource.php` — exakte Re-Implementation von `serializeResource()` (mit gleichem Output-Shape minus erlaubter Konsistenz-Verbesserungen).
  - `assertRelationsLoaded()` als private Method der Resource oder via Observer in dev/testing.
- [ ] `app/Http/Resources/ResourceCollection.php` mit Pagination Meta.
- [ ] `app/Http/Resources/FilterOptionsResource.php`, `DataCitePrefixResource.php`.
- [ ] `serializeResource`, `assertRelationsLoaded`, `determinePublicStatus`, `isResourceComplete` werden zu Methoden auf der Resource-Klasse oder bleiben temporär als private Helper.
- [ ] **Tests:**
  - `tests/pest/Unit/Http/Resources/ResourceListItemResourceTest.php` — Snapshot-Test des Output-Arrays gegen einen Resource-Factory-Aufruf.
  - Bestehende `ResourcesFilterSortTest`, `Performance/ResourceQueryPerformanceTest` müssen grün bleiben (ggf. minimal angepasst).

### Phase 5 — Form Requests für `Resource*`-Scope
- [x] `IndexResourcesRequest`, `LoadMoreResourcesRequest`, `DestroyResourceRequest`, `DestroyAllResourcesRequest`, `ExportResourceRequest` erstellen.
- [ ] Validation-Regeln aus `extractFilters`, `resolveSortState`, inline `validate(['confirmation' => …])` migrieren.
- [ ] **Tests:** `tests/pest/Unit/Http/Requests/IndexResourcesRequestTest.php` (Rules + Authorize), eine Test-Datei pro Request.

### Phase 6 — Controller Split
- [x] `ResourceFilterController` extrahieren (`loadMore`, `getFilterOptions`, plus `applyFilters`, `applySorting`, `extractFilters`, `resolveSortState`, `baseQuery` → in `app/Services/Resources/ResourceQueryBuilder.php`).
  - `ResourceQueryBuilder` ist nun die Single Source für Filter-/Sort-Logik, wiederverwendet in `ResourceController@index`.
- [x] `ResourceExportController` extrahieren (`exportDataCiteJson`, `exportDataCiteXml`, `exportJsonLd`).
- [x] `ResourceDoiRegistrationController` extrahieren (`registerDoi`, `getDataCitePrefixes`).
- [x] `routes/web.php` updaten: nur Action-References austauschen, URLs unverändert.
- [x] Wayfinder regenerieren: `docker exec ernie-app-dev php artisan wayfinder:generate` → TS-Imports unter `resources/js/routes/` aktualisieren.
- [ ] **Tests:** Pro neuem Controller eine Feature-Test-Datei unter `tests/pest/Feature/Http/Controllers/`. Mindestens ein Happy-Path und ein Authorization-Path je Action.
- [ ] Vorhandene Tests, die `app(ResourceController::class)` direkt nutzen (z. B. `Performance/ResourceQueryPerformanceTest`), auf `ResourceQueryBuilder` umbiegen.

### Phase 7 — Frontend-Anpassungen
- [ ] TS-Types unter `resources/js/types/resources.ts` an neue API-Resource-Shape anpassen (sofern abweichend).
- [ ] Wayfinder-Imports in `resources/js/pages/resources.tsx`, `resources/js/components/curation/datacite-form.tsx`, `resources/js/hooks/*.ts`, … aktualisieren (search&replace nach altem Routen-Helper).
- [ ] **Tests:**
  - `npm run types` muss grün laufen.
  - Vitest: ggf. Mocks für API-Calls in `tests/vitest/pages/resources.test.tsx`, `tests/vitest/hooks/*` aktualisieren.
  - Neue Vitest-Tests, wo Snapshot-Shape geändert wurde.

### Phase 8 — Projektweite Form Request Migration
Reihenfolge (nach Risiko, niedrig zuerst):
- [ ] `Auth\*` Controller (klein, gut getestet).
- [ ] `Api\DataCiteController`, `Api\CitationLookupController`, `DoiValidationController`.
- [ ] `Assistance*Controller` (5 Methoden).
- [ ] `Batch*Controller` (4 Controller, je 1 Methode).
- [ ] `ContactMessageController`.
- [ ] `LandingPage*Controller`, `RelatedItemController`, `Datacenter*`, `Settings/ThesaurusSettingsController`, `IgsnController`, `IgsnImportController`.

Pro Controller: Form Request erstellen, Controller umstellen, Tests aktualisieren, neue Tests für Form Request hinzufügen.

### Phase 9 — Architektur-Tests + Cleanup
- [ ] `tests/pest/Arch/HttpLayerTest.php` schreiben (siehe 2.5).
- [ ] Allow-List für Ausnahmen dokumentieren.
- [ ] Ungenutzte Imports/Methoden entfernen, `pint` laufen lassen.
- [ ] CHANGELOG-Eintrag in [resources/data/changelog.json](resources/data/changelog.json) (genau **ein** konsolidierter `improvements`-Eintrag, gemäß User-Memory).
- [ ] OpenAPI ([resources/data/openapi.json](resources/data/openapi.json)) prüfen — falls Response-Shape minimal angepasst, dort spiegeln.
- [ ] User-Doku [resources/js/pages/docs.tsx](resources/js/pages/docs.tsx) prüfen — Endpoints/URLs unverändert, daher voraussichtlich keine Änderung nötig.

### Phase 10 — Final Validation
- [ ] `docker exec ernie-app-dev composer test` grün (inkl. Browser-Tests).
- [ ] `npm run test` grün.
- [ ] `npm run test:e2e` grün (lokale Playwright-Config).
- [ ] `./vendor/bin/phpstan` grün, keine neuen Errors.
- [ ] `./vendor/bin/pint --test` grün.
- [ ] `npm run lint && npm run types` grün.
- [ ] Codecov-Report manuell prüfen: neue Lines ≥ 75 % Coverage.

---

## 4. Test-Strategie im Detail

| Layer | Tool | Was wird abgedeckt |
|---|---|---|
| **Unit** | Pest | Jeder XML-Section-Parser einzeln gegen Fixture-XMLs. Jeder Form Request (Rules + `authorize()`). Jede API Resource (Output-Shape Snapshot). `ResourceQueryBuilder`. |
| **Feature** | Pest | Jeder neue Controller, jede Action: Happy Path, Authorization, Validation-Failure (422). Bestehende `XmlUpload*Test`-Dateien laufen unverändert. |
| **Browser (E2E)** | Pest Browser (Playwright) | (1) XML-Upload → Editor-Befüllung. (2) Resource speichern (Editor → Save → Liste sieht Eintrag). (3) DOI-Registrierung (Fake-Service). |
| **Frontend** | Vitest | Hooks/Components, deren API-Shape sich ändert: `resources.tsx`, betroffene Hooks. Snapshot-Updates wo nötig. |
| **Architektur** | Pest Arch | Form-Request-Pflicht in Controllers; `Request $request` verboten; Sub-Parser final/readonly; LOC-Soft-Limit. |

**Coverage-Strategie für 75 %-Gate:**
- Jeder neue Service/Resource/FormRequest braucht einen Unit-Test.
- Edge Cases (leeres XML, fehlende DOI, ungültige Sort-Keys, fehlende Berechtigung) explizit testen.
- `phpstan` array shapes auf Form Request `toCriteria()`-Methoden zwingen Test-Autor zu vollständigen Eingaben → höhere Branch-Coverage.

---

## 5. Risiken & Mitigation

| Risiko | Wahrsch. | Impact | Mitigation |
|---|---|---|---|
| API-Shape-Drift bricht FE | mittel | hoch | Snapshot-Tests der API Resources als Vertragstest; FE im selben PR migrieren; Pest Browser-Test als End-zu-End-Sicherheitsnetz. |
| XML-Parser-Regression (subtile Datenverluste) | mittel | hoch | Jede Section-Parser-Migration ist 1:1-Logik-Übernahme + bestehende Feature-Tests laufen unverändert. Reihenfolge der Section-Aufrufe im Orchestrator exakt wie zuvor. |
| Wayfinder-Generation übersieht Stelle im FE | niedrig | mittel | `npm run types` als Gate; `grep_search` nach altem Routen-Namen vor Merge. |
| Form-Request-Migration ändert Authorization-Verhalten | mittel | hoch | Jede `authorize()`-Methode bekommt einen expliziten Test. Bestehende Policy-Tests (`tests/pest/Feature/Policies/*`) laufen unverändert. |
| PR wird zu groß für Review | hoch | mittel | Phasen sind logische Commits; PR-Beschreibung mit Phasen-Mapping; ggf. interaktiver Walkthrough mit Reviewer. |
| Performance-Regression durch API Resources | niedrig | niedrig | `Performance/ResourceQueryPerformanceTest` muss grün bleiben (N+1-Detection). API Resources nutzen die bereits eager-geloadeten Relations. |

---

## 6. Out of Scope (für diesen PR explizit nicht)

- Vorschlag #3 (Frontend-Komponenten-Split `datacite-form.tsx` etc.) — eigener PR.
- Vorschlag #4 (DataCite-Mapper-Schicht) — eigener PR.
- Vorschlag #5 (umfassende Arch-Tests + ADRs) — minimal-Variante hier (Phase 9), Vollausbau später.
- `UploadJsonController` (1.169 LOC), `OldDataStatisticsController` (1.134 LOC) — Folge-PR mit gleicher Methodik.
- Endpoint-URL-Änderungen / RESTful-Umbau — explizit OUT.

---

## 7. Definition of Done

- [ ] Alle Phasen 0–10 abgehakt.
- [ ] PR-Description enthält Mapping `Alt-Methode → Neue-Klasse`.
- [ ] CHANGELOG-Eintrag (ein konsolidierter `improvements`-Eintrag).
- [ ] `database/er-diagram.md` & `database/er-diagram-plantuml.md` unverändert (kein Schema-Change).
- [ ] OpenAPI-Spec konsistent.
- [ ] CI grün (Pest, Vitest, Playwright lokal, PHPStan, Pint, ESLint, TS).
- [ ] Codecov-Patch ≥ 75 %.
- [ ] PR-Reviewer durchläuft die Phasen-Commits in Reihenfolge — jeder Commit ist atomar grün.
