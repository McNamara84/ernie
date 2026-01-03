# Implementierungsplan: Semantische Landing Page URLs

**Feature-Request:** Landing Page URLs nach dem Muster `/{DOI}/{TITLE_SLUG}` statt `/{ID}`

**Datum:** 03.01.2026

**Status:** Geplant

---

## 1. Übersicht

### 1.1 Aktuelles URL-Schema
```
https://ernie.rz-vm182.gfz.de/datasets/{RESOURCE_ID}
```
Beispiel: `https://ernie.rz-vm182.gfz.de/datasets/1`

### 1.2 Neues URL-Schema
```
https://ernie.rz-vm182.gfz.de/{DOI}/{TITLE_SLUG}
```
Beispiel: `https://ernie.rz-vm182.gfz.de/10.5880/igets.bu.l1.001/superconducting-gravimeter-data-from-buc`

### 1.3 Entscheidungen (aus Anforderungsanalyse)

| Aspekt | Entscheidung |
|--------|-------------|
| DOI-Format in URL | Schrägstriche beibehalten (natives DOI-Format) |
| Sonderzeichen | ASCII-Transliteration (ä→ae, é→e, etc.) |
| Ohne DOI | Fallback: `draft-{RESOURCE_ID}` |
| Titeländerung | Slug bei Erstellung fixieren (immutable) |
| Groß-/Kleinschreibung | Alles kleingeschrieben |
| Slug-Länge | Mindestens 40 Zeichen, dann am nächsten Wortende abschneiden |
| Eindeutigkeit | DOI garantiert Eindeutigkeit |
| Rückwärtskompatibilität | Keine (alte URLs werden entfernt) |

---

## 2. Technische Änderungen

### 2.1 Datenbank-Migration

**Neue Migration:** `add_doi_prefix_to_landing_pages_table.php`

```php
Schema::table('landing_pages', function (Blueprint $table) {
    // DOI-Prefix für URL-Generierung (z.B. "10.5880/igets.bu.l1.001")
    // NULL für Drafts ohne DOI
    $table->string('doi_prefix', 255)->nullable()->after('resource_id');
    
    // Index für effiziente URL-Lookups
    $table->index(['doi_prefix', 'slug'], 'landing_pages_url_lookup');
});
```

**Hinweis:** Das bestehende `slug`-Feld wird weiterverwendet, aber mit neuer Generierungslogik.

---

### 2.2 Neuer Service: `SlugGeneratorService`

**Datei:** `app/Services/SlugGeneratorService.php`

**Verantwortlichkeiten:**
- ASCII-Transliteration (Umlaute, Akzente)
- Entfernung von Sonderzeichen
- Konvertierung zu Kleinbuchstaben
- Leerzeichen → Bindestriche
- Längensteuerung (≥40 Zeichen, Wortgrenze)

**Methoden:**

```php
class SlugGeneratorService
{
    /**
     * Generiert einen URL-freundlichen Slug aus einem Titel.
     * 
     * @param string $title Der Originaltitel
     * @param int $minLength Mindestlänge (default: 40)
     * @return string Der generierte Slug
     */
    public function generateFromTitle(string $title, int $minLength = 40): string;
    
    /**
     * Transliteriert Sonderzeichen zu ASCII.
     * ä→ae, ö→oe, ü→ue, ß→ss, é→e, etc.
     */
    private function transliterate(string $text): string;
    
    /**
     * Schneidet den Text am Wortende ab.
     * Mindestens $minLength Zeichen, dann bis zum nächsten Wortende.
     */
    private function truncateAtWordBoundary(string $text, int $minLength): string;
}
```

**Beispiel-Transformationen:**

| Original | Slug |
|----------|------|
| `Superconducting Gravimeter Data from Buchenbach` | `superconducting-gravimeter-data-from-buchenbach` |
| `Böden der Südalpen: Eine Analyse (2024)` | `boeden-der-suedalpen-eine-analyse-2024` |
| `A & B: Test` | `a-b-test` |
| `Short` | `short` (unter 40 Zeichen, aber Titel ist kurz) |

---

### 2.3 Model-Änderungen: `LandingPage`

**Datei:** `app/Models/LandingPage.php`

#### 2.3.1 Neue Properties

