<?php

declare(strict_types=1);

use App\Models\Description;
use App\Models\DescriptionType;
use App\Models\Language;
use App\Models\Resource;
use App\Services\PreferredAbstractResolverService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

covers(PreferredAbstractResolverService::class);

function preferredAbstractTestDescription(Resource $resource, string $value, ?string $language, string $typeSlug = 'Abstract'): Description
{
    $type = DescriptionType::firstOrCreate(
        ['slug' => $typeSlug],
        ['name' => $typeSlug, 'is_active' => true],
    );

    return Description::factory()->create([
        'resource_id' => $resource->id,
        'description_type_id' => $type->id,
        'value' => $value,
        'language' => $language,
    ]);
}

it('does not trigger lazy loading when descriptions were not provided', function () {
    $resource = Resource::factory()->create();

    expect(app(PreferredAbstractResolverService::class)->resolve($resource))->toBeNull()
        ->and($resource->relationLoaded('descriptions'))->toBeFalse();
});

it('prefers an exact resource-language abstract', function () {
    $german = Language::factory()->create(['code' => 'de', 'name' => 'German']);
    $resource = Resource::factory()->create(['language_id' => $german->id]);
    preferredAbstractTestDescription($resource, 'Abstract without language.', null);
    preferredAbstractTestDescription($resource, 'English abstract.', 'en');
    preferredAbstractTestDescription($resource, 'Deutscher Abstract.', 'de');

    $loaded = $resource->fresh()->load(['language', 'descriptions.descriptionType']);

    expect(app(PreferredAbstractResolverService::class)->resolve($loaded))->toBe('Deutscher Abstract.');
});

it('falls back to English and then the lowest description id', function () {
    $resource = Resource::factory()->create();
    preferredAbstractTestDescription($resource, 'First English abstract.', 'en');
    preferredAbstractTestDescription($resource, 'Second English abstract.', 'en');
    preferredAbstractTestDescription($resource, 'Deutscher Abstract.', 'de');

    $loaded = $resource->fresh()->load(['language', 'descriptions.descriptionType']);

    expect(app(PreferredAbstractResolverService::class)->resolve($loaded))->toBe('First English abstract.');
});

it('uses primary subtags for English and German fallback variants', function () {
    $resource = Resource::factory()->create();
    preferredAbstractTestDescription($resource, 'Deutsche Zusammenfassung.', 'de-DE');
    preferredAbstractTestDescription($resource, 'Canadian English abstract.', 'en-CA');

    $loaded = $resource->fresh()->load(['language', 'descriptions.descriptionType']);

    expect(app(PreferredAbstractResolverService::class)->resolve($loaded))->toBe('Canadian English abstract.');
});

it('ignores non-abstract descriptions and trims the selected value', function () {
    $resource = Resource::factory()->create();
    preferredAbstractTestDescription($resource, 'Methods must not be selected.', 'en', 'Methods');
    preferredAbstractTestDescription($resource, '  Selected abstract.  ', 'en');

    $loaded = $resource->fresh()->load(['language', 'descriptions.descriptionType']);

    expect(app(PreferredAbstractResolverService::class)->resolve($loaded))->toBe('Selected abstract.');
});

it('skips an earlier blank abstract when a later usable abstract exists', function () {
    $resource = Resource::factory()->create();
    preferredAbstractTestDescription($resource, '   ', 'en');
    preferredAbstractTestDescription($resource, 'Available English abstract.', 'en');

    $loaded = $resource->fresh()->load(['language', 'descriptions.descriptionType']);

    expect(app(PreferredAbstractResolverService::class)->resolve($loaded))->toBe('Available English abstract.');
});

it('returns null when all abstracts are empty', function () {
    $resource = Resource::factory()->create();
    preferredAbstractTestDescription($resource, '   ', 'en');

    $loaded = $resource->fresh()->load(['language', 'descriptions.descriptionType']);

    expect(app(PreferredAbstractResolverService::class)->resolve($loaded))->toBeNull();
});
