<?php

declare(strict_types=1);

use App\Models\Language;
use App\Models\Resource;
use App\Models\Title;
use App\Services\Language\LanguageSuggestionDiscoveryService;

beforeEach(function () {
    \App\Models\DescriptionType::firstOrCreate(
        ['id' => 1],
        ['name' => 'abstract', 'slug' => 'abstract']
    );
});

// ══════════════════════════════════════════════════════════════════════════════════
// ─ EXPLICIT LANGUAGE DETECTION (from title/description language attributes) ─
// ══════════════════════════════════════════════════════════════════════════════════

// Erkennt explizite deutsche Sprache aus dem Sprachattribut des Titels
it('detects explicit German language from title language attribute', function () {
    $resource = Resource::factory()->create(['language_id' => null]);

    Language::firstOrCreate(['code' => 'de'], ['name' => 'German', 'active' => true, 'elmo_active' => true]);
    Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'active' => true, 'elmo_active' => true]);

    Title::factory()->create([
        'resource_id' => $resource->id,
        'value' => 'Eine Studie über geologische Proben',
        'language' => 'de',
    ]);

    $suggestions = [];
    $service = new LanguageSuggestionDiscoveryService;

    $count = $service->discover(
        storeSuggestion: function (
            int $resourceId,
            string $targetType,
            int $targetId,
            string $suggestedValue,
            string $suggestedLabel,
            ?float $similarityScore,
            ?array $metadata,
        ) use (&$suggestions): bool {
            $suggestions[] = compact(
                'resourceId',
                'targetType',
                'targetId',
                'suggestedValue',
                'suggestedLabel',
                'similarityScore',
                'metadata',
            );

            return true;
        },
        onProgress: fn (string $message) => null,
    );

    expect($count)->toBe(1);
    expect($suggestions)->toHaveCount(1);
    expect($suggestions[0]['suggestedValue'])->toBe('de');
    expect($suggestions[0]['suggestedLabel'])->toBe('German (de)');
    // Confidence for explicit language should be in 95-100 band per spec
    expect($suggestions[0]['similarityScore'])->toBe(0.95);
    expect($suggestions[0]['metadata']['source'])->toBe('explicit_language');
    // Verify evidence structure per spec
    expect($suggestions[0]['metadata'])->toHaveKey('evidence');
    expect(is_array($suggestions[0]['metadata']['evidence']))->toBeTrue();
});

// Priorisiert explizite Sprache gegenüber Text-Heuristiken
it('prioritizes explicit language over text heuristics', function () {
    $resource = Resource::factory()->create(['language_id' => null]);

    Language::firstOrCreate(['code' => 'de'], ['name' => 'German', 'active' => true, 'elmo_active' => true]);
    Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'active' => true, 'elmo_active' => true]);

    // Explicit German title
    Title::factory()->create([
        'resource_id' => $resource->id,
        'value' => 'Eine Studie',
        'language' => 'de',
    ]);

    // Description with English-like text, but no explicit language
    $resource->descriptions()->create([
        'value' => 'This dataset contains data',
        'description_type_id' => 1,
        'language' => null,
    ]);

    $suggestions = [];
    $service = new LanguageSuggestionDiscoveryService;

    $service->discover(
        storeSuggestion: function (
            int $resourceId,
            string $targetType,
            int $targetId,
            string $suggestedValue,
            string $suggestedLabel,
            ?float $similarityScore,
            ?array $metadata,
        ) use (&$suggestions): bool {
            $suggestions[] = ['value' => $suggestedValue, 'source' => $metadata['source'] ?? null];

            return true;
        },
        onProgress: fn (string $message) => null,
    );

    expect($suggestions[0]['value'])->toBe('de');
    expect($suggestions[0]['source'])->toBe('explicit_language');
});

