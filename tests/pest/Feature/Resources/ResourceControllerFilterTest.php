<?php

use App\Models\Description;
use App\Models\DescriptionType;
use App\Models\LandingPage;
use App\Models\LandingPageDomain;
use App\Models\Language;
use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceCreator;
use App\Models\ResourceType;
use App\Models\Right;
use App\Models\TitleType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();

    actingAs(User::factory()->create([
        'email_verified_at' => now(),
    ]));
});

/**
 * Make a resource "complete" so it doesn't get classified as a draft.
 * Adds a creator (Person), a license (Right), and an abstract (Description).
 */
function makeResourceComplete(Resource $resource): void
{
    // Creator
    $person = Person::create([
        'family_name' => 'Testauthor',
        'given_name' => 'Test',
    ]);
    ResourceCreator::create([
        'resource_id' => $resource->id,
        'creatorable_type' => Person::class,
        'creatorable_id' => $person->id,
        'position' => 0,
    ]);

    // License
    $right = Right::firstOrCreate(
        ['identifier' => 'cc-by-4'],
        ['name' => 'Creative Commons Attribution 4.0'],
    );
    $resource->rights()->attach($right->id);

    // Abstract description
    $descriptionType = DescriptionType::firstOrCreate(
        ['slug' => 'Abstract'],
        ['name' => 'Abstract'],
    );
    Description::create([
        'resource_id' => $resource->id,
        'value' => 'Test abstract',
        'description_type_id' => $descriptionType->id,
    ]);
}

