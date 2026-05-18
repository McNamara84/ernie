<?php

declare(strict_types=1);

use App\Enums\CacheKey;
use App\Models\LandingPage;
use App\Models\Resource;
use App\Models\ResourceType;
use App\Models\Subject;
use App\Services\KeywordSuggestionService;
use App\Support\GemetVocabularyParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

covers(KeywordSuggestionService::class);

uses(RefreshDatabase::class);

/**
 * @param  array<int, string>  $tags
 * @param  mixed  $value
 */
function putCacheValue(array $tags, string $key, mixed $value): void
{
    if (method_exists(Cache::getStore(), 'tags')) {
        Cache::tags($tags)->put($key, $value);

        return;
    }

    Cache::put($key, $value);
}

beforeEach(function () {
    Cache::flush();
    $this->service = app(KeywordSuggestionService::class);

    $this->datasetType = ResourceType::factory()->create([
        'name' => 'Dataset',
        'slug' => 'dataset',
    ]);
});

/**
 * Create a published resource with subjects for testing.
 *
 * @param  array<int, array{value: string, subject_scheme?: string|null, value_uri?: string|null}>  $subjects
 */
function createResourceWithSubjects(ResourceType $type, array $subjects): Resource
{
    $resource = Resource::factory()->create([
        'resource_type_id' => $type->id,
    ]);

    LandingPage::factory()->published()->create([
        'resource_id' => $resource->id,
    ]);

    foreach ($subjects as $subjectData) {
        Subject::factory()->create([
            'resource_id' => $resource->id,
            'value' => $subjectData['value'],
            'subject_scheme' => $subjectData['subject_scheme'] ?? null,
            'value_uri' => $subjectData['value_uri'] ?? null,
        ]);
    }

    return $resource;
}

it('returns empty array when no published resources exist', function () {
    $suggestions = $this->service->getSuggestions();

    expect($suggestions)->toBeEmpty();
});

it('returns keywords from published resources', function () {
    createResourceWithSubjects($this->datasetType, [
        ['value' => 'Seismology'],
        ['value' => 'GNSS', 'subject_scheme' => 'Science Keywords'],
    ]);

    $suggestions = $this->service->getSuggestions();

    expect($suggestions)->toHaveCount(1);
    expect(array_column($suggestions, 'value'))->toContain('Seismology');
});

it('excludes keywords from unpublished resources', function () {
    // Published resource
    createResourceWithSubjects($this->datasetType, [
        ['value' => 'PublishedKeyword'],
    ]);

    // Unpublished resource
    $draftResource = Resource::factory()->create([
        'resource_type_id' => $this->datasetType->id,
    ]);
    LandingPage::factory()->draft()->create([
        'resource_id' => $draftResource->id,
    ]);
    Subject::factory()->create([
        'resource_id' => $draftResource->id,
        'value' => 'DraftKeyword',
    ]);

    $suggestions = $this->service->getSuggestions();

    expect($suggestions)->toHaveCount(1);
    expect($suggestions[0]['value'])->toBe('PublishedKeyword');
});

it('deduplicates keywords and counts usage', function () {
    // Same keyword on two different published resources
    createResourceWithSubjects($this->datasetType, [
        ['value' => 'Seismology'],
    ]);

    createResourceWithSubjects($this->datasetType, [
        ['value' => 'Seismology'],
    ]);

    $suggestions = $this->service->getSuggestions();

    expect($suggestions)->toHaveCount(1);
    expect($suggestions[0]['value'])->toBe('Seismology');
    expect($suggestions[0]['count'])->toBe(2);
});

it('returns only free keyword suggestions when controlled schemes are also present', function () {
    createResourceWithSubjects($this->datasetType, [
        ['value' => 'Free Keyword'],
        ['value' => 'GNSS', 'subject_scheme' => 'Science Keywords'],
        ['value' => 'Geochemistry', 'subject_scheme' => 'EPOS MSL vocabulary'],
    ]);

    $suggestions = $this->service->getSuggestions();

    expect($suggestions)->toHaveCount(1);

    $freeKeyword = collect($suggestions)->firstWhere('value', 'Free Keyword');
    expect($freeKeyword['scheme'])->toBeNull();
});

