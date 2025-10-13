# Playwright Config Fix - Old Tests Ignorieren

## Problem

Der GitHub Workflow lief endlos/hatte Timeouts, weil die `playwright.config.ts` **ALLE** `.spec.ts` Dateien inkl. der alten Tests ausführte.

## Ursache

Die ursprüngliche `testMatch` Konfiguration war zu breit:

```typescript
testMatch: [
  '**/critical/*.spec.ts',
  '**/workflows/*.spec.ts',
  '**/*.spec.ts',  // ← Matcht ALLE Tests, inkl. alte!
],
```

## Lösung

### 1. Präzise testMatch Patterns

```typescript
testMatch: [
  // Nur neue Tests in critical/ und workflows/
  'tests/playwright/critical/**/*.spec.ts',
  'tests/playwright/workflows/**/*.spec.ts',
],
```

### 2. Explizites testIgnore für alte Tests

```typescript
testIgnore: [
  '**/helpers/**',
  '**/page-objects/**',
  '**/*.md',
  '**/constants.ts',
  // ALLE alten Tests auf Root-Level ignorieren
  'tests/playwright/*.spec.ts',
],
```

## Verifikation

```powershell
# Tests auflisten (sollte nur neue Tests zeigen)
npx playwright test --list

# Erwartetes Ergebnis:
# Total: 183 tests in 7 files
# - 4 Critical Smoke Tests
# - 57 Workflow Tests  
# × 3 Browser = 183 Tests
```

## Alte Tests (werden ignoriert)

Diese Dateien existieren noch, werden aber NICHT ausgeführt:

```
tests/playwright/
├── curation-authors.spec.ts              ❌ ignoriert
├── curation-controlled-vocabularies.spec.ts ❌ ignoriert
├── curation-titles.spec.ts               ❌ ignoriert
├── debug-login.spec.ts                   ❌ ignoriert
├── login-success.spec.ts                 ❌ ignoriert
├── login.spec.ts                         ❌ ignoriert
├── old-datasets-authors.spec.ts          ❌ ignoriert
├── old-datasets-contributors.spec.ts     ❌ ignoriert
├── old-datasets-dates-mocked.spec.ts     ❌ ignoriert
├── old-datasets-dates.spec.ts            ❌ ignoriert
├── old-datasets-descriptions.spec.ts     ❌ ignoriert
├── old-datasets.spec.ts                  ❌ ignoriert
├── ror-affiliations.spec.ts              ❌ ignoriert
└── xml-upload.spec.ts                    ❌ ignoriert
```

Diese werden in **Phase 5** gelöscht.

## Auswirkung auf CI/CD

- ✅ Keine alten Tests laufen mehr
- ✅ Keine Timeouts durch hängende alte Tests
- ✅ Nur neue workflow-basierte Tests werden ausgeführt
- ✅ Geschätzte Laufzeit: ~10-15 Min (statt Timeout nach 60 Min)

## Next Steps

1. ✅ Config angepasst
2. ⏳ GitHub Workflow-Durchlauf abwarten
3. ⏳ Verifikation: Alle neuen Tests sollten durchlaufen
4. ⏳ Phase 5: Alte Test-Dateien löschen
