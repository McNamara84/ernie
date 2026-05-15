<?php

declare(strict_types=1);

use App\Models\IdentifierType;
use App\Models\RelationType;
use App\Services\RelatedIdentifierTypeResolverService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

covers(RelatedIdentifierTypeResolverService::class);

it('resolves fallback identifier and relation type variants to canonical slugs', function () {
    $service = app(RelatedIdentifierTypeResolverService::class);

    expect($service->resolveIdentifierType('doi'))->toBe('DOI')
        ->and($service->resolveRelationType('Is Cited By'))->toBe('IsCitedBy')
        ->and($service->resolveRelationType('is-cited-by'))->toBe('IsCitedBy');
});

it('resolves database-backed display names to canonical slugs', function () {
    IdentifierType::query()->create([
        'name' => 'My Identifier',
        'slug' => 'MyIdentifier',
        'is_active' => true,
    ]);

    RelationType::query()->create([
        'name' => 'My Relation Type',
        'slug' => 'MyRelationType',
        'is_active' => true,
    ]);

    $service = app(RelatedIdentifierTypeResolverService::class);

    expect($service->resolveIdentifierType('my identifier'))->toBe('MyIdentifier')
        ->and($service->resolveRelationType('My Relation Type'))->toBe('MyRelationType');
});

it('returns null for null, blank, non-string, and unresolvable inputs', function () {
    $service = app(RelatedIdentifierTypeResolverService::class);

    expect($service->resolveIdentifierType(null))->toBeNull()
        ->and($service->resolveRelationType(null))->toBeNull()
    ->and($service->resolveIdentifierType(['DOI']))->toBeNull()
    ->and($service->resolveRelationType(123))->toBeNull()
        ->and($service->resolveIdentifierType('   '))->toBeNull()
        ->and($service->resolveRelationType('@@@'))->toBeNull();
});