it('sorts suggestions alphabetically by value', function () {
    createResourceWithSubjects($this->datasetType, [
        ['value' => 'Zircon'],
        ['value' => 'Alpine'],
        ['value' => 'Magnetism'],
    ]);

    $suggestions = $this->service->getSuggestions();

    expect($suggestions[0]['value'])->toBe('Alpine');
    expect($suggestions[1]['value'])->toBe('Magnetism');
    expect($suggestions[2]['value'])->toBe('Zircon');
});

it('returns the free-keyword variant when the same value also exists as a controlled term', function () {
    createResourceWithSubjects($this->datasetType, [
        ['value' => 'Geochemistry', 'subject_scheme' => null],
        ['value' => 'Geochemistry', 'subject_scheme' => 'EPOS MSL vocabulary'],
    ]);

    $suggestions = $this->service->getSuggestions();

    expect($suggestions)->toHaveCount(1);
    expect($suggestions[0]['value'])->toBe('Geochemistry');
    expect($suggestions[0]['scheme'])->toBeNull();
});

it('builds pruned thesaurus facets from published controlled keywords', function () {
    Storage::fake('local');
    Storage::disk('local')->put('gcmd-science-keywords.json', json_encode([
        'lastUpdated' => now()->toIso8601String(),
        'data' => [[
            'id' => 'science-root',
            'text' => 'Science Keywords',
            'language' => 'en',
            'scheme' => 'NASA/GCMD Earth Science Keywords',
            'schemeURI' => 'https://example.test/science',
            'description' => '',
            'children' => [[
                'id' => 'science-earth',
                'text' => 'EARTH SCIENCE',
                'language' => 'en',
                'scheme' => 'NASA/GCMD Earth Science Keywords',
                'schemeURI' => 'https://example.test/science',
                'description' => '',
                'children' => [[
                    'id' => 'science-gnss',
                    'text' => 'GNSS',
                    'language' => 'en',
                    'scheme' => 'NASA/GCMD Earth Science Keywords',
                    'schemeURI' => 'https://example.test/science',
                    'description' => '',
                    'children' => [],
                ], [
                    'id' => 'science-unused',
                    'text' => 'Unused child',
                    'language' => 'en',
                    'scheme' => 'NASA/GCMD Earth Science Keywords',
                    'schemeURI' => 'https://example.test/science',
                    'description' => '',
                    'children' => [],
                ]],
            ]],
        ]],
    ], JSON_THROW_ON_ERROR));

    Storage::disk('local')->put('msl-vocabulary.json', json_encode([[ 
        'id' => 'msl-root',
        'text' => 'Material',
        'language' => 'en',
        'scheme' => 'EPOS MSL vocabulary',
        'schemeURI' => 'https://example.test/msl',
        'description' => '',
        'children' => [[
            'id' => 'msl-rock',
            'text' => 'Rock',
            'language' => 'en',
            'scheme' => 'EPOS MSL vocabulary',
            'schemeURI' => 'https://example.test/msl',
            'description' => '',
            'children' => [],
        ]],
    ]], JSON_THROW_ON_ERROR));

    createResourceWithSubjects($this->datasetType, [
        ['value' => 'GNSS', 'subject_scheme' => 'Science Keywords', 'value_uri' => 'science-gnss'],
        ['value' => 'Rock', 'subject_scheme' => 'EPOS MSL vocabulary', 'value_uri' => 'msl-rock'],
    ]);

    $facets = $this->service->getThesaurusFacets();

    expect($facets)->toHaveCount(2)
        ->and($facets[0]['scheme'])->toBe('Science Keywords')
        ->and($facets[0]['roots'][0]['children'][0]['children'])->toHaveCount(1)
        ->and($facets[0]['roots'][0]['children'][0]['children'][0]['text'])->toBe('GNSS')
        ->and($facets[1]['scheme'])->toBe('EPOS MSL vocabulary')
        ->and($facets[1]['roots'][0]['children'][0]['text'])->toBe('Rock');
});