```php
/**
 * @property string|null $doi_prefix
 */

protected $fillable = [
    // ... existing
    'doi_prefix',
];
```

#### 2.3.2 Geänderte URL-Accessor

```php
/**
 * Get the public landing page URL.
 * Format: /{DOI}/{SLUG} oder /draft-{ID}/{SLUG}
 */
public function getPublicUrlAttribute(): string
{
    $doiPart = $this->doi_prefix ?? "draft-{$this->resource_id}";
    
    return url("/{$doiPart}/{$this->slug}");
}

/**
 * Get the preview URL for the landing page.
 */
public function getPreviewUrlAttribute(): ?string
{
    if (! $this->preview_token) {
        return null;
    }
    
    $doiPart = $this->doi_prefix ?? "draft-{$this->resource_id}";
    
    return url("/{$doiPart}/{$this->slug}?preview={$this->preview_token}");
}
```

#### 2.3.3 Slug-Generierung beim Erstellen

```php
protected static function boot(): void
{
    parent::boot();

    static::creating(function (LandingPage $landingPage): void {
        // Generate preview token
        if (empty($landingPage->preview_token)) {
            $landingPage->preview_token = Str::random(64);
        }
        
        // Generate slug from main title (immutable after creation)
        if (empty($landingPage->slug)) {
            $landingPage->slug = app(SlugGeneratorService::class)
                ->generateFromTitle($landingPage->getMainTitleFromResource());
        }
        
        // Capture DOI prefix from resource
        if (empty($landingPage->doi_prefix)) {
            $landingPage->doi_prefix = $landingPage->getDOIPrefixFromResource();
        }
    });
}

/**
 * Get main title from associated resource.
 */
private function getMainTitleFromResource(): string
{
    $resource = $this->resource ?? Resource::find($this->resource_id);
    
    if (!$resource) {
        return 'dataset-' . $this->resource_id;
    }
    
    $mainTitle = $resource->titles()
        ->whereNull('title_type_id')
        ->orWhereHas('titleType', fn($q) => $q->where('slug', 'main-title'))
        ->first();
    
    return $mainTitle?->value ?? 'dataset-' . $this->resource_id;
}

/**
 * Get DOI prefix from associated resource.
 * Returns null if resource has no DOI.
 */
private function getDOIPrefixFromResource(): ?string
{
    $resource = $this->resource ?? Resource::find($this->resource_id);
    
    return $resource?->doi;
}
```

---

### 2.4 Routing-Änderungen

**Datei:** `routes/web.php`

#### 2.4.1 Alte Route entfernen

```php
// ENTFERNEN:
Route::get('datasets/{resourceId}', [LandingPagePublicController::class, 'show'])
    ->name('landing-page.show')
    ->where('resourceId', '[0-9]+');

Route::post('datasets/{resourceId}/contact', [ContactMessageController::class, 'store'])
    ->name('landing-page.contact')
    ->where('resourceId', '[0-9]+')
    ->middleware('throttle:10,1');
```

#### 2.4.2 Neue Routes hinzufügen

```php
// Landing Pages mit DOI (z.B. /10.5880/test.001/my-dataset-title)
// Regex: DOI-Prefix fängt mit 10. an und kann Schrägstriche enthalten
Route::get('{doiPrefix}/{slug}', [LandingPagePublicController::class, 'show'])
    ->name('landing-page.show')
    ->where('doiPrefix', '10\.[0-9]+/.+')
    ->where('slug', '[a-z0-9-]+');

Route::post('{doiPrefix}/{slug}/contact', [ContactMessageController::class, 'store'])
    ->name('landing-page.contact')
    ->where('doiPrefix', '10\.[0-9]+/.+')
    ->where('slug', '[a-z0-9-]+')
    ->middleware('throttle:10,1');

// Landing Pages ohne DOI (Draft-Modus)
Route::get('draft-{resourceId}/{slug}', [LandingPagePublicController::class, 'showDraft'])
    ->name('landing-page.show-draft')
    ->where('resourceId', '[0-9]+')
    ->where('slug', '[a-z0-9-]+');

Route::post('draft-{resourceId}/{slug}/contact', [ContactMessageController::class, 'store'])
    ->name('landing-page.contact-draft')
    ->where('resourceId', '[0-9]+')
    ->where('slug', '[a-z0-9-]+')
    ->middleware('throttle:10,1');
```