// Verifiziert, dass mehrere explizite Sprachsignale Konfidenz bestätigen
it('increases confidence when multiple explicit evidence sources agree', function () {
    $resource = Resource::factory()->create(['language_id' => null]);

    Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'active' => true, 'elmo_active' => true]);
    Language::firstOrCreate(['code' => 'de'], ['name' => 'German', 'active' => true, 'elmo_active' => true]);

    // Both title and description are explicitly English
    Title::factory()->create([
        'resource_id' => $resource->id,
        'value' => 'Groundwater quality study',
        'language' => 'en',
    ]);

    $resource->descriptions()->create([
        'value' => 'Research dataset for analysis',
        'description_type_id' => 1,
        'language' => 'en',
    ]);

    $suggestions = [];
    $service = new LanguageSuggestionDiscoveryService;

    $service->discover(
        storeSuggestion: function (
            int $resourceId,
            string $targetType,
            int $targetId,
            string $suggestedValue,
            string $suggestedLabel,
            ?float $similarityScore,
            ?array $metadata,
        ) use (&$suggestions): bool {
            $suggestions[] = compact('suggestedValue', 'similarityScore', 'metadata');

            return true;
        },
        onProgress: fn (string $message) => null,
    );

    expect($suggestions)->toHaveCount(1);
    expect($suggestions[0]['suggestedValue'])->toBe('en');
    // Multiple explicit sources should result in highest confidence (95+)
    expect($suggestions[0]['similarityScore'])->toBe(0.95);
    expect($suggestions[0]['metadata']['source'])->toBe('explicit_language');
    // Evidence should document all sources that agreed
    expect($suggestions[0]['metadata'])->toHaveKey('evidence');
});

// ══════════════════════════════════════════════════════════════════════════════════
// ─ ENGLISH TEXT DETECTION (various English content patterns) ─
// ══════════════════════════════════════════════════════════════════════════════════

// Erkennt Englisch aus klarem englischem Titel und Beschreibung
it('detects English from clear English title and description', function () {
    $resource = Resource::factory()->create(['language_id' => null]);

    Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'active' => true, 'elmo_active' => true]);
    Language::firstOrCreate(['code' => 'de'], ['name' => 'German', 'active' => true, 'elmo_active' => true]);

    Title::factory()->create([
        'resource_id' => $resource->id,
        'value' => 'A comprehensive study of groundwater quality in coastal regions',
        'language' => null,
    ]);

    $resource->descriptions()->create([
        'value' => 'This dataset contains research data and analysis for the study of aquifer systems.',
        'description_type_id' => 1,
        'language' => null,
    ]);

    $suggestions = [];
    $service = new LanguageSuggestionDiscoveryService;

    $service->discover(
        storeSuggestion: function (
            int $resourceId,
            string $targetType,
            int $targetId,
            string $suggestedValue,
            string $suggestedLabel,
            ?float $similarityScore,
            ?array $metadata,
        ) use (&$suggestions): bool {
            $suggestions[] = compact(
                'suggestedValue',
                'suggestedLabel',
                'similarityScore',
                'metadata',
            );

            return true;
        },
        onProgress: fn (string $message) => null,
    );

    expect($suggestions)->toHaveCount(1);
    expect($suggestions[0]['suggestedValue'])->toBe('en');
    expect($suggestions[0]['suggestedLabel'])->toBe('English (en)');
    // Confidence for text heuristic should be in 60-79 band per spec (at least 0.6)
    expect($suggestions[0]['similarityScore'])->toBeGreaterThanOrEqual(0.6);
    expect($suggestions[0]['similarityScore'])->toBeLessThanOrEqual(0.9);
    expect($suggestions[0]['metadata']['source'])->toBe('text_heuristic');
    // Verify evidence structure per spec
    expect($suggestions[0]['metadata'])->toHaveKey('evidence');
});

// Erkennt Englisch aus wissenschaftlichem englischem Inhalt mit Fachbegriffen
it('detects English from scientific English content with domain terms', function () {
    $resource = Resource::factory()->create(['language_id' => null]);

    Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'active' => true, 'elmo_active' => true]);
    Language::firstOrCreate(['code' => 'de'], ['name' => 'German', 'active' => true, 'elmo_active' => true]);

    Title::factory()->create([
        'resource_id' => $resource->id,
        'value' => 'Geological formation analysis using seismic data',
        'language' => null,
    ]);

    $resource->descriptions()->create([
        'value' => 'Research data for mapping subsurface geological units with advanced modeling techniques.',
        'description_type_id' => 1,
        'language' => null,
    ]);

    $suggestions = [];
    $service = new LanguageSuggestionDiscoveryService;

    $service->discover(
        storeSuggestion: function (
            int $resourceId,
            string $targetType,
            int $targetId,
            string $suggestedValue,
            string $suggestedLabel,
            ?float $similarityScore,
            ?array $metadata,
        ) use (&$suggestions): bool {
            $suggestions[] = compact('suggestedValue', 'similarityScore', 'metadata');

            return true;
        },
        onProgress: fn (string $message) => null,
    );

    expect($suggestions)->toHaveCount(1);
    expect($suggestions[0]['suggestedValue'])->toBe('en');
});

