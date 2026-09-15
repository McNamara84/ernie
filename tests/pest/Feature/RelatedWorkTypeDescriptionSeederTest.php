<?php

declare(strict_types=1);

use App\Models\IdentifierType;
use App\Models\RelationType;
use Database\Seeders\IdentifierTypeSeeder;
use Database\Seeders\RelationTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('defines a unique non-empty description for every supported type', function (): void {
    expect(RelationTypeSeeder::TYPES)->toHaveCount(39)
        ->and(IdentifierTypeSeeder::TYPES)->toHaveCount(23);

    foreach ([RelationTypeSeeder::TYPES, IdentifierTypeSeeder::TYPES] as $types) {
        $slugs = array_column($types, 'slug');

        expect(array_unique($slugs))->toHaveCount(count($types));

        foreach ($types as $type) {
            expect(trim($type['name']))->not->toBeEmpty()
                ->and(trim($type['slug']))->not->toBeEmpty()
                ->and(trim($type['description']))->not->toBeEmpty();
        }
    }
});

it('persists the versioned DataCite 4.7 descriptions', function (): void {
    $this->seed([
        RelationTypeSeeder::class,
        IdentifierTypeSeeder::class,
    ]);

    expect(RelationType::query()->count())->toBe(count(RelationTypeSeeder::TYPES))
        ->and(RelationType::query()->whereNull('description')->orWhere('description', '')->exists())->toBeFalse()
        ->and(RelationType::query()->where('slug', 'Cites')->value('description'))
        ->toBe('Indicates that A includes B in a citation')
        ->and(IdentifierType::query()->count())->toBe(count(IdentifierTypeSeeder::TYPES))
        ->and(IdentifierType::query()->whereNull('description')->orWhere('description', '')->exists())->toBeFalse()
        ->and(IdentifierType::query()->where('slug', 'DOI')->value('description'))
        ->toBe('A character string used to uniquely identify an object. A DOI name is divided into two parts, a prefix and a suffix, separated by a slash.');
});

it('repairs descriptions without changing existing names or activation flags', function (): void {
    $this->seed([
        RelationTypeSeeder::class,
        IdentifierTypeSeeder::class,
    ]);

    $relationCount = RelationType::query()->count();
    $identifierCount = IdentifierType::query()->count();

    RelationType::query()->where('slug', 'Cites')->update([
        'name' => 'Custom citation label',
        'description' => 'Outdated relation description',
        'is_active' => false,
        'is_elmo_active' => false,
    ]);
    IdentifierType::query()->where('slug', 'DOI')->update([
        'name' => 'Custom DOI label',
        'description' => 'Outdated identifier description',
        'is_active' => false,
        'is_elmo_active' => false,
    ]);

    $this->seed([
        RelationTypeSeeder::class,
        IdentifierTypeSeeder::class,
    ]);

    $relationType = RelationType::query()->where('slug', 'Cites')->firstOrFail();
    $identifierType = IdentifierType::query()->where('slug', 'DOI')->firstOrFail();

    expect(RelationType::query()->count())->toBe($relationCount)
        ->and($relationType->name)->toBe('Custom citation label')
        ->and($relationType->description)->toBe('Indicates that A includes B in a citation')
        ->and($relationType->is_active)->toBeFalse()
        ->and($relationType->is_elmo_active)->toBeFalse()
        ->and(IdentifierType::query()->count())->toBe($identifierCount)
        ->and($identifierType->name)->toBe('Custom DOI label')
        ->and($identifierType->description)
        ->toBe('A character string used to uniquely identify an object. A DOI name is divided into two parts, a prefix and a suffix, separated by a slash.')
        ->and($identifierType->is_active)->toBeFalse()
        ->and($identifierType->is_elmo_active)->toBeFalse();
});

it('exposes populated descriptions through all related work type endpoints', function (): void {
    config(['services.ernie.api_key' => 'test-api-key']);

    $this->seed([
        RelationTypeSeeder::class,
        IdentifierTypeSeeder::class,
    ]);

    $endpoints = [
        ['/api/v1/relation-types', []],
        ['/api/v1/relation-types/ernie', []],
        ['/api/v1/relation-types/elmo', ['X-API-Key' => 'test-api-key']],
        ['/api/v1/identifier-types', []],
        ['/api/v1/identifier-types/ernie', []],
        ['/api/v1/identifier-types/elmo', ['X-API-Key' => 'test-api-key']],
    ];

    foreach ($endpoints as [$endpoint, $headers]) {
        $data = $this->getJson($endpoint, $headers)
            ->assertOk()
            ->json();

        expect($data)->not->toBeEmpty();

        foreach ($data as $type) {
            expect($type)->toHaveKey('description')
                ->and($type['description'])->toBeString()->not->toBeEmpty();
        }
    }
});
