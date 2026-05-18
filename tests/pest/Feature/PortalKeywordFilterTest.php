<?php

declare(strict_types=1);

use App\Http\Controllers\PortalController;
use App\Models\LandingPage;
use App\Models\Resource;
use App\Models\ResourceType;
use App\Models\Subject;
use App\Models\Title;
use App\Models\TitleType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\withoutVite;

covers(PortalController::class);

uses(RefreshDatabase::class);

beforeEach(function () {
    withoutVite();
    Cache::flush();
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
                    'id' => 'science-seismology',
                    'text' => 'Seismology',
                    'language' => 'en',
                    'scheme' => 'NASA/GCMD Earth Science Keywords',
                    'schemeURI' => 'https://example.test/science',
                    'description' => '',
                    'children' => [],
                ]],
            ]],
        ]],
    ], JSON_THROW_ON_ERROR));

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
                'id' => 'platforms-voyager',
                'text' => 'VOYAGER 1',
                'language' => 'en',
                'scheme' => 'NASA/GCMD Platforms',
                'schemeURI' => 'https://example.test/platforms',
                'description' => '',
                'children' => [],
            ]],
        ]],
    ], JSON_THROW_ON_ERROR));

    $this->datasetType = ResourceType::factory()->create([
        'name' => 'Dataset',
        'slug' => 'dataset',
    ]);

    $this->mainTitleType = TitleType::firstOrCreate(
        ['slug' => 'MainTitle'],
        ['name' => 'Main Title'],
    );
});

/**
 * Helper: Create a published resource with title and optional subjects.
 *
 * @param  array<int, array{value: string, subject_scheme?: string|null, value_uri?: string|null}>  $subjects
 */