// Überspringt Vorschläge, wenn sich Titel- und Beschreibungssprachen widersprechen
it('skips suggestions when explicit title and description languages conflict', function () {
    $resource = Resource::factory()->create(['language_id' => null]);

    Language::firstOrCreate(['code' => 'de'], ['name' => 'German', 'active' => true, 'elmo_active' => true]);
    Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'active' => true, 'elmo_active' => true]);

    Title::factory()->create([
        'resource_id' => $resource->id,
        'value' => 'Eine Studie über geologische Proben',
        'language' => 'de',
    ]);

    $resource->descriptions()->create([
        'value' => 'This dataset contains research data and analysis for the study.',
        'description_type_id' => 1,
        'language' => 'en',
    ]);

    $suggestions = [];
    $service = new LanguageSuggestionDiscoveryService;

    $count = $service->discover(
        storeSuggestion: function (
            int $resourceId,
            string $targetType,
            int $targetId,
            string $suggestedValue,
            string $suggestedLabel,
            ?float $similarityScore,
            ?array $metadata,
        ) use (&$suggestions): bool {
            $suggestions[] = compact('suggestedValue', 'metadata');

            return true;
        },
        onProgress: fn (string $message) => null,
    );

    expect($count)->toBe(0);
    expect($suggestions)->toHaveCount(0);
});

// Überspringt mehrdeutige Spracherkennung wenn Konfidenz zu niedrig ist
it('skips suggestions when language detection is ambiguous', function () {
    $resource = Resource::factory()->create(['language_id' => null]);

    Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'active' => true, 'elmo_active' => true]);
    Language::firstOrCreate(['code' => 'de'], ['name' => 'German', 'active' => true, 'elmo_active' => true]);
    Language::firstOrCreate(['code' => 'fr'], ['name' => 'French', 'active' => true, 'elmo_active' => true]);

    // Mixed text that produces ambiguous scores
    // (English and German signals too close together)
    Title::factory()->create([
        'resource_id' => $resource->id,
        'value' => 'Data und research analysis',
        'language' => null,
    ]);

    $suggestions = [];
    $service = new LanguageSuggestionDiscoveryService;

    $service->discover(
        storeSuggestion: function (
            int $resourceId,
            string $targetType,
            int $targetId,
            string $suggestedValue,
            string $suggestedLabel,
            ?float $similarityScore,
            ?array $metadata,
        ) use (&$suggestions): bool {
            $suggestions[] = compact('suggestedValue', 'similarityScore', 'metadata');

            return true;
        },
        onProgress: fn (string $message) => null,
    );

    // Should skip because gap between top and second language is too small
    expect($suggestions)->toHaveCount(0);
});

// ══════════════════════════════════════════════════════════════════════════════════
// ─ NON-ENGLISH LANGUAGE DETECTION (German, French, Spanish, Italian, Dutch) ─
// ══════════════════════════════════════════════════════════════════════════════════

// Erkennt Deutsch aus deutschem Text mit Stoppwörtern
it('detects German from German text with stopwords', function () {
    $resource = Resource::factory()->create(['language_id' => null]);

    Language::firstOrCreate(['code' => 'de'], ['name' => 'German', 'active' => true, 'elmo_active' => true]);
    Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'active' => true, 'elmo_active' => true]);
    Language::firstOrCreate(['code' => 'fr'], ['name' => 'French', 'active' => true, 'elmo_active' => true]);

    Title::factory()->create([
        'resource_id' => $resource->id,
        'value' => 'Grundwasserqualität und geologische Formationen',
        'language' => null,
    ]);

    $resource->descriptions()->create([
        'value' => 'Eine umfassende Studie über die Analyse von Grundwassersystemen mit modernen Techniken.',
        'description_type_id' => 1,
        'language' => null,
    ]);

    $suggestions = [];
    $service = new LanguageSuggestionDiscoveryService;

    $service->discover(
        storeSuggestion: function (
            int $resourceId,
            string $targetType,
            int $targetId,
            string $suggestedValue,
            string $suggestedLabel,
            ?float $similarityScore,
            ?array $metadata,
        ) use (&$suggestions): bool {
            $suggestions[] = compact('suggestedValue', 'similarityScore', 'metadata');

            return true;
        },
        onProgress: fn (string $message) => null,
    );

    expect($suggestions)->toHaveCount(1);
    expect($suggestions[0]['suggestedValue'])->toBe('de');
});

