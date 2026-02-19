<?php

declare(strict_types=1);

use App\Models\Right;
use App\Models\Resource;
use App\Models\ResourceType;

covers(Right::class);

describe('Right model attributes', function (): void {
    it('has correct fillable attributes', function (): void {
        $right = new Right();

        expect($right->getFillable())->toBe([
            'identifier',
            'name',
            'uri',
            'scheme_uri',
            'is_active',
            'is_elmo_active',
            'usage_count',
        ]);
    });

    it('casts is_active to boolean', function (): void {
        $right = Right::factory()->make(['is_active' => 1]);

        expect($right->is_active)->toBeTrue()->and($right->is_active)->toBeBool();
    });

    it('casts is_elmo_active to boolean', function (): void {
        $right = Right::factory()->make(['is_elmo_active' => 0]);

        expect($right->is_elmo_active)->toBeFalse()->and($right->is_elmo_active)->toBeBool();
    });

    it('casts usage_count to integer', function (): void {
        $right = Right::factory()->make(['usage_count' => '42']);

        expect($right->usage_count)->toBe(42)->and($right->usage_count)->toBeInt();
    });
});

describe('Right scopes', function (): void {
    it('filters active rights', function (): void {
        Right::factory()->create(['is_active' => true]);
        Right::factory()->create(['is_active' => false]);

        $activeRights = Right::active()->get();

        expect($activeRights)->each(fn ($right) => $right->is_active->toBeTrue());
    });

    it('filters ELMO active rights', function (): void {
        Right::factory()->create(['is_elmo_active' => true]);
        Right::factory()->create(['is_elmo_active' => false]);

        $elmoActiveRights = Right::elmoActive()->get();

        expect($elmoActiveRights)->each(fn ($right) => $right->is_elmo_active->toBeTrue());
    });

    it('orders by name', function (): void {
        Right::factory()->create(['name' => 'Zebra License']);
        Right::factory()->create(['name' => 'Alpha License']);

        $rights = Right::orderByName()->get();

        expect($rights->first()->name)->toBe('Alpha License');
        expect($rights->last()->name)->toBe('Zebra License');
    });

    it('orders by usage count descending with name fallback', function (): void {
        Right::factory()->create(['name' => 'Beta', 'usage_count' => 10]);
        Right::factory()->create(['name' => 'Alpha', 'usage_count' => 10]);
        Right::factory()->create(['name' => 'Gamma', 'usage_count' => 50]);

        $rights = Right::orderByUsageCount()->get();

        expect($rights->first()->name)->toBe('Gamma');
        // Same usage count → alphabetical
        expect($rights[1]->name)->toBe('Alpha');
        expect($rights[2]->name)->toBe('Beta');
    });
});

describe('Right relationships', function (): void {
    it('belongs to many resources', function (): void {
        $right = Right::factory()->create();
        $resource = Resource::factory()->create();

        $right->resources()->attach($resource->id);

        expect($right->resources)->toHaveCount(1);
        expect($right->resources->first()->id)->toBe($resource->id);
    });

    it('has excluded resource types relationship', function (): void {
        $right = Right::factory()->create();

        expect($right->excludedResourceTypes())->toBeInstanceOf(
            \Illuminate\Database\Eloquent\Relations\BelongsToMany::class
        );
    });
});

describe('Right::isAvailableForResourceType()', function (): void {
    it('returns true when resource type is not excluded', function (): void {
        $right = Right::factory()->create();
        $resourceType = ResourceType::factory()->create();

        expect($right->isAvailableForResourceType($resourceType->id))->toBeTrue();
    });

    it('returns false when resource type is excluded', function (): void {
        $right = Right::factory()->create();
        $resourceType = ResourceType::factory()->create();

        $right->excludedResourceTypes()->attach($resourceType->id);

        expect($right->isAvailableForResourceType($resourceType->id))->toBeFalse();
    });
});

describe('Right::scopeAvailableForResourceType()', function (): void {
    it('filters rights available for a resource type', function (): void {
        $resourceType = ResourceType::factory()->create();
        $availableRight = Right::factory()->create(['name' => 'Available']);
        $excludedRight = Right::factory()->create(['name' => 'Excluded']);

        $excludedRight->excludedResourceTypes()->attach($resourceType->id);

        $rights = Right::availableForResourceType($resourceType->id)->get();

        expect($rights->pluck('name')->toArray())->toContain('Available');
        expect($rights->pluck('name')->toArray())->not->toContain('Excluded');
    });
});