function createPublishedResourceWithKeywords(
    ResourceType $type,
    string $title,
    array $subjects = [],
): Resource {
    $mainTitleType = TitleType::firstOrCreate(
        ['slug' => 'MainTitle'],
        ['name' => 'Main Title'],
    );

    $resource = Resource::factory()->create([
        'resource_type_id' => $type->id,
        'publication_year' => 2024,
    ]);

    Title::factory()->create([
        'resource_id' => $resource->id,
        'title_type_id' => $mainTitleType->id,
        'value' => $title,
    ]);

    LandingPage::factory()->create([
        'resource_id' => $resource->id,
        'is_published' => true,
        'published_at' => now(),
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

describe('Portal Keyword Filter', function () {
    it('returns keyword suggestions with the portal page', function () {
        createPublishedResourceWithKeywords(
            $this->datasetType,
            'Seismic Analysis',
            [
                ['value' => 'Seismology'],
                ['value' => 'GNSS', 'subject_scheme' => 'Science Keywords', 'value_uri' => 'science-gnss'],
            ],
        );

        $this->get(route('portal'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('keywordSuggestions')
                ->has('keywordSuggestions', 1)
                ->where('keywordSuggestions.0.value', 'Seismology')
            );
    });

    it('returns thesaurus facets with the portal page', function () {
        createPublishedResourceWithKeywords(
            $this->datasetType,
            'Controlled Study',
            [
                ['value' => 'GNSS', 'subject_scheme' => 'Science Keywords', 'value_uri' => 'science-gnss'],
            ],
        );

        $this->get(route('portal'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('thesaurusFacets', 1)
                ->where('thesaurusFacets.0.scheme', 'Science Keywords')
                ->where('thesaurusFacets.0.roots.0.children.0.children.0.text', 'GNSS')
            );
    });

    it('filters resources by a single keyword', function () {
        createPublishedResourceWithKeywords(
            $this->datasetType,
            'Earthquake Study',
            [['value' => 'Seismology']],
        );

        createPublishedResourceWithKeywords(
            $this->datasetType,
            'Weather Forecast',
            [['value' => 'Meteorology']],
        );

        $this->get(route('portal', ['keywords' => ['Seismology']]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('pagination.total', 1)
                ->where('resources.0.title', 'Earthquake Study')
                ->where('filters.keywords', ['Seismology'])
            );
    });

    it('filters resources by multiple keywords with AND logic', function () {
        // Resource with both keywords
        createPublishedResourceWithKeywords(
            $this->datasetType,
            'Complete Study',
            [
                ['value' => 'Seismology'],
                ['value' => 'GNSS'],
            ],
        );

        // Resource with only one keyword
        createPublishedResourceWithKeywords(
            $this->datasetType,
            'Partial Study',
            [['value' => 'Seismology']],
        );

        // Only the resource with BOTH keywords should match
        $this->get(route('portal', ['keywords' => ['Seismology', 'GNSS']]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('pagination.total', 1)
                ->where('resources.0.title', 'Complete Study')
            );
    });

    it('combines keyword filter with text search', function () {
        createPublishedResourceWithKeywords(
            $this->datasetType,
            'Earthquake Analysis',
            [['value' => 'Seismology']],
        );

        createPublishedResourceWithKeywords(
            $this->datasetType,
            'Earthquake Overview',
            [['value' => 'Meteorology']],
        );

        // Search for "Earthquake" + filter for "Seismology": only the first should match
        $this->get(route('portal', ['q' => 'Earthquake', 'keywords' => ['Seismology']]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('pagination.total', 1)
                ->where('resources.0.title', 'Earthquake Analysis')
            );
    });

    it('combines keyword filter with type filter', function () {
        $physicalObjectType = ResourceType::factory()->create([
            'name' => 'PhysicalObject',
            'slug' => 'physical-object',
        ]);

        createPublishedResourceWithKeywords(
            $this->datasetType,
            'Dataset with Seismology',
            [['value' => 'Seismology']],
        );

        createPublishedResourceWithKeywords(
            $physicalObjectType,
            'Sample with Seismology',
            [['value' => 'Seismology']],
        );

        // Filter for keyword "Seismology" + type "dataset" → only dataset
        $this->get(route('portal', ['keywords' => ['Seismology'], 'type' => 'dataset']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('pagination.total', 1)
                ->where('resources.0.title', 'Dataset with Seismology')
            );
    });

    it('returns empty results when no resource matches all keywords', function () {
        createPublishedResourceWithKeywords(
            $this->datasetType,
            'Study A',
            [['value' => 'Seismology']],
        );

        createPublishedResourceWithKeywords(
            $this->datasetType,
            'Study B',
            [['value' => 'GNSS']],
        );

        // No resource has BOTH keywords
        $this->get(route('portal', ['keywords' => ['Seismology', 'GNSS']]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('pagination.total', 0)
            );
    });

    it('ignores empty keywords array', function () {
        createPublishedResourceWithKeywords(
            $this->datasetType,
            'Any Study',
            [['value' => 'Seismology']],
        );

        // Empty keywords array should return all results
        $this->get(route('portal', ['keywords' => []]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('pagination.total', 1)
            );
    });

    it('searches in keywords with free text query', function () {
        createPublishedResourceWithKeywords(
            $this->datasetType,
            'Generic Title',
            [['value' => 'Paleoclimate']],
        );

        // Text search should also match keyword values
        $this->get(route('portal', ['q' => 'Paleoclimate']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('pagination.total', 1)
            );
    });

    it('filters resources by a single free keyword', function () {
        createPublishedResourceWithKeywords(
            $this->datasetType,
            'Free Keyword Match',
            [['value' => 'Seismology']],
        );

        createPublishedResourceWithKeywords(
            $this->datasetType,
            'Controlled Keyword Match',
            [['value' => 'Seismology', 'subject_scheme' => 'Science Keywords', 'value_uri' => 'science-seismology']],
        );

        $this->get(route('portal', ['free_keywords' => ['Seismology']]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('pagination.total', 1)
                ->where('resources.0.title', 'Free Keyword Match')
                ->where('filters.freeKeywords', ['Seismology'])
            );
    });

    it('filters resources by selected thesaurus parent nodes', function () {
        createPublishedResourceWithKeywords(
            $this->datasetType,
            'GNSS Dataset',
            [['value' => 'GNSS', 'subject_scheme' => 'Science Keywords', 'value_uri' => 'science-gnss']],
        );

        createPublishedResourceWithKeywords(
            $this->datasetType,
            'Free GNSS Mention',
            [['value' => 'GNSS']],
        );

        $this->get(route('portal', ['thesaurus_keywords' => ['science-earth']]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('pagination.total', 1)
                ->where('resources.0.title', 'GNSS Dataset')
                ->where('filters.thesaurusKeywords', ['science-earth'])
            );
    });

    it('matches imported thesaurus records that keep the original subject scheme string', function () {
        createPublishedResourceWithKeywords(
            $this->datasetType,
            'Imported GNSS Dataset',
            [['value' => 'GNSS', 'subject_scheme' => 'NASA/GCMD Earth Science Keywords', 'value_uri' => 'science-gnss']],
        );

        $this->get(route('portal', ['thesaurus_keywords' => ['science-earth']]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('pagination.total', 1)
                ->where('resources.0.title', 'Imported GNSS Dataset')
            );
    });

    it('applies AND logic across selected thesaurus nodes', function () {
        createPublishedResourceWithKeywords(
            $this->datasetType,
            'Complete Controlled Study',
            [
                ['value' => 'GNSS', 'subject_scheme' => 'Science Keywords', 'value_uri' => 'science-gnss'],
                ['value' => 'VOYAGER 1', 'subject_scheme' => 'Platforms', 'value_uri' => 'platforms-voyager'],
            ],
        );

        createPublishedResourceWithKeywords(
            $this->datasetType,
            'Only Science Study',
            [['value' => 'GNSS', 'subject_scheme' => 'Science Keywords', 'value_uri' => 'science-gnss']],
        );

        $this->get(route('portal', ['thesaurus_keywords' => ['science-earth', 'platforms-root']]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('pagination.total', 1)
                ->where('resources.0.title', 'Complete Controlled Study')
            );
    });

    it('returns empty results for unknown thesaurus node IDs', function () {
        createPublishedResourceWithKeywords(
            $this->datasetType,
            'Any Controlled Study',
            [['value' => 'GNSS', 'subject_scheme' => 'Science Keywords', 'value_uri' => 'science-gnss']],
        );

        $this->get(route('portal', ['thesaurus_keywords' => ['missing-node']]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('pagination.total', 0)
            );
    });
});

describe('Portal Keyword Suggestions', function () {
    it('only includes keywords from published resources', function () {
        // Published resource
        createPublishedResourceWithKeywords(
            $this->datasetType,
            'Published Study',
            [['value' => 'PublishedKeyword']],
        );

        // Unpublished resource (draft landing page)
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

        $this->get(route('portal'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('keywordSuggestions', 1)
                ->where('keywordSuggestions.0.value', 'PublishedKeyword')
            );
    });

    it('returns deduplicated keywords with usage count', function () {
        // Two resources with the same keyword
        createPublishedResourceWithKeywords(
            $this->datasetType,
            'Study A',
            [['value' => 'Seismology']],
        );

        createPublishedResourceWithKeywords(
            $this->datasetType,
            'Study B',
            [['value' => 'Seismology']],
        );

        $this->get(route('portal'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('keywordSuggestions', 1)
                ->where('keywordSuggestions.0.value', 'Seismology')
                ->where('keywordSuggestions.0.count', 2)
            );
    });

    it('includes subject scheme in suggestions', function () {
        createPublishedResourceWithKeywords(
            $this->datasetType,
            'GCMD Study',
            [
                ['value' => 'FreeKeyword'],
                ['value' => 'GNSS', 'subject_scheme' => 'Science Keywords', 'value_uri' => 'science-gnss'],
                ['value' => 'Geochemistry', 'subject_scheme' => 'EPOS MSL vocabulary'],
            ],
        );

        $this->get(route('portal'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('keywordSuggestions', 1)
                ->where('keywordSuggestions.0.value', 'FreeKeyword')
            );
    });
});