it('builds pruned thesaurus facets from breadcrumb controlled keywords without value uri', function () {
    Storage::fake('local');
    Storage::disk('local')->put('gcmd-science-keywords.json', json_encode([
        'lastUpdated' => now()->toIso8601String(),
        'data' => [[
            'id' => 'science-root',
            'text' => 'Science Keywords',
            'language' => 'en',
            'scheme' => 'NASA/GCMD Earth Science Keywords',
            'schemeURI' => 'https://example.test/science',
            'description' => '',
            'children' => [[
                'id' => 'science-earth',
                'text' => 'EARTH SCIENCE',
                'language' => 'en',
                'scheme' => 'NASA/GCMD Earth Science Keywords',
                'schemeURI' => 'https://example.test/science',
                'description' => '',
                'children' => [[
                    'id' => 'science-solid-earth',
                    'text' => 'SOLID EARTH',
                    'language' => 'en',
                    'scheme' => 'NASA/GCMD Earth Science Keywords',
                    'schemeURI' => 'https://example.test/science',
                    'description' => '',
                    'children' => [[
                        'id' => 'science-seismology',
                        'text' => 'SEISMOLOGY',
                        'language' => 'en',
                        'scheme' => 'NASA/GCMD Earth Science Keywords',
                        'schemeURI' => 'https://example.test/science',
                        'description' => '',
                        'children' => [],
                    ]],
                ]],
            ]],
        ]],
    ], JSON_THROW_ON_ERROR));

    createResourceWithSubjects($this->datasetType, [
        ['value' => 'EARTH SCIENCE > SOLID EARTH > SEISMOLOGY', 'subject_scheme' => 'GCMD Science Keywords', 'value_uri' => null],
    ]);

    $facets = $this->service->getThesaurusFacets();

    expect($facets)->toHaveCount(1)
        ->and($facets[0]['scheme'])->toBe('Science Keywords')
        ->and($facets[0]['roots'][0]['children'][0]['text'])->toBe('EARTH SCIENCE')
        ->and($facets[0]['roots'][0]['children'][0]['children'][0]['text'])->toBe('SOLID EARTH')
        ->and($facets[0]['roots'][0]['children'][0]['children'][0]['children'][0]['text'])->toBe('SEISMOLOGY');
});

it('keeps ancestors when only descendant terms are used', function () {
    Storage::fake('local');
    Storage::disk('local')->put('gcmd-platforms.json', json_encode([
        'lastUpdated' => now()->toIso8601String(),
        'data' => [[
            'id' => 'platforms-root',
            'text' => 'Platforms',
            'language' => 'en',
            'scheme' => 'NASA/GCMD Platforms',
            'schemeURI' => 'https://example.test/platforms',
            'description' => '',
            'children' => [[
                'id' => 'platforms-space',
                'text' => 'Space-based Platforms',
                'language' => 'en',
                'scheme' => 'NASA/GCMD Platforms',
                'schemeURI' => 'https://example.test/platforms',
                'description' => '',
                'children' => [[
                    'id' => 'platforms-voyager',
                    'text' => 'VOYAGER 1',
                    'language' => 'en',
                    'scheme' => 'NASA/GCMD Platforms',
                    'schemeURI' => 'https://example.test/platforms',
                    'description' => '',
                    'children' => [],
                ]],
            ]],
        ]],
    ], JSON_THROW_ON_ERROR));

    createResourceWithSubjects($this->datasetType, [
        ['value' => 'VOYAGER 1', 'subject_scheme' => 'Platforms', 'value_uri' => 'platforms-voyager'],
    ]);

    $facets = $this->service->getThesaurusFacets();

    expect($facets)->toHaveCount(1)
        ->and($facets[0]['roots'][0]['text'])->toBe('Platforms')
        ->and($facets[0]['roots'][0]['children'][0]['text'])->toBe('Space-based Platforms')
        ->and($facets[0]['roots'][0]['children'][0]['children'][0]['text'])->toBe('VOYAGER 1');
});

it('resolves selected thesaurus nodes to descendant IDs and values', function () {
    Storage::fake('local');
    Storage::disk('local')->put('gcmd-science-keywords.json', json_encode([
        'lastUpdated' => now()->toIso8601String(),
        'data' => [[
            'id' => 'science-root',
            'text' => 'Science Keywords',
            'language' => 'en',
            'scheme' => 'NASA/GCMD Earth Science Keywords',
            'schemeURI' => 'https://example.test/science',
            'description' => '',
            'children' => [[
                'id' => 'science-earth',
                'text' => 'EARTH SCIENCE',
                'language' => 'en',
                'scheme' => 'NASA/GCMD Earth Science Keywords',
                'schemeURI' => 'https://example.test/science',
                'description' => '',
                'children' => [[
                    'id' => 'science-gnss',
                    'text' => 'GNSS',
                    'language' => 'en',
                    'scheme' => 'NASA/GCMD Earth Science Keywords',
                    'schemeURI' => 'https://example.test/science',
                    'description' => '',
                    'children' => [],
                ]],
            ]],
        ]],
    ], JSON_THROW_ON_ERROR));

    createResourceWithSubjects($this->datasetType, [
        ['value' => 'GNSS', 'subject_scheme' => 'Science Keywords', 'value_uri' => 'science-gnss'],
    ]);

    $resolved = $this->service->resolveSelectedThesaurusNodes(['science-earth']);

    expect($resolved)->toHaveCount(1)
        ->and($resolved[0]['scheme'])->toBe('Science Keywords')
        ->and($resolved[0]['descendant_ids'])->toBe(['science-earth', 'science-gnss'])
        ->and($resolved[0]['descendant_values'])->toBe([
            'Science Keywords > EARTH SCIENCE',
            'EARTH SCIENCE',
            'Science Keywords > EARTH SCIENCE > GNSS',
            'EARTH SCIENCE > GNSS',
            'GNSS',
        ]);
});

