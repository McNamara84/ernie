<?php

declare(strict_types=1);

use App\Models\ResourceType;
use App\Models\Resource;
use App\Models\Right;

covers(ResourceType::class);

describe('ResourceType model attributes', function (): void {
    it('has correct fillable attributes', function (): void {
        $model = new ResourceType();

        expect($model->getFillable())->toBe([
            'name',
            'slug',
            'description',
            'is_active',
            'is_elmo_active',
        ]);
    });

    it('casts is_active to boolean', function (): void {
        $type = ResourceType::factory()->make(['is_active' => 1]);

        expect($type->is_active)->toBeBool()->toBeTrue();
    });

    it('casts is_elmo_active to boolean', function (): void {
        $type = ResourceType::factory()->make(['is_elmo_active' => 0]);

        expect($type->is_elmo_active)->toBeBool()->toBeFalse();
    });
});

describe('ResourceType scopes', function (): void {
    it('filters active resource types', function (): void {
        ResourceType::factory()->create(['is_active' => true]);
        ResourceType::factory()->create(['is_active' => false]);

        $active = ResourceType::active()->get();

        expect($active)->each(fn ($type) => $type->is_active->toBeTrue());
    });

    it('filters ELMO active resource types', function (): void {
        ResourceType::factory()->create(['is_elmo_active' => true]);
        ResourceType::factory()->create(['is_elmo_active' => false]);

        $elmoActive = ResourceType::elmoActive()->get();

        expect($elmoActive)->each(fn ($type) => $type->is_elmo_active->toBeTrue());
    });

    it('orders by name', function (): void {
        ResourceType::factory()->create(['name' => 'Zebra Type']);
        ResourceType::factory()->create(['name' => 'Alpha Type']);

        $ordered = ResourceType::orderByName()->get();
        $names = $ordered->pluck('name')->toArray();

        // First alphabetical entry should come before the last
        $alphaIndex = array_search('Alpha Type', $names);
        $zebraIndex = array_search('Zebra Type', $names);

        expect($alphaIndex)->toBeLessThan($zebraIndex);
    });
});

describe('ResourceType relationships', function (): void {
    it('has many resources', function (): void {
        $type = ResourceType::factory()->create();

        expect($type->resources())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class);
    });

    it('has excluded from rights relationship', function (): void {
        $type = ResourceType::factory()->create();

        expect($type->excludedFromRights())->toBeInstanceOf(
            \Illuminate\Database\Eloquent\Relations\BelongsToMany::class
        );
    });

    it('can check excluded rights', function (): void {
        $type = ResourceType::factory()->create();
        $right = Right::factory()->create();

        $type->excludedFromRights()->attach($right->id);

        expect($type->excludedFromRights)->toHaveCount(1);
        expect($type->excludedFromRights->first()->id)->toBe($right->id);
    });
});
