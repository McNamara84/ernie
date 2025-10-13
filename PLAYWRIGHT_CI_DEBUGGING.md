# Playwright CI Debugging Guide

## Problem: Tests schlagen in CI fehl

Der Workflow zeigt: `××F·××T××F××T××F···××T·××F××F××T·××F°××T°××T××F××F××F××F××F××F°°××T·××T××T××T××T`

**Legende:**
- `×` = Test übersprungen (skipped)
- `F` = Test fehlgeschlagen (Failed)
- `T` = Test Timeout
- `·` = Test bestanden (passed)
- `°` = Test erwartungsgemäß fehlgeschlagen

## Analyse: Warum schlagen die Tests fehl?

### Mögliche Ursachen

#### 1. Testbenutzer fehlt in DB
Die Tests verwenden `test@example.com` / `password`. Dieser User muss in der DB existieren!

**Prüfen im Workflow:**
```yaml
- name: Verify test user exists
  run: |
    php artisan tinker --execute="echo App\Models\User::where('email', 'test@example.com')->exists() ? 'User exists' : 'User MISSING';"
```

#### 2. Tests warten nicht richtig
Die neuen Workflow-Tests haben komplexere Interaktionen, die möglicherweise nicht auf Loading States warten.

**Häufige Probleme:**
- Debounced Inputs (Search, Filter) - brauchen `waitForDebounce()`
- AJAX Requests - brauchen `waitForLoadState('networkidle')`
- Accordions - brauchen `waitForAccordionState()`
- Navigation - brauchen `waitForURL()`

#### 3. Daten fehlen in DB
Die Tests erwarten bestimmte Daten:
- Old Datasets (für 02-old-datasets-workflow.spec.ts)
- Resources (für 05-resources-management.spec.ts)
- ROR Affiliations (bereits vorhanden im Setup)

**Prüfen:**
```yaml
- name: Verify database seeding
  run: |
    php artisan tinker --execute="
    echo 'Users: ' . App\Models\User::count();
    echo 'Resources: ' . App\Models\Resource::count();
    "
```

#### 4. Timing-Probleme in CI
CI ist langsamer als lokal:
- Build Assets dauert länger
- Server-Start dauert länger
- Browser ist langsamer

**Bereits gefixt:**
- ✅ Timeouts erhöht (90s Test, 15s Expect)
- ✅ Action Timeout (15s)
- ✅ Navigation Timeout (30s)

#### 5. Tests sind nicht idempotent
Tests könnten:
- Daten in der DB ändern (CREATE, UPDATE, DELETE)
- Nicht aufräumen nach sich
- Von bestimmter Reihenfolge abhängen

**Lösung:** Tests sollten:
- `beforeEach` für Setup nutzen
- `afterEach` für Cleanup nutzen
- Unabhängig voneinander laufen können

## Empfohlene Fixes

### Fix 1: Füge Test-User zum Seeder hinzu

**`database/seeders/DatabaseSeeder.php`:**
```php
public function run(): void
{
    // Existing seeders...
    
    // Create test user for Playwright tests
    if (app()->environment('local', 'testing')) {
        \App\Models\User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
        ]);
    }
}
```

### Fix 2: Füge Debugging zum Workflow hinzu

```yaml
- name: Debug - Check test user and data
  run: |
    echo "=== Checking test user ==="
    php artisan tinker --execute="
    \$user = App\Models\User::where('email', 'test@example.com')->first();
    if (\$user) {
        echo 'Test user exists: ' . \$user->email;
    } else {
        echo 'ERROR: Test user does NOT exist!';
        echo 'Creating test user...';
        App\Models\User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
        ]);
        echo 'Test user created.';
    }
    "
    
    echo ""
    echo "=== Checking database counts ==="
    php artisan tinker --execute="
    echo 'Users: ' . App\Models\User::count();
    echo 'Resources: ' . App\Models\Resource::count();
    "
```

### Fix 3: Füge Screenshots zu allen Tests hinzu

**`playwright.config.ts`:**
```typescript
use: {
  screenshot: 'on',  // Statt 'only-on-failure'
  video: 'on',       // Statt 'retain-on-failure'
}
```

**Warnung:** Erzeugt VIELE Artefakte! Nur zum Debuggen.

### Fix 4: Teste einzelne Workflows isoliert

Statt alle Tests zu laufen, teste erst einmal nur die Smoke Tests:

```yaml
- name: Run Smoke Tests only (Debug)
  run: npx playwright test tests/playwright/critical --project=${{ matrix.browser }}
```

Wenn Smoke Tests grün sind, dann Workflows einzeln:

```yaml
- name: Run Authentication Workflow (Debug)
  run: npx playwright test tests/playwright/workflows/01-authentication.spec.ts --project=${{ matrix.browser }}
```

### Fix 5: Erhöhe Retries in CI

**`playwright.config.ts`:**
```typescript
retries: process.env.CI ? 3 : 0,  // 3 statt 2
```

## Nächste Schritte

### Sofort:
1. ✅ Browser-Matrix beibehalten (alle 3 Browser)
2. ✅ Timeouts sind bereits erhöht
3. ⏳ Test-User im Seeder erstellen
4. ⏳ Debugging-Output zum Workflow hinzufügen

### Danach:
5. ⏳ GitHub Actions laufen lassen
6. ⏳ Logs analysieren (welche Tests schlagen genau fehl?)
7. ⏳ Einzelne Tests lokal reproduzieren
8. ⏳ Spezifische Fixes für fehlgeschlagene Tests

## Quick Commit

```bash
git add .
git commit -m "fix(ci): Revert browser matrix change + add test user to seeder

- Keep all 3 browsers in matrix (chromium, firefox, webkit)
- Ensure test user exists for Playwright tests
- Add debugging output to workflow
- Timeouts already increased (90s test, 15s expect)

Debug fehlgeschlagene Tests in CI"
```
