<?php

declare(strict_types=1);

use App\Models\RelatedItem;
use App\Models\RelatedItemContributor;
use App\Models\RelatedItemCreator;
use App\Models\RelatedItemCreatorAffiliation;
use App\Models\RelatedItemTitle;
use App\Models\RelationType;
use App\Models\Resource;

covers(
    RelatedItem::class,
    RelatedItemTitle::class,
    RelatedItemCreator::class,
    RelatedItemContributor::class,
    RelatedItemCreatorAffiliation::class,
);

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('has correct fillable attributes', function () {
    $item = new RelatedItem();
    expect($item->getFillable())
        ->toContain('resource_id', 'related_item_type', 'relation_type_id', 'publication_year', 'publisher');
});

it('belongs to a resource and relation type', function () {
    $resource = Resource::factory()->create();
    $item = RelatedItem::factory()->create(['resource_id' => $resource->id]);

    expect($item->resource)->toBeInstanceOf(Resource::class);
    expect($item->resource->id)->toBe($resource->id);
    expect($item->relationType)->toBeInstanceOf(RelationType::class);
});

it('returns the main title text', function () {
    $item = RelatedItem::factory()->create();
    RelatedItemTitle::factory()->create([
        'related_item_id' => $item->id,
        'title' => 'Alt',
        'title_type' => 'AlternativeTitle',
        'position' => 1,
    ]);
    RelatedItemTitle::factory()->create([
        'related_item_id' => $item->id,
        'title' => 'The Main',
        'title_type' => 'MainTitle',
        'position' => 0,
    ]);

    expect($item->fresh()->mainTitle())->toBe('The Main');
});

it('returns null when no main title exists', function () {
    $item = RelatedItem::factory()->create();
    RelatedItemTitle::factory()->create([
        'related_item_id' => $item->id,
        'title_type' => 'AlternativeTitle',
    ]);

    expect($item->fresh()->mainTitle())->toBeNull();
});

it('cascades deletes to children', function () {
    $item = RelatedItem::factory()->create();
    RelatedItemTitle::factory()->create(['related_item_id' => $item->id]);
    $creator = RelatedItemCreator::factory()->create(['related_item_id' => $item->id]);
    RelatedItemCreatorAffiliation::factory()->create(['related_item_creator_id' => $creator->id]);
    RelatedItemContributor::factory()->create(['related_item_id' => $item->id]);

    $item->delete();

    expect(RelatedItemTitle::count())->toBe(0);
    expect(RelatedItemCreator::count())->toBe(0);
    expect(RelatedItemCreatorAffiliation::count())->toBe(0);
    expect(RelatedItemContributor::count())->toBe(0);
});

it('loads titles ordered by position', function () {
    $item = RelatedItem::factory()->create();
    RelatedItemTitle::factory()->create(['related_item_id' => $item->id, 'title' => 'B', 'position' => 1]);
    RelatedItemTitle::factory()->create(['related_item_id' => $item->id, 'title' => 'A', 'position' => 0]);

    expect($item->titles->pluck('title')->all())->toBe(['A', 'B']);
});
