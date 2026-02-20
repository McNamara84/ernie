<?php

declare(strict_types=1);

use App\Models\Title;
use App\Models\TitleType;
use App\Models\Resource;

covers(Title::class);

describe('Title model attributes', function (): void {
    it('has correct fillable attributes', function (): void {
        $title = new Title();

        expect($title->getFillable())->toBe([
            'resource_id',
            'value',
            'title_type_id',
            'language',
        ]);
    });
});

describe('Title relationships', function (): void {
    it('belongs to a resource', function (): void {
        $resource = Resource::factory()->create();
        $titleType = TitleType::firstOrCreate(
            ['slug' => 'MainTitle'],
            ['name' => 'Main Title', 'slug' => 'MainTitle', 'is_active' => true]
        );

        $title = Title::factory()->create([
            'resource_id' => $resource->id,
            'title_type_id' => $titleType->id,
        ]);

        expect($title->resource)->toBeInstanceOf(Resource::class);
        expect($title->resource->id)->toBe($resource->id);
    });

    it('belongs to a title type', function (): void {
        $titleType = TitleType::firstOrCreate(
            ['slug' => 'AlternativeTitle'],
            ['name' => 'Alternative Title', 'slug' => 'AlternativeTitle', 'is_active' => true]
        );

        $title = Title::factory()->create([
            'title_type_id' => $titleType->id,
        ]);

        expect($title->titleType)->toBeInstanceOf(TitleType::class);
        expect($title->titleType->id)->toBe($titleType->id);
    });
});

describe('Title::isMainTitle()', function (): void {
    it('returns true when title_type_id is null (legacy data)', function (): void {
        $title = new Title();
        $title->title_type_id = null;

        expect($title->isMainTitle())->toBeTrue();
    });

    it('returns true when title type slug is MainTitle', function (): void {
        $titleType = TitleType::firstOrCreate(
            ['slug' => 'MainTitle'],
            ['name' => 'Main Title', 'slug' => 'MainTitle', 'is_active' => true]
        );

        $title = Title::factory()->create([
            'title_type_id' => $titleType->id,
        ]);

        // Eager load the relation
        $title->load('titleType');

        expect($title->isMainTitle())->toBeTrue();
    });

    it('returns false when title type is not MainTitle', function (): void {
        $titleType = TitleType::firstOrCreate(
            ['slug' => 'AlternativeTitle'],
            ['name' => 'Alternative Title', 'slug' => 'AlternativeTitle', 'is_active' => true]
        );

        $title = Title::factory()->create([
            'title_type_id' => $titleType->id,
        ]);

        // Eager load the relation
        $title->load('titleType');

        expect($title->isMainTitle())->toBeFalse();
    });

    it('returns false when title type relation is not loaded to prevent N+1', function (): void {
        $titleType = TitleType::firstOrCreate(
            ['slug' => 'MainTitle'],
            ['name' => 'Main Title', 'slug' => 'MainTitle', 'is_active' => true]
        );

        $title = Title::factory()->create([
            'title_type_id' => $titleType->id,
        ]);

        // Deliberately do NOT load the relation
        $fresh = Title::find($title->id);

        // Without eager loading, isMainTitle returns false to prevent N+1
        expect($fresh->isMainTitle())->toBeFalse();
    });
});
