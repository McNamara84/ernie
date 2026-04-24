<?php

declare(strict_types=1);

use App\Models\RelatedItem;
use App\Models\RelationType;
use App\Models\Resource;
use App\Models\ResourceType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function seedRelatedItemPrereqs(): array
{
    $resourceType = ResourceType::firstOrCreate(
        ['slug' => 'JournalArticle'],
        ['name' => 'Journal Article', 'is_active' => true, 'is_elmo_active' => true]
    );
    $relationType = RelationType::firstOrCreate(
        ['slug' => 'Cites'],
        ['name' => 'Cites', 'is_active' => true]
    );

    return [$resourceType, $relationType];
}

function validStorePayload(int $relationTypeId): array
{
    return [
        'related_item_type' => 'JournalArticle',
        'relation_type_id' => $relationTypeId,
        'titles' => [
            ['title' => 'The Main Title', 'title_type' => 'MainTitle'],
            ['title' => 'A Subtitle', 'title_type' => 'Subtitle'],
        ],
        'publication_year' => 2023,
        'volume' => '12',
        'issue' => '3',
        'first_page' => '101',
        'last_page' => '115',
        'publisher' => 'Journal of Science',
        'identifier' => '10.1234/example',
        'identifier_type' => 'DOI',
        'creators' => [
            [
                'name_type' => 'Personal',
                'name' => 'Doe, Jane',
                'given_name' => 'Jane',
                'family_name' => 'Doe',
                'name_identifier' => '0000-0001-0002-0003',
                'name_identifier_scheme' => 'ORCID',
                'affiliations' => [
                    ['name' => 'GFZ Helmholtz Centre', 'affiliation_identifier' => 'https://ror.org/04z8jg394', 'scheme' => 'ROR'],
                ],
            ],
        ],
        'contributors' => [
            [
                'contributor_type' => 'Editor',
                'name_type' => 'Personal',
                'name' => 'Smith, John',
            ],
        ],
    ];
}