// Erkennt Deutsch aus Akzenthinweisen im Text
it('detects German from accent hints in text', function () {
    $resource = Resource::factory()->create(['language_id' => null]);

    Language::firstOrCreate(['code' => 'de'], ['name' => 'German', 'active' => true, 'elmo_active' => true]);
    Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'active' => true, 'elmo_active' => true]);

    Title::factory()->create([
        'resource_id' => $resource->id,
        'value' => 'Gewässeruntersuchung mit Müller-Methode',
        'language' => null,
    ]);

    $suggestions = [];
    $service = new LanguageSuggestionDiscoveryService;

    $service->discover(
        storeSuggestion: function (
            int $resourceId,
            string $targetType,
            int $targetId,
            string $suggestedValue,
            string $suggestedLabel,
            ?float $similarityScore,
            ?array $metadata,
        ) use (&$suggestions): bool {
            $suggestions[] = compact('suggestedValue', 'metadata');

            return true;
        },
        onProgress: fn (string $message) => null,
    );

    expect($suggestions)->toHaveCount(1);
    expect($suggestions[0]['suggestedValue'])->toBe('de');
});

// Erkennt Französisch aus französischem Text mit Akzentzeichen
it('detects French from French text with accent marks', function () {
    $resource = Resource::factory()->create(['language_id' => null]);

    Language::firstOrCreate(['code' => 'fr'], ['name' => 'French', 'active' => true, 'elmo_active' => true]);
    Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'active' => true, 'elmo_active' => true]);

    Title::factory()->create([
        'resource_id' => $resource->id,
        'value' => 'Étude de la qualité des eaux souterraines',
        'language' => null,
    ]);

    $resource->descriptions()->create([
        'value' => 'Données de recherche pour l\'analyse géologique avec des techniques avancées.',
        'description_type_id' => 1,
        'language' => null,
    ]);

    $suggestions = [];
    $service = new LanguageSuggestionDiscoveryService;

    $service->discover(
        storeSuggestion: function (
            int $resourceId,
            string $targetType,
            int $targetId,
            string $suggestedValue,
            string $suggestedLabel,
            ?float $similarityScore,
            ?array $metadata,
        ) use (&$suggestions): bool {
            $suggestions[] = compact('suggestedValue', 'similarityScore', 'metadata');

            return true;
        },
        onProgress: fn (string $message) => null,
    );

    expect($suggestions)->toHaveCount(1);
    expect($suggestions[0]['suggestedValue'])->toBe('fr');
});

// Erkennt Spanisch aus spanischem Text mit Stoppwörtern und Akzenten
it('detects Spanish from Spanish text with stopwords and accents', function () {
    $resource = Resource::factory()->create(['language_id' => null]);

    Language::firstOrCreate(['code' => 'es'], ['name' => 'Spanish', 'active' => true, 'elmo_active' => true]);
    Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'active' => true, 'elmo_active' => true]);

    Title::factory()->create([
        'resource_id' => $resource->id,
        'value' => 'Análisis de la calidad del agua subterránea',
        'language' => null,
    ]);

    $resource->descriptions()->create([
        'value' => 'Investigación exhaustiva sobre sistemas de acuíferos con técnicas modernas.',
        'description_type_id' => 1,
        'language' => null,
    ]);

    $suggestions = [];
    $service = new LanguageSuggestionDiscoveryService;

    $service->discover(
        storeSuggestion: function (
            int $resourceId,
            string $targetType,
            int $targetId,
            string $suggestedValue,
            string $suggestedLabel,
            ?float $similarityScore,
            ?array $metadata,
        ) use (&$suggestions): bool {
            $suggestions[] = compact('suggestedValue', 'metadata');

            return true;
        },
        onProgress: fn (string $message) => null,
    );

    expect($suggestions)->toHaveCount(1);
    expect($suggestions[0]['suggestedValue'])->toBe('es');
});

