# Playwright Config Update - Phase 3 Fix

## Changes Made

### playwright.config.ts

**Problem:** Workflow hatte Timeouts, weil alte Tests mitgelaufen sind.

**Fix:** 
- ✅ Präzisiere `testMatch` patterns auf nur `critical/` und `workflows/`
- ✅ Ignoriere explizit alle alten Tests auf Root-Level mit `testIgnore`

```typescript
// VORHER (lief ALLE Tests)
testMatch: [
  '**/critical/*.spec.ts',
  '**/workflows/*.spec.ts',
  '**/*.spec.ts',  // ← Problem!
],

// NACHHER (nur neue Tests)
testMatch: [
  'tests/playwright/critical/**/*.spec.ts',
  'tests/playwright/workflows/**/*.spec.ts',
],
testIgnore: [
  // ... helpers, docs, etc.
  'tests/playwright/*.spec.ts',  // Alle Root-Level Tests ignorieren
],
```

## Test Count Verification

```powershell
npx playwright test --list
# Output: Total: 183 tests in 7 files ✅
```

**Breakdown:**
- 4 Critical Smoke Tests
- 57 Workflow Tests
- × 3 Browser (chromium, firefox, webkit)
- = **183 Tests total**

## Impact

| Metrik | Vorher | Nachher |
|--------|--------|---------|
| Test-Dateien ausgeführt | ~14 | 7 |
| Tests pro Browser | ~40+ | 61 |
| Timeout-Risiko | Hoch | Niedrig |
| Geschätzte CI-Zeit | Timeout (60min) | ~10-15 min |

## Files Affected

### Modified
- `playwright.config.ts` - testMatch & testIgnore angepasst

### Created
- `docs/PLAYWRIGHT_CONFIG_FIX.md` - Dokumentation

## Testing

Lokal getestet mit:
```powershell
npx playwright test --list
```

Bestätigt: Nur 7 neue Test-Dateien werden erkannt, alte Tests werden ignoriert.

## Next Steps

1. ✅ Config-Fix committed
2. ⏳ GitHub Workflow läuft durch
3. ⏳ Verifikation: Alle Tests grün
4. ⏳ Phase 4: Workflow-Optimierung
5. ⏳ Phase 5: Alte Dateien löschen

---

**Commit Message:**

```
fix(playwright): Ignore old tests to prevent timeout

Update playwright.config.ts to only run new workflow-based tests:
- Change testMatch to explicit paths (critical/, workflows/)
- Add testIgnore for all root-level .spec.ts files
- Prevents old tests from causing CI timeouts

Verified: 183 tests in 7 files (was ~14 files with old tests)
Estimated CI time: 10-15 min (was timing out at 60 min)

Ref: docs/PLAYWRIGHT_CONFIG_FIX.md
```
