<?php

declare(strict_types=1);

use App\Models\Publisher;
use App\Models\Resource;

covers(Publisher::class);

describe('Publisher model attributes', function (): void {
    it('has correct fillable attributes', function (): void {
        $publisher = new Publisher();

        expect($publisher->getFillable())->toBe([
            'name',
            'identifier',
            'identifier_scheme',
            'scheme_uri',
            'language',
            'is_default',
        ]);
    });

    it('casts is_default to boolean', function (): void {
        $publisher = Publisher::factory()->make(['is_default' => 1]);

        expect($publisher->is_default)->toBeBool();
    });
});

describe('Publisher::getDefault()', function (): void {
    it('returns default publisher when one exists', function (): void {
        Publisher::factory()->create(['is_default' => false, 'name' => 'Not Default']);
        $default = Publisher::factory()->create(['is_default' => true, 'name' => 'GFZ Data Services']);

        $result = Publisher::getDefault();

        expect($result)->not->toBeNull();
        expect($result->id)->toBe($default->id);
        expect($result->name)->toBe('GFZ Data Services');
    });

    it('returns null when no default publisher exists', function (): void {
        Publisher::factory()->create(['is_default' => false]);

        $result = Publisher::getDefault();

        expect($result)->toBeNull();
    });
});

describe('Publisher scopes', function (): void {
    it('filters default publishers', function (): void {
        Publisher::factory()->create(['is_default' => false]);
        Publisher::factory()->create(['is_default' => true]);

        $defaults = Publisher::default()->get();

        expect($defaults)->each(fn ($pub) => $pub->is_default->toBeTrue());
    });
});

describe('Publisher relationships', function (): void {
    it('has many resources', function (): void {
        $publisher = Publisher::factory()->create();
        Resource::factory()->create(['publisher_id' => $publisher->id]);

        expect($publisher->resources)->toHaveCount(1);
        expect($publisher->resources()->getRelated())->toBeInstanceOf(Resource::class);
    });
});