it('invalidates cached free keyword suggestions and thesaurus facets', function () {
    Storage::fake('local');
    Storage::disk('local')->put('gcmd-science-keywords.json', json_encode([
        'lastUpdated' => now()->toIso8601String(),
        'data' => [[
            'id' => 'science-root',
            'text' => 'Science Keywords',
            'language' => 'en',
            'scheme' => 'NASA/GCMD Earth Science Keywords',
            'schemeURI' => 'https://example.test/science',
            'description' => '',
            'children' => [[
                'id' => 'science-earth',
                'text' => 'EARTH SCIENCE',
                'language' => 'en',
                'scheme' => 'NASA/GCMD Earth Science Keywords',
                'schemeURI' => 'https://example.test/science',
                'description' => '',
                'children' => [[
                    'id' => 'science-gnss',
                    'text' => 'GNSS',
                    'language' => 'en',
                    'scheme' => 'NASA/GCMD Earth Science Keywords',
                    'schemeURI' => 'https://example.test/science',
                    'description' => '',
                    'children' => [],
                ], [
                    'id' => 'science-atmosphere',
                    'text' => 'ATMOSPHERE',
                    'language' => 'en',
                    'scheme' => 'NASA/GCMD Earth Science Keywords',
                    'schemeURI' => 'https://example.test/science',
                    'description' => '',
                    'children' => [],
                ]],
            ]],
        ]],
    ], JSON_THROW_ON_ERROR));

    createResourceWithSubjects($this->datasetType, [
        ['value' => 'Free A'],
        ['value' => 'GNSS', 'subject_scheme' => 'Science Keywords', 'value_uri' => 'science-gnss'],
    ]);

    createResourceWithSubjects($this->datasetType, [
        ['value' => 'Free B'],
        ['value' => 'ATMOSPHERE', 'subject_scheme' => 'Science Keywords', 'value_uri' => 'science-atmosphere'],
    ]);

    putCacheValue(CacheKey::PORTAL_KEYWORD_SUGGESTIONS->tags(), CacheKey::PORTAL_KEYWORD_SUGGESTIONS->key(), [[
        'value' => 'Stale Keyword',
        'scheme' => null,
        'count' => 99,
    ]]);
    CacheKey::PORTAL_THESAURUS_FACETS->forget();
    putCacheValue(CacheKey::PORTAL_THESAURUS_FACETS->tags(), CacheKey::PORTAL_THESAURUS_FACETS->key(), [[
        'scheme' => 'Stale Scheme',
        'roots' => [],
    ]]);

    expect(array_column($this->service->getFreeKeywordSuggestions(), 'value'))->toBe(['Stale Keyword'])
        ->and($this->service->getThesaurusFacets()[0]['scheme'])->toBe('Stale Scheme');

    $this->service->invalidateCache();

    $updatedSuggestions = $this->service->getFreeKeywordSuggestions();
    $updatedFacets = $this->service->getThesaurusFacets();

    expect(array_column($updatedSuggestions, 'value'))->toEqualCanonicalizing(['Free A', 'Free B'])
        ->and($updatedFacets[0]['roots'][0]['children'][0]['children'])->toHaveCount(2);
});