describe('Status Filter', function (): void {
    it('filters resources by status - curation (no DOI)', function (): void {
        $resourceType = ResourceType::factory()->create();
        $language = Language::factory()->create();
        $titleType = TitleType::factory()->create(['slug' => 'MainTitle']);

        // Create resource in curation (no DOI)
        $curationResource = Resource::factory()->create([
            'doi' => null,
            'resource_type_id' => $resourceType->id,
            'language_id' => $language->id,
            'publication_year' => 2024,
        ]);
        $curationResource->titles()->create([
            'value' => 'Curation Resource',
            'title_type_id' => $titleType->id,
        ]);
        makeResourceComplete($curationResource);

        // Create resource with DOI (not in curation)
        $publishedResource = Resource::factory()->create([
            'doi' => '10.5880/test.2024',
            'resource_type_id' => $resourceType->id,
            'language_id' => $language->id,
            'publication_year' => 2024,
        ]);
        $publishedResource->titles()->create([
            'value' => 'Published Resource',
            'title_type_id' => $titleType->id,
        ]);
        makeResourceComplete($publishedResource);
        LandingPage::factory()->create([
            'resource_id' => $publishedResource->id,
            'is_published' => true,
        ]);

        get(route('resources', ['status' => ['curation']]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('resources')
                ->has('resources', 1)
                ->where('resources.0.id', $curationResource->id)
                ->where('resources.0.publicstatus', 'curation')
            );
    });

    it('filters resources by status - curation (DOI without landing page)', function (): void {
        $resourceType = ResourceType::factory()->create();
        $language = Language::factory()->create();
        $titleType = TitleType::factory()->create(['slug' => 'MainTitle']);

        // Create resource with DOI but no landing page (still curation)
        $curationResource = Resource::factory()->create([
            'doi' => '10.5880/test.2024',
            'resource_type_id' => $resourceType->id,
            'language_id' => $language->id,
            'publication_year' => 2024,
        ]);
        $curationResource->titles()->create([
            'value' => 'Curation with DOI',
            'title_type_id' => $titleType->id,
        ]);
        makeResourceComplete($curationResource);

        // Create resource with DOI and landing page (not curation)
        $reviewResource = Resource::factory()->create([
            'doi' => '10.5880/test.2025',
            'resource_type_id' => $resourceType->id,
            'language_id' => $language->id,
            'publication_year' => 2024,
        ]);
        $reviewResource->titles()->create([
            'value' => 'Review Resource',
            'title_type_id' => $titleType->id,
        ]);
        makeResourceComplete($reviewResource);
        LandingPage::factory()->create([
            'resource_id' => $reviewResource->id,
            'is_published' => false,
        ]);

        get(route('resources', ['status' => ['curation']]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('resources')
                ->has('resources', 1)
                ->where('resources.0.id', $curationResource->id)
                ->where('resources.0.publicstatus', 'curation')
            );
    });

    it('filters resources by status - review', function (): void {
        $resourceType = ResourceType::factory()->create();
        $language = Language::factory()->create();
        $titleType = TitleType::factory()->create(['slug' => 'MainTitle']);

        // Create resource in review (DOI + draft landing page)
        $reviewResource = Resource::factory()->create([
            'doi' => '10.5880/test.2024',
            'resource_type_id' => $resourceType->id,
            'language_id' => $language->id,
            'publication_year' => 2024,
        ]);
        $reviewResource->titles()->create([
            'value' => 'Review Resource',
            'title_type_id' => $titleType->id,
        ]);
        makeResourceComplete($reviewResource);
        LandingPage::factory()->create([
            'resource_id' => $reviewResource->id,
            'is_published' => false,
        ]);

        // Create resource in curation (no DOI)
        $curationResource = Resource::factory()->create([
            'doi' => null,
            'resource_type_id' => $resourceType->id,
            'language_id' => $language->id,
            'publication_year' => 2024,
        ]);
        $curationResource->titles()->create([
            'value' => 'Curation Resource',
            'title_type_id' => $titleType->id,
        ]);
        makeResourceComplete($curationResource);

        get(route('resources', ['status' => ['review']]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('resources')
                ->has('resources', 1)
                ->where('resources.0.id', $reviewResource->id)
                ->where('resources.0.publicstatus', 'review')
            );
    });

    it('filters resources by status - published', function (): void {
        $resourceType = ResourceType::factory()->create();
        $language = Language::factory()->create();
        $titleType = TitleType::factory()->create(['slug' => 'MainTitle']);

        // Create published resource (DOI + published landing page)
        $publishedResource = Resource::factory()->create([
            'doi' => '10.5880/test.2024',
            'resource_type_id' => $resourceType->id,
            'language_id' => $language->id,
            'publication_year' => 2024,
        ]);
        $publishedResource->titles()->create([
            'value' => 'Published Resource',
            'title_type_id' => $titleType->id,
        ]);
        makeResourceComplete($publishedResource);
        LandingPage::factory()->create([
            'resource_id' => $publishedResource->id,
            'is_published' => true,
        ]);

        // Create resource in review (DOI + draft landing page)
        $reviewResource = Resource::factory()->create([
            'doi' => '10.5880/test.2025',
            'resource_type_id' => $resourceType->id,
            'language_id' => $language->id,
            'publication_year' => 2024,
        ]);
        $reviewResource->titles()->create([
            'value' => 'Review Resource',
            'title_type_id' => $titleType->id,
        ]);
        makeResourceComplete($reviewResource);
        LandingPage::factory()->create([
            'resource_id' => $reviewResource->id,
            'is_published' => false,
        ]);

        get(route('resources', ['status' => ['published']]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('resources')
                ->has('resources', 1)
                ->where('resources.0.id', $publishedResource->id)
                ->where('resources.0.publicstatus', 'published')
            );
    });

    it('filters resources by multiple status values', function (): void {
        $resourceType = ResourceType::factory()->create();
        $language = Language::factory()->create();
        $titleType = TitleType::factory()->create(['slug' => 'MainTitle']);

        // Curation resource
        $curationResource = Resource::factory()->create([
            'doi' => null,
            'resource_type_id' => $resourceType->id,
            'language_id' => $language->id,
            'publication_year' => 2024,
        ]);
        $curationResource->titles()->create([
            'value' => 'Curation Resource',
            'title_type_id' => $titleType->id,
        ]);
        makeResourceComplete($curationResource);

        // Published resource
        $publishedResource = Resource::factory()->create([
            'doi' => '10.5880/test.2024',
            'resource_type_id' => $resourceType->id,
            'language_id' => $language->id,
            'publication_year' => 2024,
        ]);
        $publishedResource->titles()->create([
            'value' => 'Published Resource',
            'title_type_id' => $titleType->id,
        ]);
        makeResourceComplete($publishedResource);
        LandingPage::factory()->create([
            'resource_id' => $publishedResource->id,
            'is_published' => true,
        ]);

        // Review resource (should not appear)
        $reviewResource = Resource::factory()->create([
            'doi' => '10.5880/test.2025',
            'resource_type_id' => $resourceType->id,
            'language_id' => $language->id,
            'publication_year' => 2024,
        ]);
        $reviewResource->titles()->create([
            'value' => 'Review Resource',
            'title_type_id' => $titleType->id,
        ]);
        makeResourceComplete($reviewResource);
        LandingPage::factory()->create([
            'resource_id' => $reviewResource->id,
            'is_published' => false,
        ]);

        get(route('resources', ['status' => ['curation', 'published']]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('resources')
                ->has('resources', 2)
            );
    });
});

describe('Curator Filter', function (): void {
    it('filters resources by curator - using updatedBy', function (): void {
        $resourceType = ResourceType::factory()->create();
        $language = Language::factory()->create();
        $titleType = TitleType::factory()->create(['slug' => 'MainTitle']);

        $creator = User::factory()->create(['name' => 'Creator User']);
        $editor = User::factory()->create(['name' => 'Editor User']);

        // Resource created by Creator but updated by Editor
        $resource = Resource::factory()->create([
            'resource_type_id' => $resourceType->id,
            'language_id' => $language->id,
            'publication_year' => 2024,
            'created_by_user_id' => $creator->id,
            'updated_by_user_id' => $editor->id, // Last editor
        ]);
        $resource->titles()->create([
            'value' => 'Edited Resource',
            'title_type_id' => $titleType->id,
        ]);

        // Filter by editor (should find the resource)
        get(route('resources', ['curator' => ['Editor User']]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('resources')
                ->has('resources', 1)
                ->where('resources.0.id', $resource->id)
                ->where('resources.0.curator', 'Editor User')
            );

        // Filter by creator (should NOT find the resource)
        get(route('resources', ['curator' => ['Creator User']]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('resources')
                ->has('resources', 0)
            );
    });

    it('filters resources by curator - fallback to createdBy when never updated', function (): void {
        $resourceType = ResourceType::factory()->create();
        $language = Language::factory()->create();
        $titleType = TitleType::factory()->create(['slug' => 'MainTitle']);

        $creator = User::factory()->create(['name' => 'Creator User']);

        // Resource created by Creator, never updated (updated_by_user_id is null)
        $resource = Resource::factory()->create([
            'resource_type_id' => $resourceType->id,
            'language_id' => $language->id,
            'publication_year' => 2024,
            'created_by_user_id' => $creator->id,
            'updated_by_user_id' => null, // Never edited
        ]);
        $resource->titles()->create([
            'value' => 'Created Resource',
            'title_type_id' => $titleType->id,
        ]);

        // Filter by creator (should find the resource via fallback)
        get(route('resources', ['curator' => ['Creator User']]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('resources')
                ->has('resources', 1)
                ->where('resources.0.id', $resource->id)
                ->where('resources.0.curator', 'Creator User')
            );
    });

    it('provides correct curator list in filter options - includes both updatedBy and createdBy users', function (): void {
        $resourceType = ResourceType::factory()->create();
        $language = Language::factory()->create();

        $creator = User::factory()->create(['name' => 'Alice Creator']);
        $editor = User::factory()->create(['name' => 'Bob Editor']);
        $anotherCreator = User::factory()->create(['name' => 'Charlie Creator']);

        // Resource updated by Bob (should show Bob as curator)
        Resource::factory()->create([
            'resource_type_id' => $resourceType->id,
            'language_id' => $language->id,
            'publication_year' => 2024,
            'created_by_user_id' => $creator->id,
            'updated_by_user_id' => $editor->id,
        ]);

        // Resource never updated by Charlie (should show Charlie as curator via fallback)
        Resource::factory()->create([
            'resource_type_id' => $resourceType->id,
            'language_id' => $language->id,
            'publication_year' => 2024,
            'created_by_user_id' => $anotherCreator->id,
            'updated_by_user_id' => null,
        ]);

        get(route('resources.filter-options'))
            ->assertOk()
            ->assertJson([
                'resource_types' => [],
                'statuses' => ['draft', 'curation', 'review', 'published'],
                'curators' => [
                    'Bob Editor',    // From updatedBy
                    'Charlie Creator', // From createdBy (fallback)
                ],
            ]);
    });

    it('returns the actual publication_year range in filter options (Issue: PR #679 review)', function (): void {
        $resourceType = ResourceType::factory()->create();
        $language = Language::factory()->create();

        Resource::factory()->create([
            'resource_type_id' => $resourceType->id,
            'language_id' => $language->id,
            'publication_year' => 2018,
        ]);

        Resource::factory()->create([
            'resource_type_id' => $resourceType->id,
            'language_id' => $language->id,
            'publication_year' => 2024,
        ]);

        get(route('resources.filter-options'))
            ->assertOk()
            ->assertJson([
                'year_range' => [
                    'min' => 2018,
                    'max' => 2024,
                ],
            ]);
    });

    it('sorts by curator using updatedBy with createdBy fallback (Issue: PR #679 review)', function (): void {
        $resourceType = ResourceType::factory()->create();
        $language = Language::factory()->create();

        $alice = User::factory()->create(['name' => 'Alice']);
        $charlie = User::factory()->create(['name' => 'Charlie']);
        $bob = User::factory()->create(['name' => 'Bob']);

        // Resource A: created by Charlie, updated by Alice -> effective curator "Alice"
        $resourceA = Resource::factory()->create([
            'resource_type_id' => $resourceType->id,
            'language_id' => $language->id,
            'publication_year' => 2024,
            'created_by_user_id' => $charlie->id,
            'updated_by_user_id' => $alice->id,
        ]);
        makeResourceComplete($resourceA);

        // Resource B: created by Bob, never updated -> effective curator "Bob"
        $resourceB = Resource::factory()->create([
            'resource_type_id' => $resourceType->id,
            'language_id' => $language->id,
            'publication_year' => 2024,
            'created_by_user_id' => $bob->id,
            'updated_by_user_id' => null,
        ]);
        makeResourceComplete($resourceB);

        // Resource C: created by Alice, updated by Charlie -> effective curator "Charlie"
        $resourceC = Resource::factory()->create([
            'resource_type_id' => $resourceType->id,
            'language_id' => $language->id,
            'publication_year' => 2024,
            'created_by_user_id' => $alice->id,
            'updated_by_user_id' => $charlie->id,
        ]);
        makeResourceComplete($resourceC);

        // Ascending: Alice (A), Bob (B), Charlie (C) — based on effective curator,
        // NOT on created_by_user_id alone (which would yield Alice (C), Bob (B), Charlie (A)).
        get(route('resources', ['sort_key' => 'curator', 'sort_direction' => 'asc']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('resources')
                ->where('resources.0.id', $resourceA->id)
                ->where('resources.0.curator', 'Alice')
                ->where('resources.1.id', $resourceB->id)
                ->where('resources.1.curator', 'Bob')
                ->where('resources.2.id', $resourceC->id)
                ->where('resources.2.curator', 'Charlie')
            );
    });

    it('excludes Physical Object curators from the /resources curator filter list (Issue: PR #679 review)', function (): void {
        $datasetType = ResourceType::factory()->create(['slug' => 'dataset', 'name' => 'Dataset']);
        $physicalObjectType = ResourceType::factory()->create(['slug' => 'physical-object', 'name' => 'Physical Object']);
        $language = Language::factory()->create();

        $datasetCurator = User::factory()->create(['name' => 'Dataset Curator']);
        $igsnOnlyCurator = User::factory()->create(['name' => 'IGSN Only Curator']);

        // A regular dataset curated by 'Dataset Curator' — must appear.
        Resource::factory()->create([
            'resource_type_id' => $datasetType->id,
            'language_id' => $language->id,
            'publication_year' => 2024,
            'created_by_user_id' => $datasetCurator->id,
            'updated_by_user_id' => null,
        ]);

        // A Physical Object (IGSN) curated only by 'IGSN Only Curator' — must NOT
        // leak into /resources curator filter options (IGSNs live on /igsns).
        Resource::factory()->create([
            'resource_type_id' => $physicalObjectType->id,
            'language_id' => $language->id,
            'publication_year' => 2024,
            'created_by_user_id' => $igsnOnlyCurator->id,
            'updated_by_user_id' => null,
        ]);

        $response = get(route('resources.filter-options'))->assertOk();
        $curators = $response->json('curators');

        expect($curators)->toContain('Dataset Curator')
            ->and($curators)->not->toContain('IGSN Only Curator');
    });

    it('excludes Physical Object publication years from the /resources year_range (Issue: PR #679 review)', function (): void {
        $datasetType = ResourceType::factory()->create(['slug' => 'dataset', 'name' => 'Dataset']);
        $physicalObjectType = ResourceType::factory()->create(['slug' => 'physical-object', 'name' => 'Physical Object']);
        $language = Language::factory()->create();

        // Datasets span 2018–2024; the year_range must reflect this.
        Resource::factory()->create([
            'resource_type_id' => $datasetType->id,
            'language_id' => $language->id,
            'publication_year' => 2018,
        ]);
        Resource::factory()->create([
            'resource_type_id' => $datasetType->id,
            'language_id' => $language->id,
            'publication_year' => 2024,
        ]);

        // Physical Object outliers (1985, 2099) must NOT skew /resources year_range.
        Resource::factory()->create([
            'resource_type_id' => $physicalObjectType->id,
            'language_id' => $language->id,
            'publication_year' => 1985,
        ]);
        Resource::factory()->create([
            'resource_type_id' => $physicalObjectType->id,
            'language_id' => $language->id,
            'publication_year' => 2099,
        ]);

        get(route('resources.filter-options'))
            ->assertOk()
            ->assertJson([
                'year_range' => [
                    'min' => 2018,
                    'max' => 2024,
                ],
            ]);
    });

    it('exposes the MainTitle on /resources even when subtitles are eager-loaded first (Issue: PR #679 review)', function (): void {
        // Titles are eager-loaded ordered by id and may include subtitles /
        // alternate titles. Picking `titles->first()` blindly would surface a
        // subtitle as the resource's display title in list views. The list
        // resource must select the title flagged as MainTitle.
        $resourceType = ResourceType::factory()->create(['slug' => 'dataset', 'name' => 'Dataset']);
        $language = Language::factory()->create();
        $subtitleType = TitleType::factory()->create(['slug' => 'Subtitle', 'name' => 'Subtitle']);
        $mainTitleType = TitleType::factory()->create(['slug' => 'MainTitle', 'name' => 'Main Title']);

        $resource = Resource::factory()->create([
            'resource_type_id' => $resourceType->id,
            'language_id' => $language->id,
            'publication_year' => 2024,
        ]);

        // Insert the subtitle FIRST so it has the lower id and would win a
        // naive `titles->first()` selection.
        $resource->titles()->create([
            'value' => 'A subtitle that must NOT be the display title',
            'title_type_id' => $subtitleType->id,
        ]);
        $resource->titles()->create([
            'value' => 'The real main title',
            'title_type_id' => $mainTitleType->id,
        ]);
        makeResourceComplete($resource);

        get(route('resources'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('resources')
                ->where('resources.0.id', $resource->id)
                ->where('resources.0.title', 'The real main title')
            );
    });

    it('exposes a correct landingPage.public_url on /resources for internal and external pages (Issue: PR #679 review)', function (): void {
        // ResourceQueryBuilder must eager-load the LandingPage columns and the
        // externalDomain relation that LandingPage::public_url derives from.
        // Otherwise list endpoints return empty / wrong public_url and trigger
        // an N+1 query on externalDomain for external pages.
        $resourceType = ResourceType::factory()->create(['slug' => 'dataset', 'name' => 'Dataset']);
        $language = Language::factory()->create();
        $mainTitleType = TitleType::factory()->create(['slug' => 'MainTitle', 'name' => 'Main Title']);

        // --- Internal landing page (default_gfz template, with DOI) ---
        $internalResource = Resource::factory()->create([
            'resource_type_id' => $resourceType->id,
            'language_id' => $language->id,
            'publication_year' => 2024,
        ]);
        $internalResource->titles()->create([
            'value' => 'Internal Resource',
            'title_type_id' => $mainTitleType->id,
        ]);
        makeResourceComplete($internalResource);
        LandingPage::factory()->create([
            'resource_id' => $internalResource->id,
            'doi_prefix' => '10.5880/test.internal',
            'slug' => 'internal-slug',
            'template' => 'default_gfz',
            'is_published' => true,
            'published_at' => now(),
        ]);

        // --- External landing page ---
        $externalDomain = LandingPageDomain::factory()->withDomain('https://example.org/')->create();
        $externalResource = Resource::factory()->create([
            'resource_type_id' => $resourceType->id,
            'language_id' => $language->id,
            'publication_year' => 2024,
        ]);
        $externalResource->titles()->create([
            'value' => 'External Resource',
            'title_type_id' => $mainTitleType->id,
        ]);
        makeResourceComplete($externalResource);
        LandingPage::factory()->create([
            'resource_id' => $externalResource->id,
            'doi_prefix' => '10.5880/test.external',
            'slug' => 'external-slug',
            'template' => 'external',
            'external_domain_id' => $externalDomain->id,
            'external_path' => 'datasets/foo',
            'is_published' => true,
            'published_at' => now(),
        ]);

        $response = get(route('resources'))->assertOk();
        $response->assertInertia(function ($page) use ($internalResource, $externalResource) {
            $resources = collect($page->toArray()['props']['resources'] ?? [])
                ->keyBy('id');

            expect($resources[$internalResource->id]['landingPage']['public_url'])
                ->toContain('/10.5880/test.internal/internal-slug');

            expect($resources[$externalResource->id]['landingPage']['public_url'])
                ->toBe('https://example.org/datasets/foo');

            return $page;
        });
    });
});
