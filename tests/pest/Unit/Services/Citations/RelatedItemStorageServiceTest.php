<?php

declare(strict_types=1);

use App\Models\RelatedItem;
use App\Models\RelatedItemContributor;
use App\Models\RelatedItemCreator;
use App\Models\RelatedItemTitle;
use App\Models\RelationType;
use App\Models\Resource;
use App\Services\Citations\RelatedItemStorageService;

covers(RelatedItemStorageService::class);

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function citationPayload(int $relationTypeId, array $overrides = []): array
{
    return array_merge([
        'related_item_type' => 'JournalArticle',
        'relation_type_id' => $relationTypeId,
        'publication_year' => 2024,
        'volume' => '1',
        'issue' => '2',
        'first_page' => '1',
        'last_page' => '10',
        'publisher' => 'ACME',
        'identifier' => '10.5/x',
        'identifier_type' => 'DOI',
        'titles' => [
            ['title' => 'Main', 'title_type' => 'MainTitle'],
            ['title' => 'Sub', 'title_type' => 'Subtitle'],
        ],
        'creators' => [
            [
                'name' => 'Doe, Jane',
                'name_type' => 'Personal',
                'given_name' => 'Jane',
                'family_name' => 'Doe',
                'affiliations' => [['name' => 'GFZ']],
            ],
        ],
        'contributors' => [
            [
                'contributor_type' => 'Editor',
                'name' => 'Smith, Bob',
                'name_type' => 'Personal',
                'affiliations' => [],
            ],
        ],
    ], $overrides);
}

it('creates a related item with all nested children in one transaction', function () {
    $resource = Resource::factory()->create();
    $relType = RelationType::firstOrCreate(
        ['slug' => 'Cites'],
        ['name' => 'Cites', 'is_active' => true]
    );
    $svc = new RelatedItemStorageService();

    $item = $svc->create($resource, citationPayload($relType->id));

    expect($item)->toBeInstanceOf(RelatedItem::class);
    expect($item->position)->toBe(0);
    expect($item->titles)->toHaveCount(2);
    expect($item->creators)->toHaveCount(1);
    expect($item->creators->first()->affiliations)->toHaveCount(1);
    expect($item->contributors)->toHaveCount(1);
});

it('auto-increments position for additional items', function () {
    $resource = Resource::factory()->create();
    $relType = RelationType::firstOrCreate(['slug' => 'Cites'], ['name' => 'Cites', 'is_active' => true]);
    $svc = new RelatedItemStorageService();

    $a = $svc->create($resource, citationPayload($relType->id));
    $b = $svc->create($resource, citationPayload($relType->id));
    $c = $svc->create($resource, citationPayload($relType->id));

    expect([$a->position, $b->position, $c->position])->toBe([0, 1, 2]);
});

it('replaces all children on update', function () {
    $resource = Resource::factory()->create();
    $relType = RelationType::firstOrCreate(['slug' => 'Cites'], ['name' => 'Cites', 'is_active' => true]);
    $svc = new RelatedItemStorageService();

    $item = $svc->create($resource, citationPayload($relType->id));

    $updated = $svc->update($item, citationPayload($relType->id, [
        'publication_year' => 2020,
        'titles' => [['title' => 'New', 'title_type' => 'MainTitle']],
        'creators' => [],
        'contributors' => [],
    ]));

    expect($updated->publication_year)->toBe(2020);
    expect($updated->titles)->toHaveCount(1);
    expect($updated->titles->first()->title)->toBe('New');
    expect($updated->creators)->toHaveCount(0);
    expect($updated->contributors)->toHaveCount(0);
    // Ensure no orphans
    expect(RelatedItemTitle::where('related_item_id', $item->id)->count())->toBe(1);
    expect(RelatedItemCreator::where('related_item_id', $item->id)->count())->toBe(0);
    expect(RelatedItemContributor::where('related_item_id', $item->id)->count())->toBe(0);
});

it('reorders items by id→position mapping', function () {
    $resource = Resource::factory()->create();
    $relType = RelationType::firstOrCreate(['slug' => 'Cites'], ['name' => 'Cites', 'is_active' => true]);
    $svc = new RelatedItemStorageService();

    $a = $svc->create($resource, citationPayload($relType->id));
    $b = $svc->create($resource, citationPayload($relType->id));

    $svc->reorder($resource, [
        ['id' => $a->id, 'position' => 5],
        ['id' => $b->id, 'position' => 2],
    ]);

    expect($a->fresh()->position)->toBe(5);
    expect($b->fresh()->position)->toBe(2);
});

it('deletes an item and all its children', function () {
    $resource = Resource::factory()->create();
    $relType = RelationType::firstOrCreate(['slug' => 'Cites'], ['name' => 'Cites', 'is_active' => true]);
    $svc = new RelatedItemStorageService();

    $item = $svc->create($resource, citationPayload($relType->id));
    $id = $item->id;

    $svc->delete($item);

    expect(RelatedItem::find($id))->toBeNull();
    expect(RelatedItemTitle::where('related_item_id', $id)->count())->toBe(0);
    expect(RelatedItemCreator::where('related_item_id', $id)->count())->toBe(0);
    expect(RelatedItemContributor::where('related_item_id', $id)->count())->toBe(0);
});
