<?php

declare(strict_types=1);

use App\Enums\SchemaOrgProfile;
use App\Models\Resource;
use App\Models\ResourceType;
use App\Services\SchemaOrgResourceTypeMappingService;
use App\Support\SchemaOrgResourceType;
use Database\Seeders\ResourceTypeSeeder;
use Tests\Fixtures\SchemaOrgResourceTypes;

covers(SchemaOrgResourceTypeMappingService::class, SchemaOrgResourceType::class);

it('resolves every reviewed slug with explicit fallback and profile semantics', function (string $slug, string $type, string $profile, bool $fallback): void {
    $mapping = app(SchemaOrgResourceTypeMappingService::class)->resolveSlug($slug);

    expect($mapping->primaryType)->toBe('https://schema.org/'.$type)
        ->and($mapping->profile->value)->toBe($profile)
        ->and($mapping->jsonLdType())->toBe($slug === 'software' ? ['SoftwareSourceCode', 'SoftwareApplication'] : $type)
        ->and($mapping->additionalTypes)->toBe($slug === 'software' ? ['https://schema.org/SoftwareApplication'] : []);
    if ($fallback) {
        expect($mapping->fallbackReason)->toBeString()->not->toBeEmpty()
            ->and($mapping->additionalType)->toBe(ResourceType::slugToDataciteResourceTypeGeneral($slug));
    } else {
        expect($mapping->fallbackReason)->toBeNull()
            ->and($mapping->additionalType)->toBeNull();
    }
})->with(SchemaOrgResourceTypes::cases());

it('explicitly covers all seeded resource types and validates the configured profiles', function (): void {
    $this->seed(ResourceTypeSeeder::class);
    $slugs = ResourceType::query()->orderBy('slug')->pluck('slug')->all();
    $configured = array_keys(config('schemaorg.resource_types'));
    sort($configured);
    $reviewed = array_keys(SchemaOrgResourceTypes::cases());
    sort($reviewed);

    expect($configured)->toBe($slugs)->toBe($reviewed);
    foreach (config('schemaorg.resource_types') as $mapping) {
        expect($mapping['primary_type'])->toMatch('/\Ahttps:\/\/schema\.org\/[A-Za-z0-9]+\z/')
            ->and(SchemaOrgProfile::tryFrom($mapping['profile']))->not->toBeNull();
        if (in_array($mapping['primary_type'], ['https://schema.org/Thing', 'https://schema.org/CreativeWork'], true)) {
            expect($mapping['fallback_reason'])->toBeString()->not->toBeEmpty();
        }
    }
});

it('uses the safe runtime fallback without inventing a DataCite type', function (?string $slug): void {
    $mapping = app(SchemaOrgResourceTypeMappingService::class)->resolveSlug($slug);

    expect($mapping->primaryType)->toBe('https://schema.org/Thing')
        ->and($mapping->jsonLdType())->toBe('Thing')
        ->and($mapping->profile)->toBe(SchemaOrgProfile::DESCRIBED_OBJECT)
        ->and($mapping->fallbackReason)->toBeString()->not->toBeEmpty()
        ->and($mapping->additionalType)->toBeNull()
        ->and($mapping->additionalTypes)->toBe([]);
})->with([null, '', 'custom-material', 'Dataset', 'dataset ']);

it('honors approved configuration changes for both primary and additional types', function (): void {
    config(['schemaorg.resource_types.model' => [
        'primary_type' => 'https://schema.org/3DModel',
        'additional_types' => [],
        'profile' => 'media',
        'fallback_reason' => null,
    ]]);

    $mapping = app(SchemaOrgResourceTypeMappingService::class)->resolveSlug('model');
    expect($mapping->primaryType)->toBe('https://schema.org/3DModel')
        ->and($mapping->jsonLdType())->toBe('3DModel')
        ->and($mapping->profile)->toBe(SchemaOrgProfile::MEDIA)
        ->and($mapping->fallbackReason)->toBeNull();
});

it('resolves loaded and unloaded resource types independently of renamed or inactive labels', function (string $slug, string $expected): void {
    $type = ResourceType::factory()->create(['slug' => $slug, 'name' => 'A renamed label', 'is_active' => false]);
    $resource = Resource::factory()->create(['resource_type_id' => $type->id]);
    $service = app(SchemaOrgResourceTypeMappingService::class);

    $resource->unsetRelation('resourceType');
    expect($service->resolve($resource)->primaryType)->toBe($expected)
        ->and($resource->relationLoaded('resourceType'))->toBeTrue()
        ->and($service->resolve($resource)->primaryType)->toBe($expected);
})->with([
    ['journal-article', 'https://schema.org/ScholarlyArticle'],
    ['physical-object', 'https://schema.org/Thing'],
]);

it('resolves a resource without a type relationship', function (): void {
    $resource = Resource::factory()->create(['resource_type_id' => null]);
    expect(app(SchemaOrgResourceTypeMappingService::class)->resolve($resource)->primaryType)->toBe('https://schema.org/Thing');
});