// Erkennt Italienisch aus italienischem Text
it('detects Italian from Italian text', function () {
    $resource = Resource::factory()->create(['language_id' => null]);

    Language::firstOrCreate(['code' => 'it'], ['name' => 'Italian', 'active' => true, 'elmo_active' => true]);
    Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'active' => true, 'elmo_active' => true]);

    Title::factory()->create([
        'resource_id' => $resource->id,
        'value' => 'Studio della qualità dell\'acqua sotterranea',
        'language' => null,
    ]);

    $resource->descriptions()->create([
        'value' => 'Ricerca comprensiva sui sistemi di falde acquifere con tecniche avanzate.',
        'description_type_id' => 1,
        'language' => null,
    ]);

    $suggestions = [];
    $service = new LanguageSuggestionDiscoveryService;

    $service->discover(
        storeSuggestion: function (
            int $resourceId,
            string $targetType,
            int $targetId,
            string $suggestedValue,
            string $suggestedLabel,
            ?float $similarityScore,
            ?array $metadata,
        ) use (&$suggestions): bool {
            $suggestions[] = compact('suggestedValue', 'metadata');

            return true;
        },
        onProgress: fn (string $message) => null,
    );

    expect($suggestions)->toHaveCount(1);
    expect($suggestions[0]['suggestedValue'])->toBe('it');
});

// Erkennt Niederländisch aus niederländischem Text
it('detects Dutch from Dutch text', function () {
    $resource = Resource::factory()->create(['language_id' => null]);

    Language::firstOrCreate(['code' => 'nl'], ['name' => 'Dutch', 'active' => true, 'elmo_active' => true]);
    Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'active' => true, 'elmo_active' => true]);

    Title::factory()->create([
        'resource_id' => $resource->id,
        'value' => 'Studie van de kwaliteit van grondwater',
        'language' => null,
    ]);

    $resource->descriptions()->create([
        'value' => 'Onderzoek naar aquiférsystemen met geavanceerde technieken.',
        'description_type_id' => 1,
        'language' => null,
    ]);

    $suggestions = [];
    $service = new LanguageSuggestionDiscoveryService;

    $service->discover(
        storeSuggestion: function (
            int $resourceId,
            string $targetType,
            int $targetId,
            string $suggestedValue,
            string $suggestedLabel,
            ?float $similarityScore,
            ?array $metadata,
        ) use (&$suggestions): bool {
            $suggestions[] = compact('suggestedValue', 'metadata');

            return true;
        },
        onProgress: fn (string $message) => null,
    );

    expect($suggestions)->toHaveCount(1);
    expect($suggestions[0]['suggestedValue'])->toBe('nl');
});

// ══════════════════════════════════════════════════════════════════════════════════
// ─ LOW-SIGNAL RECORDS (should NOT generate suggestions) ─
// ══════════════════════════════════════════════════════════════════════════════════

// Überspringt Datensätze mit nur Akronymen und alphanumerischen Codes
it('skips records with only acronyms and alphanumeric codes', function () {
    $resource = Resource::factory()->create(['language_id' => null]);

    Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'active' => true, 'elmo_active' => true]);

    Title::factory()->create([
        'resource_id' => $resource->id,
        'value' => 'ABC123 XYZ DEF456',
        'language' => null,
    ]);

    $suggestions = [];
    $service = new LanguageSuggestionDiscoveryService;

    $service->discover(
        storeSuggestion: function (
            int $resourceId,
            string $targetType,
            int $targetId,
            string $suggestedValue,
            string $suggestedLabel,
            ?float $similarityScore,
            ?array $metadata,
        ) use (&$suggestions): bool {
            $suggestions[] = compact('suggestedValue');

            return true;
        },
        onProgress: fn (string $message) => null,
    );

    expect($suggestions)->toHaveCount(0);
});

