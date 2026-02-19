<?php

declare(strict_types=1);

use App\Models\Language;
use App\Models\Resource;

covers(Language::class);

describe('Language model attributes', function (): void {
    it('has correct fillable attributes', function (): void {
        $language = new Language();

        expect($language->getFillable())->toBe([
            'code',
            'name',
            'active',
            'elmo_active',
        ]);
    });

    it('casts active to boolean', function (): void {
        $language = Language::factory()->make(['active' => 1]);

        expect($language->active)->toBeBool()->toBeTrue();
    });

    it('casts elmo_active to boolean', function (): void {
        $language = Language::factory()->make(['elmo_active' => 0]);

        expect($language->elmo_active)->toBeBool()->toBeFalse();
    });
});

describe('Language scopes', function (): void {
    it('filters active languages', function (): void {
        Language::factory()->create(['active' => true]);
        Language::factory()->create(['active' => false]);

        $active = Language::active()->get();

        expect($active)->each(fn ($lang) => $lang->active->toBeTrue());
    });

    it('filters ELMO active languages', function (): void {
        Language::factory()->create(['elmo_active' => true]);
        Language::factory()->create(['elmo_active' => false]);

        $elmoActive = Language::elmoActive()->get();

        expect($elmoActive)->each(fn ($lang) => $lang->elmo_active->toBeTrue());
    });

    it('orders by name', function (): void {
        Language::factory()->create(['name' => 'Zulu']);
        Language::factory()->create(['name' => 'Arabic']);

        $ordered = Language::orderByName()->get();
        $names = $ordered->pluck('name')->toArray();

        $arabicIndex = array_search('Arabic', $names);
        $zuluIndex = array_search('Zulu', $names);

        expect($arabicIndex)->toBeLessThan($zuluIndex);
    });
});

describe('Language relationships', function (): void {
    it('has many resources', function (): void {
        $language = Language::factory()->create();

        expect($language->resources())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class);
    });
});
