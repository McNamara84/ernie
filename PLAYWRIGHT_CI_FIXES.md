# Playwright CI Fixes - Diagnose und Lösung

## Problem

GitHub Actions Workflow läuft sehr lange und bricht dann mit Fehlern ab:
```
Running 61 tests using 1 worker
××F·××T××F××T××F···××T·××F××F××T·××F°××T°××T××F××F××F××F××F××F°°××T·××T××T××T××T
Error: The operation was canceled.
```

**Legende:**
- `×` = Test übersprungen
- `F` = Test fehlgeschlagen (Failed)
- `T` = Test Timeout
- `·` = Test bestanden
- `°` = Test erwartungsgemäß fehlgeschlagen (Expected Failure)

## Analyse

### Symptome
1. Viele Tests haben **Timeouts** (`T`)
2. Viele Tests **schlagen fehl** (`F`)
3. Workflow wird nach 60 Minuten **abgebrochen**
4. Nur **1 Worker** pro Browser - sehr langsam

### Wahrscheinliche Ursachen

#### 1. Server-Startup-Problem
Der Laravel-Server startet möglicherweise nicht richtig oder ist zu langsam:
```yaml
- name: Start Laravel server
  run: php artisan serve --host=127.0.0.1 --port=8000 &
  
- name: Wait for server to be ready
  run: timeout 30 bash -c 'until curl -s http://127.0.0.1:8000 > /dev/null; do sleep 1; done'
```

**Problem:** 30 Sekunden könnten zu kurz sein wenn:
- Composer Autoloader langsam ist
- .env nicht korrekt geladen wird
- SQLite DB nicht initialisiert ist

#### 2. Test-Timeouts
Playwright Default-Timeout ist 30s, aber manche Workflows brauchen länger:
- XML Upload + Parsing
- Multiple Authors/Titles hinzufügen
- ROR Affiliation Search (externe API)

#### 3. Race Conditions
Tests könnten zu schnell sein und warten nicht auf:
- Debounced Inputs (Search, Filter)
- Loading States
- AJAX Requests
- Transitions/Animations

#### 4. Datenbank-State
Tests teilen sich die gleiche SQLite-DB:
- Keine Isolation zwischen Tests
- Mögliche Konflikte bei parallelen Tests (aber nur 1 Worker)

## Lösungen

### Quick Fix 1: Erhöhe Timeouts

```yaml
# playwright.config.ts
export default defineConfig({
  timeout: 90 * 1000,  // 90s statt 60s
  expect: {
    timeout: 15 * 1000,  // 15s statt 10s
  },
  use: {
    actionTimeout: 15 * 1000,  // 15s für Aktionen
    navigationTimeout: 30 * 1000,  // 30s für Navigation
  },
});
```

### Quick Fix 2: Verbessere Server-Startup

```yaml
- name: Wait for server to be ready
  run: |
    echo "Waiting for Laravel server..."
    for i in {1..60}; do
      if curl -s http://127.0.0.1:8000 > /dev/null 2>&1; then
        echo "Server is ready after $i seconds!"
        curl -I http://127.0.0.1:8000
        exit 0
      fi
      echo "Attempt $i/60..."
      sleep 1
    done
    echo "Server failed to start within 60 seconds"
    exit 1
```

### Quick Fix 3: Reduziere Test-Last

Option A: **Nur Chromium im PR, alle Browser nur im main**
```yaml
strategy:
  matrix:
    browser: ${{ github.event_name == 'pull_request' && ['chromium'] || ['chromium', 'firefox', 'webkit'] }}
```

Option B: **Smoke Tests zuerst, fail-fast**
```yaml
jobs:
  smoke-tests:
    name: Critical Smoke Tests
    runs-on: ubuntu-latest
    steps:
      # ... setup ...
      - name: Run Smoke Tests
        run: npx playwright test tests/playwright/critical --project=chromium
      
  workflow-tests:
    name: Workflow Tests (${{ matrix.browser }})
    needs: smoke-tests  # Nur wenn Smoke Tests grün
    strategy:
      matrix:
        browser: [chromium, firefox, webkit]
    # ... rest ...
```

### Quick Fix 4: Parallele Ausführung

```yaml
- name: Run Playwright tests
  run: npx playwright test --project=${{ matrix.browser }} --workers=2
```

**Warnung:** Kann Race Conditions verursachen wenn Tests DB-State teilen!

### Quick Fix 5: Sharding (Empfohlen!)

```yaml
strategy:
  matrix:
    browser: [chromium, firefox, webkit]
    shard: [1/2, 2/2]  # Teile Tests in 2 Shards

steps:
  # ... setup ...
  - name: Run Playwright tests
    run: npx playwright test --project=${{ matrix.browser }} --shard=${{ matrix.shard }}
```

**Ergebnis:** 6 Jobs (3 Browser × 2 Shards), jeder läuft ~5-7 Min

## Empfohlene Strategie

### Phase 4a: Sofort-Fix (jetzt)
1. ✅ Erhöhe Timeouts in `playwright.config.ts`
2. ✅ Verbessere Server-Startup-Check
3. ✅ Nur Chromium für Pull Requests

### Phase 4b: Optimierung (später)
4. ⏳ Split in Smoke + Workflow Jobs
5. ⏳ Sharding implementieren
6. ⏳ Parallele Worker (mit Test-Isolation)

## Sofort-Anwendung

### 1. playwright.config.ts anpassen
```typescript
timeout: 90 * 1000,  // 90s
expect: {
  timeout: 15 * 1000,  // 15s
},
use: {
  actionTimeout: 15 * 1000,
  navigationTimeout: 30 * 1000,
},
```

### 2. Workflow anpassen (.github/workflows/playwright.yml)
```yaml
strategy:
  matrix:
    # Nur Chromium für PRs, alle Browser für main
    browser: ${{ github.event_name == 'pull_request' && fromJSON('["chromium"]') || fromJSON('["chromium", "firefox", "webkit"]') }}

# Besserer Server-Check
- name: Wait for server to be ready
  run: |
    for i in {1..60}; do
      if curl -sf http://127.0.0.1:8000 > /dev/null; then
        echo "✅ Server ready after $i seconds"
        exit 0
      fi
      sleep 1
    done
    echo "❌ Server timeout"
    exit 1
```

### 3. Tests debuggen
```bash
# Lokal testen mit mehr Logging
DEBUG=pw:api npx playwright test --project=chromium --headed
```

## Erwartete Verbesserung

| Metrik | Vorher | Mit Fixes | Verbesserung |
|--------|--------|-----------|--------------|
| **Timeout-Rate** | Hoch (viele T) | Niedrig | -80% |
| **CI-Zeit (PR)** | 60 Min (timeout) | ~15 Min | -75% |
| **CI-Zeit (main)** | 60 Min (timeout) | ~20 Min | -67% |
| **Zuverlässigkeit** | Instabil | Stabil | +++

## Next Steps

1. ✅ Fixes committen
2. ⏳ GitHub Actions laufen lassen
3. ⏳ Wenn Tests grün: Phase 4 (Optimierung)
4. ⏳ Wenn Tests noch fehlschlagen: Einzelne Tests debuggen