it('normalizes additional thesaurus schemes and preserves notation values', function () {
    Storage::fake('local');
    Storage::disk('local')->put('gcmd-instruments.json', json_encode([[
        'id' => 'instrument-root',
        'text' => 'Mass Spectrometer',
        'language' => 'en',
        'scheme' => 'GCMD Instrument Concepts',
        'schemeURI' => 'https://example.test/instruments',
        'description' => '',
        'notation' => 101,
        'children' => [],
    ]], JSON_THROW_ON_ERROR));
    Storage::disk('local')->put('chronostrat-timescale.json', json_encode([[
        'id' => 'chrono-root',
        'text' => 'Holocene',
        'language' => 'en',
        'scheme' => 'Chronostrat Timescale',
        'schemeURI' => 'https://example.test/chronostrat',
        'description' => '',
        'children' => [],
    ]], JSON_THROW_ON_ERROR));
    Storage::disk('local')->put('gemet-thesaurus.json', json_encode([[
        'id' => 'gemet-root',
        'text' => 'Soil',
        'language' => 'en',
        'scheme' => 'GEMET thesaurus',
        'schemeURI' => 'https://example.test/gemet',
        'description' => '',
        'children' => [],
    ]], JSON_THROW_ON_ERROR));
    Storage::disk('local')->put('analytical-methods.json', json_encode([[
        'id' => 'analytical-root',
        'text' => 'Mass Spectrometry',
        'language' => 'en',
        'scheme' => 'Analytical Method Vocabulary',
        'schemeURI' => 'https://example.test/analytical',
        'description' => '',
        'notation' => 'A-7',
        'children' => [],
    ]], JSON_THROW_ON_ERROR));
    Storage::disk('local')->put('euroscivoc.json', json_encode([[
        'id' => 'euroscivoc-root',
        'text' => 'Mathematics',
        'language' => 'en',
        'scheme' => 'EuroSciVoc',
        'schemeURI' => 'https://example.test/euroscivoc',
        'description' => '',
        'children' => [],
    ]], JSON_THROW_ON_ERROR));

    createResourceWithSubjects($this->datasetType, [
        ['value' => 'Mass Spectrometer', 'subject_scheme' => 'Instruments', 'value_uri' => 'instrument-root'],
        ['value' => 'Holocene', 'subject_scheme' => 'International Chronostratigraphic Chart', 'value_uri' => 'chrono-root'],
        ['value' => 'Soil', 'subject_scheme' => GemetVocabularyParser::SCHEME_TITLE, 'value_uri' => 'gemet-root'],
        ['value' => 'Mass Spectrometry', 'subject_scheme' => 'Analytical Methods for Geochemistry and Cosmochemistry', 'value_uri' => 'analytical-root'],
        ['value' => 'Mathematics', 'subject_scheme' => 'European Science Vocabulary (EuroSciVoc)', 'value_uri' => 'euroscivoc-root'],
    ]);

    $facetsByScheme = collect($this->service->getThesaurusFacets())->keyBy('scheme');

    expect($facetsByScheme->keys()->all())->toEqualCanonicalizing([
        'Instruments',
        'International Chronostratigraphic Chart',
        GemetVocabularyParser::SCHEME_TITLE,
        'Analytical Methods for Geochemistry and Cosmochemistry',
        'European Science Vocabulary (EuroSciVoc)',
    ]);

    expect($facetsByScheme->get('Instruments')['roots'][0]['notation'])->toBe('101')
        ->and($facetsByScheme->get('Analytical Methods for Geochemistry and Cosmochemistry')['roots'][0]['notation'])->toBe('A-7');
});

it('skips invalid vocabulary payloads and non-array roots', function () {
    Storage::fake('local');
    Storage::disk('local')->put('gcmd-science-keywords.json', '{invalid json');
    Storage::disk('local')->put('gcmd-platforms.json', json_encode([
        'data' => 'not-an-array',
    ], JSON_THROW_ON_ERROR));
    Storage::disk('local')->put('gcmd-instruments.json', json_encode([
        'data' => [
            'skip-me',
            [
                'id' => 'instrument-root',
                'text' => 'Instrument Node',
                'language' => 'en',
                'scheme' => 'GCMD Instrument Concepts',
                'schemeURI' => 'https://example.test/instruments',
                'description' => '',
                'children' => 'not-an-array',
            ],
        ],
    ], JSON_THROW_ON_ERROR));

    createResourceWithSubjects($this->datasetType, [
        ['value' => 'Instrument Node', 'subject_scheme' => 'Instruments', 'value_uri' => 'instrument-root'],
    ]);

    $facets = $this->service->getThesaurusFacets();

    expect($facets)->toHaveCount(1)
        ->and($facets[0]['scheme'])->toBe('Instruments')
        ->and($facets[0]['roots'][0]['text'])->toBe('Instrument Node');
});