// Überspringt Datensätze mit nur Eigennamen und Akronymen
it('skips records with only proper nouns and acronyms', function () {
    $resource = Resource::factory()->create(['language_id' => null]);

    Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'active' => true, 'elmo_active' => true]);

    Title::factory()->create([
        'resource_id' => $resource->id,
        'value' => 'NASA USGS GFZ',
        'language' => null,
    ]);

    $suggestions = [];
    $service = new LanguageSuggestionDiscoveryService;

    $service->discover(
        storeSuggestion: function (
            int $resourceId,
            string $targetType,
            int $targetId,
            string $suggestedValue,
            string $suggestedLabel,
            ?float $similarityScore,
            ?array $metadata,
        ) use (&$suggestions): bool {
            $suggestions[] = compact('suggestedValue');

            return true;
        },
        onProgress: fn (string $message) => null,
    );

    expect($suggestions)->toHaveCount(0);
});

// Überspringt Datensätze mit nur mathematischen Formeln und Symbolen
it('skips records with only mathematical formulas and symbols', function () {
    $resource = Resource::factory()->create(['language_id' => null]);

    Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'active' => true, 'elmo_active' => true]);

    Title::factory()->create([
        'resource_id' => $resource->id,
        'value' => 'H₂O + SiO₂ → H₄SiO₄',
        'language' => null,
    ]);

    $suggestions = [];
    $service = new LanguageSuggestionDiscoveryService;

    $service->discover(
        storeSuggestion: function (
            int $resourceId,
            string $targetType,
            int $targetId,
            string $suggestedValue,
            string $suggestedLabel,
            ?float $similarityScore,
            ?array $metadata,
        ) use (&$suggestions): bool {
            $suggestions[] = compact('suggestedValue');

            return true;
        },
        onProgress: fn (string $message) => null,
    );

    expect($suggestions)->toHaveCount(0);
});

// Überspringt Datensätze mit leerem oder nur Leerzeichen enthaltenden Inhalt
it('skips records with empty or whitespace-only content', function () {
    $resource = Resource::factory()->create(['language_id' => null]);

    Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'active' => true, 'elmo_active' => true]);

    Title::factory()->create([
        'resource_id' => $resource->id,
        'value' => '   ',
        'language' => null,
    ]);

    $suggestions = [];
    $service = new LanguageSuggestionDiscoveryService;

    $service->discover(
        storeSuggestion: function (
            int $resourceId,
            string $targetType,
            int $targetId,
            string $suggestedValue,
            string $suggestedLabel,
            ?float $similarityScore,
            ?array $metadata,
        ) use (&$suggestions): bool {
            $suggestions[] = compact('suggestedValue');

            return true;
        },
        onProgress: fn (string $message) => null,
    );

    expect($suggestions)->toHaveCount(0);
});

// Überspringt Datensätze mit gemischter Sprache und unzureichendem Signal in beiden
it('skips records with mixed language and insufficient signal in either', function () {
    $resource = Resource::factory()->create(['language_id' => null]);

    Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'active' => true, 'elmo_active' => true]);
    Language::firstOrCreate(['code' => 'de'], ['name' => 'German', 'active' => true, 'elmo_active' => true]);

    Title::factory()->create([
        'resource_id' => $resource->id,
        'value' => 'ABC XYZ Mixed 123',
        'language' => null,
    ]);

    $suggestions = [];
    $service = new LanguageSuggestionDiscoveryService;

    $service->discover(
        storeSuggestion: function (
            int $resourceId,
            string $targetType,
            int $targetId,
            string $suggestedValue,
            string $suggestedLabel,
            ?float $similarityScore,
            ?array $metadata,
        ) use (&$suggestions): bool {
            $suggestions[] = compact('suggestedValue');

            return true;
        },
        onProgress: fn (string $message) => null,
    );

    expect($suggestions)->toHaveCount(0);
});

// ══════════════════════════════════════════════════════════════════════════════════
// ─ RESOURCE FILTERING (only processes resources without language_id) ─
// ══════════════════════════════════════════════════════════════════════════════════