**Wichtig:** Diese Routes müssen **nach** allen anderen spezifischen Routes definiert werden, aber **vor** Catch-All-Routes, da das DOI-Muster sehr allgemein ist.

---

### 2.5 Controller-Änderungen

**Datei:** `app/Http/Controllers/LandingPagePublicController.php`

#### 2.5.1 Neue show() Methode

```php
/**
 * Display a public landing page for a resource (with DOI).
 */
public function show(
    Request $request, 
    LandingPageResourceTransformer $transformer, 
    string $doiPrefix, 
    string $slug
): Response {
    $previewToken = $request->query('preview');

    // Find landing page by DOI prefix and slug
    $landingPage = LandingPage::where('doi_prefix', $doiPrefix)
        ->where('slug', $slug)
        ->first();

    abort_if(! $landingPage, HttpResponse::HTTP_NOT_FOUND, 'Landing page not found');

    return $this->renderLandingPage($landingPage, $transformer, $previewToken);
}

/**
 * Display a public landing page for a draft resource (without DOI).
 */
public function showDraft(
    Request $request, 
    LandingPageResourceTransformer $transformer, 
    int $resourceId, 
    string $slug
): Response {
    $previewToken = $request->query('preview');

    // Find landing page by resource ID and slug (no DOI)
    $landingPage = LandingPage::where('resource_id', $resourceId)
        ->whereNull('doi_prefix')
        ->where('slug', $slug)
        ->first();

    abort_if(! $landingPage, HttpResponse::HTTP_NOT_FOUND, 'Landing page not found');

    return $this->renderLandingPage($landingPage, $transformer, $previewToken);
}

/**
 * Common rendering logic for landing pages.
 */
private function renderLandingPage(
    LandingPage $landingPage,
    LandingPageResourceTransformer $transformer,
    ?string $previewToken
): Response {
    // Check access permissions
    if (! $landingPage->isPublished()) {
        if (! $previewToken) {
            abort(HttpResponse::HTTP_NOT_FOUND, 'Landing page not found');
        }
        if ($previewToken !== $landingPage->preview_token) {
            abort(HttpResponse::HTTP_FORBIDDEN, 'Invalid preview token');
        }
    }

    // Increment view count only for published pages without preview token
    if ($landingPage->isPublished() && ! $previewToken) {
        $landingPage->incrementViewCount();
    }

    // Load resource with all necessary relationships
    $resource = Resource::with($transformer->requiredRelations())
        ->findOrFail($landingPage->resource_id);

    $resourceData = $transformer->transform($resource);

    $data = [
        'resource' => $resourceData,
        'landingPage' => $landingPage->toArray(),
        'isPreview' => (bool) $previewToken,
    ];

    $template = $landingPage->template ?? 'default_gfz';

    return Inertia::render("LandingPages/{$template}", $data);
}
```

---

### 2.6 DOI-Update-Logik

Wenn eine DOI für eine Resource registriert wird, muss die LandingPage aktualisiert werden:

**Datei:** `app/Observers/ResourceObserver.php` (neu oder erweitern)

```php
class ResourceObserver
{
    /**
     * Handle the Resource "updated" event.
     */
    public function updated(Resource $resource): void
    {
        // Wenn DOI sich geändert hat, Landing Page aktualisieren
        if ($resource->isDirty('doi') && $resource->landingPage) {
            $resource->landingPage->update([
                'doi_prefix' => $resource->doi,
            ]);
        }
    }
}
```

**Registrierung in `AppServiceProvider`:**

```php
public function boot(): void
{
    Resource::observe(ResourceObserver::class);
}
```

---

### 2.7 LandingPageController Anpassungen

**Datei:** `app/Http/Controllers/LandingPageController.php`

Die `store()` Methode muss aktualisiert werden:

```php
public function store(Request $request, Resource $resource): JsonResponse
{
    // ... validation ...

    if ($resource->landingPage) {
        return response()->json([
            'message' => 'Landing page already exists for this resource',
        ], 409);
    }

    // Generate slug using the new service
    $slugGenerator = app(SlugGeneratorService::class);
    $mainTitle = $resource->titles()
        ->whereNull('title_type_id')
        ->first()?->value ?? 'dataset-' . $resource->id;
    
    $slug = $slugGenerator->generateFromTitle($mainTitle);

    $landingPage = $resource->landingPage()->create([
        'slug' => $slug,
        'doi_prefix' => $resource->doi, // NULL if no DOI yet
        'template' => $validated['template'],
        'ftp_url' => $validated['ftp_url'] ?? null,
        'is_published' => $validated['is_published'] ?? false,
        'published_at' => ($validated['is_published'] ?? false) ? now() : null,
    ]);

    // ... rest of method ...
}
```

---

### 2.8 Test-Aktualisierungen

#### 2.8.1 Unit Tests für SlugGeneratorService

**Datei:** `tests/Unit/Services/SlugGeneratorServiceTest.php`

```php
it('converts title to lowercase slug', function () {
    $service = new SlugGeneratorService();
    
    expect($service->generateFromTitle('Hello World'))
        ->toBe('hello-world');
});

it('transliterates German umlauts', function () {
    $service = new SlugGeneratorService();
    
    expect($service->generateFromTitle('Böden der Südalpen'))
        ->toBe('boeden-der-suedalpen');
});

it('removes special characters', function () {
    $service = new SlugGeneratorService();
    
    expect($service->generateFromTitle('Test & Analysis: Results (2024)'))
        ->toBe('test-analysis-results-2024');
});

it('truncates at word boundary after minimum length', function () {
    $service = new SlugGeneratorService();
    
    $title = 'Superconducting Gravimeter Data from Buchenbach Observatory Germany';
    $slug = $service->generateFromTitle($title, 40);
    
    // Should be at least 40 chars, ending at a word boundary
    expect(strlen($slug))->toBeGreaterThanOrEqual(40);
    expect($slug)->not->toEndWith('-');
});

it('handles short titles without truncation', function () {
    $service = new SlugGeneratorService();
    
    expect($service->generateFromTitle('Short Title'))
        ->toBe('short-title');
});
```

#### 2.8.2 Feature Tests für neue URLs

**Datei:** `tests/Feature/LandingPage/LandingPageSemanticUrlTest.php`

```php
it('resolves landing page by DOI and slug', function () {
    $resource = Resource::factory()->create(['doi' => '10.5880/test.001']);
    $landingPage = LandingPage::factory()->create([
        'resource_id' => $resource->id,
        'doi_prefix' => '10.5880/test.001',
        'slug' => 'my-test-dataset',
        'is_published' => true,
    ]);

    $this->get('/10.5880/test.001/my-test-dataset')
        ->assertStatus(200);
});

it('resolves draft landing page by resource ID', function () {
    $resource = Resource::factory()->create(['doi' => null]);
    $landingPage = LandingPage::factory()->create([
        'resource_id' => $resource->id,
        'doi_prefix' => null,
        'slug' => 'draft-dataset',
        'is_published' => false,
        'preview_token' => 'test-token',
    ]);

    $this->get("/draft-{$resource->id}/draft-dataset?preview=test-token")
        ->assertStatus(200);
});

it('returns 404 for non-existent DOI/slug combination', function () {
    $this->get('/10.5880/nonexistent/fake-slug')
        ->assertStatus(404);
});
```

---

## 3. Datenmigration

### 3.1 Migrations-Skript für bestehende Landing Pages

**Artisan Command:** `php artisan landing-pages:migrate-urls`

**Datei:** `app/Console/Commands/MigrateLandingPageUrls.php`

```php
class MigrateLandingPageUrls extends Command
{
    protected $signature = 'landing-pages:migrate-urls';
    protected $description = 'Migrate existing landing pages to new URL schema';

    public function handle(SlugGeneratorService $slugGenerator): int
    {
        $landingPages = LandingPage::with('resource.titles')->get();
        
        $bar = $this->output->createProgressBar($landingPages->count());
        
        foreach ($landingPages as $landingPage) {
            $resource = $landingPage->resource;
            
            if (!$resource) {
                $this->warn("Skipping landing page {$landingPage->id}: No resource");
                continue;
            }
            
            // Get main title
            $mainTitle = $resource->titles
                ->first(fn($t) => $t->isMainTitle())
                ?->value ?? 'dataset-' . $resource->id;
            
            // Generate new slug
            $newSlug = $slugGenerator->generateFromTitle($mainTitle);
            
            // Update landing page
            $landingPage->update([
                'doi_prefix' => $resource->doi,
                'slug' => $newSlug,
            ]);
            
            $bar->advance();
        }
        
        $bar->finish();
        $this->newLine();
        $this->info('Migration completed!');
        
        return Command::SUCCESS;
    }
}
```