it('ignores blank selected thesaurus node ids while resolving descendants', function () {
    Storage::fake('local');
    Storage::disk('local')->put('gcmd-science-keywords.json', json_encode([
        'lastUpdated' => now()->toIso8601String(),
        'data' => [[
            'id' => 'science-root',
            'text' => 'Science Keywords',
            'language' => 'en',
            'scheme' => 'NASA/GCMD Earth Science Keywords',
            'schemeURI' => 'https://example.test/science',
            'description' => '',
            'children' => [[
                'id' => 'science-earth',
                'text' => 'EARTH SCIENCE',
                'language' => 'en',
                'scheme' => 'NASA/GCMD Earth Science Keywords',
                'schemeURI' => 'https://example.test/science',
                'description' => '',
                'children' => [],
            ]],
        ]],
    ], JSON_THROW_ON_ERROR));

    createResourceWithSubjects($this->datasetType, [
        ['value' => 'EARTH SCIENCE', 'subject_scheme' => 'Science Keywords', 'value_uri' => 'science-earth'],
    ]);

    $resolved = $this->service->resolveSelectedThesaurusNodes([' science-earth ', '', 'science-earth', '   ']);

    expect($resolved)->toHaveCount(1)
        ->and($resolved[0]['id'])->toBe('science-earth');
});

it('resolves selected thesaurus nodes with matching raw subject scheme aliases', function () {
    Storage::fake('local');
    Storage::disk('local')->put('gcmd-science-keywords.json', json_encode([
        'lastUpdated' => now()->toIso8601String(),
        'data' => [[
            'id' => 'science-root',
            'text' => 'Science Keywords',
            'language' => 'en',
            'scheme' => 'NASA/GCMD Earth Science Keywords',
            'schemeURI' => 'https://example.test/science',
            'description' => '',
            'children' => [[
                'id' => 'science-gnss',
                'text' => 'GNSS',
                'language' => 'en',
                'scheme' => 'NASA/GCMD Earth Science Keywords',
                'schemeURI' => 'https://example.test/science',
                'description' => '',
                'children' => [],
            ]],
        ]],
    ], JSON_THROW_ON_ERROR));

    createResourceWithSubjects($this->datasetType, [
        ['value' => 'GNSS', 'subject_scheme' => 'NASA/GCMD Earth Science Keywords', 'value_uri' => 'science-gnss'],
    ]);

    $resolved = $this->service->resolveSelectedThesaurusNodes(['science-gnss']);

    expect($resolved)->toHaveCount(1)
        ->and($resolved[0]['scheme'])->toBe('Science Keywords')
        ->and($resolved[0]['subject_schemes'])->toContain('NASA/GCMD Earth Science Keywords');
});

it('invalidates the cached controlled subject index together with thesaurus facets', function () {
    Storage::fake('local');
    Storage::disk('local')->put('gcmd-science-keywords.json', json_encode([
        'lastUpdated' => now()->toIso8601String(),
        'data' => [[
            'id' => 'science-root',
            'text' => 'Science Keywords',
            'language' => 'en',
            'scheme' => 'NASA/GCMD Earth Science Keywords',
            'schemeURI' => 'https://example.test/science',
            'description' => '',
            'children' => [[
                'id' => 'science-gnss',
                'text' => 'GNSS',
                'language' => 'en',
                'scheme' => 'NASA/GCMD Earth Science Keywords',
                'schemeURI' => 'https://example.test/science',
                'description' => '',
                'children' => [],
            ]],
        ]],
    ], JSON_THROW_ON_ERROR));

    createResourceWithSubjects($this->datasetType, [
        ['value' => 'GNSS', 'subject_scheme' => 'Science Keywords', 'value_uri' => 'science-gnss'],
    ]);

    CacheKey::PORTAL_THESAURUS_FACETS->forget();
    putCacheValue(CacheKey::PORTAL_THESAURUS_SUBJECT_INDEX->tags(), CacheKey::PORTAL_THESAURUS_SUBJECT_INDEX->key(), [
        'Science Keywords' => [
            'ids' => ['science-missing' => true],
            'values' => [],
            'schemes' => ['Science Keywords' => true],
        ],
    ]);

    expect($this->service->getThesaurusFacets())->toBe([]);

    $this->service->invalidateCache();

    $facets = $this->service->getThesaurusFacets();

    expect($facets)->toHaveCount(1)
        ->and($facets[0]['roots'][0]['children'][0]['text'])->toBe('GNSS');
});