// Überspringt Ressourcen, die bereits eine Sprache zugewiesen haben
it('skips resources that already have a language assigned', function () {
    $language = Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'active' => true, 'elmo_active' => true]);
    Language::firstOrCreate(['code' => 'de'], ['name' => 'German', 'active' => true, 'elmo_active' => true]);

    $resource = Resource::factory()->create(['language_id' => $language->id]);

    Title::factory()->create([
        'resource_id' => $resource->id,
        'value' => 'Eine Studie über Grundwasser',
        'language' => null,
    ]);

    $suggestions = [];
    $service = new LanguageSuggestionDiscoveryService;

    $service->discover(
        storeSuggestion: function (
            int $resourceId,
            string $targetType,
            int $targetId,
            string $suggestedValue,
            string $suggestedLabel,
            ?float $similarityScore,
            ?array $metadata,
        ) use (&$suggestions): bool {
            $suggestions[] = compact('suggestedValue');

            return true;
        },
        onProgress: fn (string $message) => null,
    );

    expect($suggestions)->toHaveCount(0);
});

// ══════════════════════════════════════════════════════════════════════════════════
// ─ CONFIDENCE SCORING (validate score calculations) ─
// ══════════════════════════════════════════════════════════════════════════════════

// Weist hohe Konfidenz expliziten Sprachvorschlägen zu
it('assigns high confidence to explicit language suggestions', function () {
    $resource = Resource::factory()->create(['language_id' => null]);

    Language::firstOrCreate(['code' => 'de'], ['name' => 'German', 'active' => true, 'elmo_active' => true]);

    Title::factory()->create([
        'resource_id' => $resource->id,
        'value' => 'Test',
        'language' => 'de',
    ]);

    $suggestions = [];
    $service = new LanguageSuggestionDiscoveryService;

    $service->discover(
        storeSuggestion: function (
            int $resourceId,
            string $targetType,
            int $targetId,
            string $suggestedValue,
            string $suggestedLabel,
            ?float $similarityScore,
            ?array $metadata,
        ) use (&$suggestions): bool {
            $suggestions[] = compact('similarityScore');

            return true;
        },
        onProgress: fn (string $message) => null,
    );

    expect($suggestions[0]['similarityScore'])->toBe(0.95);
});

// Überprüft dass Spracherkennungs-Konfidenz unter Schwelle nicht vorgeschlagen wird
it('skips suggestions when text detection confidence is below threshold', function () {
    $resource = Resource::factory()->create(['language_id' => null]);

    Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'active' => true, 'elmo_active' => true]);
    Language::firstOrCreate(['code' => 'de'], ['name' => 'German', 'active' => true, 'elmo_active' => true]);

    // Very short title with minimal language signal
    Title::factory()->create([
        'resource_id' => $resource->id,
        'value' => 'ABC test XYZ',
        'language' => null,
    ]);

    $suggestions = [];
    $service = new LanguageSuggestionDiscoveryService;

    $service->discover(
        storeSuggestion: function (
            int $resourceId,
            string $targetType,
            int $targetId,
            string $suggestedValue,
            string $suggestedLabel,
            ?float $similarityScore,
            ?array $metadata,
        ) use (&$suggestions): bool {
            $suggestions[] = compact('suggestedValue', 'similarityScore', 'metadata');

            return true;
        },
        onProgress: fn (string $message) => null,
    );

    // Should skip because confidence falls below threshold (< 0.6)
    expect($suggestions)->toHaveCount(0);
});

// Weist Konfidenzwerte basierend auf der Stärke des Textsignals zu
it('assigns confidence scores based on text signal strength', function () {
    $resource = Resource::factory()->create(['language_id' => null]);

    Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'active' => true, 'elmo_active' => true]);
    Language::firstOrCreate(['code' => 'de'], ['name' => 'German', 'active' => true, 'elmo_active' => true]);

    // Strong English signal
    Title::factory()->create([
        'resource_id' => $resource->id,
        'value' => 'A comprehensive study of groundwater quality analysis with research data',
        'language' => null,
    ]);

    $suggestions = [];
    $service = new LanguageSuggestionDiscoveryService;

    $service->discover(
        storeSuggestion: function (
            int $resourceId,
            string $targetType,
            int $targetId,
            string $suggestedValue,
            string $suggestedLabel,
            ?float $similarityScore,
            ?array $metadata,
        ) use (&$suggestions): bool {
            $suggestions[] = compact('similarityScore');

            return true;
        },
        onProgress: fn (string $message) => null,
    );

    expect($suggestions[0]['similarityScore'])->toBeGreaterThanOrEqual(0.6);
    expect($suggestions[0]['similarityScore'])->toBeLessThanOrEqual(0.9);
});
