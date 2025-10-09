<?php

use App\Models\Language;
use App\Models\License;
use App\Models\Resource;
use App\Models\ResourceKeyword;
use App\Models\ResourceType;
use App\Models\TitleType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    
    actingAs(User::factory()->create([
        'email_verified_at' => now(),
    ]));
    
    // Create required reference data
    $this->resourceType = ResourceType::query()->create([
        'name' => 'Dataset',
        'slug' => 'dataset',
    ]);
    
    $this->language = Language::query()->create([
        'code' => 'en',
        'name' => 'English',
        'active' => true,
        'elmo_active' => true,
    ]);
    
    $this->titleType = TitleType::query()->create([
        'name' => 'Main Title',
        'slug' => 'main-title',
    ]);
    
    $this->license = License::query()->create([
        'identifier' => 'cc-by-4.0',
        'name' => 'Creative Commons Attribution 4.0 International',
        'url' => 'https://creativecommons.org/licenses/by/4.0/',
    ]);
});

it('saves free keywords when creating a resource', function () {
    $payload = [
        'doi' => '10.5880/GFZ.TEST.2025.001',
        'year' => 2025,
        'resourceType' => $this->resourceType->id,
        'version' => '1.0',
        'language' => $this->language->id,
        'titles' => [
            [
                'title' => 'Test Resource Title',
                'titleType' => $this->titleType->id,
            ],
        ],
        'licenses' => [$this->license->id],
        'authors' => [
            [
                'type' => 'person',
                'firstName' => 'John',
                'lastName' => 'Doe',
                'position' => 0,
                'roles' => [],
                'affiliations' => [],
            ],
        ],
        'descriptions' => [
            [
                'descriptionType' => 'abstract',
                'description' => 'Test abstract description',
            ],
        ],
        'freeKeywords' => ['climate change', 'temperature', 'precipitation'],
    ];

    $response = $this->postJson('/curation/resources', $payload);

        $response->assertStatus(201);
        
        $resourceId = $response->json('resource.id');
        
        assertDatabaseHas('resource_keywords', [
            'resource_id' => $resourceId,
            'keyword' => 'climate change',
        ]);
        
        assertDatabaseHas('resource_keywords', [
            'resource_id' => $resourceId,
            'keyword' => 'temperature',
        ]);
        
        assertDatabaseHas('resource_keywords', [
            'resource_id' => $resourceId,
            'keyword' => 'precipitation',
        ]);
    });

    it('trims whitespace from keywords when saving', function () {
        $payload = getValidResourcePayload([
            'freeKeywords' => [' keyword1 ', '  keyword2  ', 'keyword3'],
        ]);

        $response = $this->postJson('/curation/resources', $payload);

        $response->assertStatus(201);
        
        $resourceId = $response->json('resource.id');
        
        assertDatabaseHas('resource_keywords', [
            'resource_id' => $resourceId,
            'keyword' => 'keyword1',
        ]);
        
        assertDatabaseHas('resource_keywords', [
            'resource_id' => $resourceId,
            'keyword' => 'keyword2',
        ]);
    });

    it('filters out empty keywords when saving', function () {
        $payload = getValidResourcePayload([
            'freeKeywords' => ['keyword1', '', '   ', 'keyword2'],
        ]);

        $response = $this->postJson('/curation/resources', $payload);

        $response->assertStatus(201);
        
        $resourceId = $response->json('resource.id');
        
        // Should only have 2 keywords
        expect(ResourceKeyword::where('resource_id', $resourceId)->count())->toBe(2);
        
        assertDatabaseHas('resource_keywords', [
            'resource_id' => $resourceId,
            'keyword' => 'keyword1',
        ]);
        
        assertDatabaseHas('resource_keywords', [
            'resource_id' => $resourceId,
            'keyword' => 'keyword2',
        ]);
    });

    it('returns free keywords when loading a resource', function () {
        $payload = getValidResourcePayload([
            'freeKeywords' => ['InSAR', 'GNSS', 'CO2 storage'],
        ]);

        $createResponse = $this->postJson('/curation/resources', $payload);
        $createResponse->assertStatus(201);

        $response = $this->getJson('/resources');

        $response->assertOk();
        
        $resource = $response->json('resources.0');
        
        expect($resource['freeKeywords'])->toBeArray();
        expect($resource['freeKeywords'])->toHaveCount(3);
        expect($resource['freeKeywords'])->toContain('InSAR');
        expect($resource['freeKeywords'])->toContain('GNSS');
        expect($resource['freeKeywords'])->toContain('CO2 storage');
    });

    it('updates free keywords when updating a resource', function () {
        // Create resource with initial keywords
        $payload = getValidResourcePayload([
            'freeKeywords' => ['old-keyword-1', 'old-keyword-2'],
        ]);

        $createResponse = $this->postJson('/curation/resources', $payload);
        $resourceId = $createResponse->json('resource.id');

        // Update with new keywords
        $updatePayload = getValidResourcePayload([
            'freeKeywords' => ['new-keyword-1', 'new-keyword-2', 'new-keyword-3'],
        ]);

        $response = $this->putJson("/curation/resources/{$resourceId}", $updatePayload);

        $response->assertOk();

        // Old keywords should be removed
        assertDatabaseMissing('resource_keywords', [
            'resource_id' => $resourceId,
            'keyword' => 'old-keyword-1',
        ]);
        
        assertDatabaseMissing('resource_keywords', [
            'resource_id' => $resourceId,
            'keyword' => 'old-keyword-2',
        ]);

        // New keywords should be present
        assertDatabaseHas('resource_keywords', [
            'resource_id' => $resourceId,
            'keyword' => 'new-keyword-1',
        ]);
        
        assertDatabaseHas('resource_keywords', [
            'resource_id' => $resourceId,
            'keyword' => 'new-keyword-2',
        ]);
        
        assertDatabaseHas('resource_keywords', [
            'resource_id' => $resourceId,
            'keyword' => 'new-keyword-3',
        ]);
        
        // Should have exactly 3 keywords
        expect(ResourceKeyword::where('resource_id', $resourceId)->count())->toBe(3);
    });

    it('deletes keywords when resource is deleted', function () {
        $payload = getValidResourcePayload([
            'freeKeywords' => ['keyword1', 'keyword2'],
        ]);

        $createResponse = $this->postJson('/curation/resources', $payload);
        $resourceId = $createResponse->json('resource.id');

        // Verify keywords exist
        expect(ResourceKeyword::where('resource_id', $resourceId)->count())->toBe(2);

        // Delete resource
        $this->deleteJson("/resources/{$resourceId}");

        // Keywords should be deleted (cascade)
        expect(ResourceKeyword::where('resource_id', $resourceId)->count())->toBe(0);
    });

    it('allows saving resource without keywords', function () {
        $payload = getValidResourcePayload([
            'freeKeywords' => [],
        ]);

        $response = $this->postJson('/curation/resources', $payload);

        $response->assertStatus(201);
        
        $resourceId = $response->json('resource.id');
        
        expect(ResourceKeyword::where('resource_id', $resourceId)->count())->toBe(0);
    });

    it('preserves mixed case in keywords', function () {
        $payload = getValidResourcePayload([
            'freeKeywords' => ['InSAR', 'GNSS', 'pH Level'],
        ]);

        $response = $this->postJson('/curation/resources', $payload);

        $response->assertStatus(201);
        
        $resourceId = $response->json('resource.id');
        
        assertDatabaseHas('resource_keywords', [
            'resource_id' => $resourceId,
            'keyword' => 'InSAR',
        ]);
        
        assertDatabaseHas('resource_keywords', [
            'resource_id' => $resourceId,
            'keyword' => 'GNSS',
        ]);
        
        assertDatabaseHas('resource_keywords', [
            'resource_id' => $resourceId,
            'keyword' => 'pH Level',
        ]);
});

function getValidResourcePayload(array $overrides = []): array
{
    return array_merge([
        'doi' => '10.5880/GFZ.TEST.2025.001',
        'year' => 2025,
        'resourceType' => 'dataset',
        'version' => '1.0',
        'language' => 'en',
        'titles' => [
            [
                'title' => 'Test Resource Title',
                'titleType' => 'main-title',
            ],
        ],
        'licenses' => ['cc-by-4.0'],
        'authors' => [
            [
                'type' => 'person',
                'firstName' => 'John',
                'lastName' => 'Doe',
                'position' => 0,
                'roles' => ['author'],
                'affiliations' => [],
            ],
        ],
        'descriptions' => [
            [
                'descriptionType' => 'abstract',
                'description' => 'Test abstract description',
            ],
        ],
    ], $overrides);
}