---

## 4. Implementierungsreihenfolge

### Phase 1: Grundlagen (Backend)
1. [ ] Datenbank-Migration erstellen und ausführen
2. [ ] `SlugGeneratorService` implementieren
3. [ ] Unit Tests für `SlugGeneratorService` schreiben

### Phase 2: Model & Observer
4. [ ] `LandingPage` Model erweitern (Properties, Accessors)
5. [ ] `ResourceObserver` erstellen/erweitern
6. [ ] Model-Boot-Logik für Slug-Generierung

### Phase 3: Routing & Controller
7. [ ] Neue Routes definieren (mit korrekten Constraints)
8. [ ] `LandingPagePublicController` refactoren
9. [ ] `LandingPageController::store()` anpassen

### Phase 4: Migration & Tests
10. [ ] Migrations-Command erstellen
11. [ ] Feature Tests für neue URL-Struktur
12. [ ] Bestehende Tests aktualisieren

### Phase 5: Frontend (optional)
13. [ ] URL-Generierung im Frontend prüfen (falls vorhanden)
14. [ ] Playwright E2E Tests aktualisieren

### Phase 6: Deployment
15. [ ] Migrations-Command auf Staging ausführen
16. [ ] Manuelle Tests
17. [ ] Production Deployment

---

## 5. Risiken & Mitigationen

| Risiko | Wahrscheinlichkeit | Auswirkung | Mitigation |
|--------|-------------------|------------|------------|
| Routing-Konflikte mit anderen Routes | Mittel | Hoch | DOI-Regex präzise definieren, Route-Reihenfolge beachten |
| Duplicate Slugs | Niedrig | Mittel | DOI garantiert Eindeutigkeit |
| Lange DOIs brechen Layout | Niedrig | Niedrig | CSS word-break anwenden |
| Performance bei URL-Lookup | Niedrig | Mittel | Composite Index auf (doi_prefix, slug) |

---

## 6. Offene Punkte

- [ ] Entscheidung: Sollen Previews ohne DOI überhaupt öffentlich zugänglich sein?
- [ ] SEO-Implikationen der neuen URL-Struktur prüfen
- [ ] Externe Systeme informieren, die auf alte URLs verlinken könnten

---

## 7. Geschätzter Aufwand

| Phase | Aufwand |
|-------|---------|
| Phase 1: Grundlagen | 2-3 Stunden |
| Phase 2: Model & Observer | 1-2 Stunden |
| Phase 3: Routing & Controller | 2-3 Stunden |
| Phase 4: Migration & Tests | 2-3 Stunden |
| Phase 5: Frontend | 1 Stunde |
| Phase 6: Deployment | 1 Stunde |
| **Gesamt** | **9-13 Stunden** |

---

## 8. Beispiel-URLs nach Implementierung

| Szenario | URL |
|----------|-----|
| Resource mit DOI, publiziert | `https://ernie.rz-vm182.gfz.de/10.5880/igets.bu.l1.001/superconducting-gravimeter-data-from-buc` |
| Resource mit DOI, Preview | `https://ernie.rz-vm182.gfz.de/10.5880/igets.bu.l1.001/superconducting-gravimeter-data-from-buc?preview=abc123...` |
| Resource ohne DOI, Preview | `https://ernie.rz-vm182.gfz.de/draft-42/my-new-dataset-title-that-is-long-enough` |
| Kurzer Titel | `https://ernie.rz-vm182.gfz.de/10.5880/test.001/short-title` |
| Titel mit Umlauten | `https://ernie.rz-vm182.gfz.de/10.5880/gfz.2024/boeden-der-suedalpen-eine-geologische-analyse` |