describe('RelatedItemController', function () {
    test('unauthenticated requests are redirected / rejected', function () {
        $resource = Resource::factory()->create();

        $this->getJson("/resources/{$resource->id}/related-items")
            ->assertStatus(401);
    });

    test('authenticated user can list related items', function () {
        [$resourceType, $relationType] = seedRelatedItemPrereqs();
        $user = User::factory()->create();
        $resource = Resource::factory()->create();
        $item = RelatedItem::factory()->create([
            'resource_id' => $resource->id,
            'relation_type_id' => $relationType->id,
        ]);
        \App\Models\RelatedItemTitle::factory()->create([
            'related_item_id' => $item->id,
            'title' => 'Hello World',
            'title_type' => 'MainTitle',
        ]);

        $this->actingAs($user)
            ->getJson("/resources/{$resource->id}/related-items")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.titles.0.title', 'Hello World');
    });

    test('store creates a related item with children', function () {
        [$resourceType, $relationType] = seedRelatedItemPrereqs();
        $user = User::factory()->create();
        $resource = Resource::factory()->create();

        $this->actingAs($user)
            ->postJson("/resources/{$resource->id}/related-items", validStorePayload($relationType->id))
            ->assertCreated()
            ->assertJsonPath('data.titles.0.title', 'The Main Title')
            ->assertJsonPath('data.creators.0.name', 'Doe, Jane')
            ->assertJsonPath('data.creators.0.affiliations.0.name', 'GFZ Helmholtz Centre')
            ->assertJsonPath('data.contributors.0.contributorType', 'Editor')
            ->assertJsonPath('data.identifier', '10.1234/example');

        expect(RelatedItem::count())->toBe(1);
    });

    test('store rejects payloads without a MainTitle', function () {
        [$resourceType, $relationType] = seedRelatedItemPrereqs();
        $user = User::factory()->create();
        $resource = Resource::factory()->create();

        $payload = validStorePayload($relationType->id);
        $payload['titles'] = [['title' => 'Only alt', 'title_type' => 'AlternativeTitle']];

        $this->actingAs($user)
            ->postJson("/resources/{$resource->id}/related-items", $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['titles']);
    });

    test('store rejects invalid relation_type_id', function () {
        seedRelatedItemPrereqs();
        $user = User::factory()->create();
        $resource = Resource::factory()->create();

        $payload = validStorePayload(999_999);

        $this->actingAs($user)
            ->postJson("/resources/{$resource->id}/related-items", $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['relation_type_id']);
    });

    test('update replaces nested children', function () {
        [$resourceType, $relationType] = seedRelatedItemPrereqs();
        $user = User::factory()->create();
        $resource = Resource::factory()->create();

        $createResp = $this->actingAs($user)
            ->postJson("/resources/{$resource->id}/related-items", validStorePayload($relationType->id))
            ->assertCreated();

        $id = $createResp->json('data.id');

        $updated = validStorePayload($relationType->id);
        $updated['titles'] = [['title' => 'Different Main Title', 'title_type' => 'MainTitle']];
        $updated['creators'] = [];
        $updated['contributors'] = [];
        $updated['publisher'] = 'New Publisher';

        $this->actingAs($user)
            ->putJson("/resources/{$resource->id}/related-items/{$id}", $updated)
            ->assertOk()
            ->assertJsonPath('data.titles.0.title', 'Different Main Title')
            ->assertJsonPath('data.publisher', 'New Publisher')
            ->assertJsonCount(0, 'data.creators')
            ->assertJsonCount(0, 'data.contributors');
    });

    test('update returns 404 when item does not belong to resource', function () {
        [$resourceType, $relationType] = seedRelatedItemPrereqs();
        $user = User::factory()->create();
        $resourceA = Resource::factory()->create();
        $resourceB = Resource::factory()->create();
        $item = RelatedItem::factory()->create(['resource_id' => $resourceA->id, 'relation_type_id' => $relationType->id]);
        \App\Models\RelatedItemTitle::factory()->create(['related_item_id' => $item->id, 'title_type' => 'MainTitle']);

        $this->actingAs($user)
            ->putJson("/resources/{$resourceB->id}/related-items/{$item->id}", validStorePayload($relationType->id))
            ->assertNotFound();
    });

    test('destroy removes the item', function () {
        [$resourceType, $relationType] = seedRelatedItemPrereqs();
        $user = User::factory()->create();
        $resource = Resource::factory()->create();
        $item = RelatedItem::factory()->create([
            'resource_id' => $resource->id,
            'relation_type_id' => $relationType->id,
        ]);

        $this->actingAs($user)
            ->deleteJson("/resources/{$resource->id}/related-items/{$item->id}")
            ->assertNoContent();

        expect(RelatedItem::whereKey($item->id)->exists())->toBeFalse();
    });

    test('reorder updates positions', function () {
        [$resourceType, $relationType] = seedRelatedItemPrereqs();
        $user = User::factory()->create();
        $resource = Resource::factory()->create();
        $a = RelatedItem::factory()->create(['resource_id' => $resource->id, 'relation_type_id' => $relationType->id, 'position' => 0]);
        $b = RelatedItem::factory()->create(['resource_id' => $resource->id, 'relation_type_id' => $relationType->id, 'position' => 1]);

        $this->actingAs($user)
            ->postJson("/resources/{$resource->id}/related-items/reorder", [
                'order' => [
                    ['id' => $a->id, 'position' => 1],
                    ['id' => $b->id, 'position' => 0],
                ],
            ])
            ->assertNoContent();

        expect($a->fresh()->position)->toBe(1);
        expect($b->fresh()->position)->toBe(0);
    });

    test('reorder requires order array', function () {
        seedRelatedItemPrereqs();
        $user = User::factory()->create();
        $resource = Resource::factory()->create();

        $this->actingAs($user)
            ->postJson("/resources/{$resource->id}/related-items/reorder", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['order']);
    });
});
