<?php

use App\Models\LandingPage;
use App\Models\Language;
use App\Models\Resource;
use App\Models\ResourceType;
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

describe('Status Filter', function (): void {
    it('filters resources by status - curation (no DOI)', function (): void {
        $resourceType = ResourceType::factory()->create();
        $language = Language::factory()->create();
        $titleType = TitleType::factory()->create(['slug' => 'main-title']);

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
        $titleType = TitleType::factory()->create(['slug' => 'main-title']);

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
        $titleType = TitleType::factory()->create(['slug' => 'main-title']);

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
        $titleType = TitleType::factory()->create(['slug' => 'main-title']);

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
        $titleType = TitleType::factory()->create(['slug' => 'main-title']);

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
        $titleType = TitleType::factory()->create(['slug' => 'main-title']);

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
        $titleType = TitleType::factory()->create(['slug' => 'main-title']);

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
                'statuses' => ['curation', 'review', 'published'],
                'curators' => [
                    'Bob Editor',    // From updatedBy
                    'Charlie Creator', // From createdBy (fallback)
                ],
            ]);
    });
});
