<?php

declare(strict_types=1);

use App\Models\Description;
use App\Models\DescriptionType;
use App\Models\LandingPage;
use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceCreator;
use App\Models\ResourceType;
use App\Models\Title;
use App\Models\TitleType;
use App\Services\Citations\LandingPageCitationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->portalPreviewDatasetType = ResourceType::factory()->create([
        'name' => 'Dataset',
        'slug' => 'dataset',
    ]);
    $this->portalPreviewIgsnType = ResourceType::factory()->create([
        'name' => 'Physical Object',
        'slug' => 'physical-object',
    ]);
});

function portalPreviewResource(ResourceType $type, bool $published = true, ?string $doi = null): Resource
{
    $resource = Resource::factory()->create([
        'resource_type_id' => $type->id,
        'doi' => $doi ?? '10.5880/portal.preview.001',
        'publication_year' => 2026,
    ]);
    $titleType = TitleType::firstOrCreate(
        ['slug' => 'MainTitle'],
        ['name' => 'Main Title', 'is_active' => true],
    );
    Title::factory()->create([
        'resource_id' => $resource->id,
        'title_type_id' => $titleType->id,
        'value' => 'A portal preview resource',
        'language' => 'en',
    ]);
    $person = Person::factory()->create([
        'given_name' => 'Jane',
        'family_name' => 'Smith',
    ]);
    ResourceCreator::factory()->forPerson($person)->create([
        'resource_id' => $resource->id,
        'position' => 1,
    ]);
    LandingPage::factory()->create([
        'resource_id' => $resource->id,
        'doi_prefix' => $resource->doi,
        'is_published' => $published,
        'published_at' => $published ? now() : null,
    ]);

    return $resource;
}

function portalPreviewDescription(Resource $resource, string $value, ?string $language = 'en', string $typeSlug = 'Abstract'): Description
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

it('publishes preview routes in both portal endpoint families', function () {
    expect(route('portal.doi.resource-preview', ['resourceId' => 42], absolute: false))
        ->toBe('/doi-search/resources/42/preview')
        ->and(route('portal.igsn.resource-preview', ['resourceId' => 42], absolute: false))
        ->toBe('/igsn-search/resources/42/preview');
});

it('returns the same APA 7 plaintext as the landing-page citation service', function () {
    $resource = portalPreviewResource($this->portalPreviewDatasetType);
    portalPreviewDescription($resource, 'The complete English abstract.');
    $citationResource = $resource->fresh()->load([
        'titles.titleType',
        'creators.creatorable',
        'resourceType',
        'publisher',
        'language',
    ]);
    $expected = app(LandingPageCitationService::class)->formatStyle($citationResource, 'apa-7')['text'];

    $this->getJson(route('portal.doi.resource-preview', ['resourceId' => $resource->id]))
        ->assertOk()
        ->assertExactJson([
            'resourceId' => $resource->id,
            'citation' => [
                'styleId' => 'apa-7',
                'label' => 'APA 7',
                'text' => $expected,
            ],
            'abstract' => 'The complete English abstract.',
        ]);
});

it('selects the preferred abstract deterministically and excludes other description types', function () {
    $resource = portalPreviewResource($this->portalPreviewDatasetType);
    portalPreviewDescription($resource, 'Deutsche Zusammenfassung.', 'de');
    portalPreviewDescription($resource, 'English regional abstract.', 'en-GB');
    portalPreviewDescription($resource, 'Preferred exact English abstract.', 'en');
    portalPreviewDescription($resource, 'Methods must not appear.', 'en', 'Methods');

    $this->getJson(route('portal.doi.resource-preview', ['resourceId' => $resource->id]))
        ->assertOk()
        ->assertJsonPath('abstract', 'Preferred exact English abstract.')
        ->assertJsonMissing(['abstract' => 'Methods must not appear.']);
});

it('returns null when no non-empty abstract is available', function () {
    $resource = portalPreviewResource($this->portalPreviewDatasetType);
    portalPreviewDescription($resource, '   ', 'en');

    $this->getJson(route('portal.doi.resource-preview', ['resourceId' => $resource->id]))
        ->assertOk()
        ->assertJsonPath('abstract', null);
});

it('returns IGSN previews only from the IGSN scope and uses the visible handle', function () {
    config(['datacite.production.igsn_prefix' => '10.60510']);
    $resource = portalPreviewResource($this->portalPreviewIgsnType, doi: '10.60510/TEST123');
    portalPreviewDescription($resource, 'Physical sample abstract.');

    $this->getJson(route('portal.igsn.resource-preview', ['resourceId' => $resource->id]))
        ->assertOk()
        ->assertJsonPath('abstract', 'Physical sample abstract.')
        ->assertJsonPath('citation.styleId', 'apa-7')
        ->assertJsonPath('citation.text', fn (string $text): bool => str_contains($text, 'TEST123')
            && ! str_contains($text, '10.60510/TEST123')
            && ! str_contains($text, 'doi.org'));

    $this->getJson(route('portal.doi.resource-preview', ['resourceId' => $resource->id]))
        ->assertNotFound();
});

it('does not expose DOI resources through the IGSN scope', function () {
    $resource = portalPreviewResource($this->portalPreviewDatasetType);

    $this->getJson(route('portal.igsn.resource-preview', ['resourceId' => $resource->id]))
        ->assertNotFound();
});

it('does not expose missing or unpublished resources', function () {
    $unpublished = portalPreviewResource($this->portalPreviewDatasetType, published: false);

    $this->getJson(route('portal.doi.resource-preview', ['resourceId' => $unpublished->id]))
        ->assertNotFound()
        ->assertJsonPath('message', 'Resource not found.');
    $this->getJson(route('portal.doi.resource-preview', ['resourceId' => 999_999]))
        ->assertNotFound()
        ->assertJsonPath('message', 'Resource not found.');
});

it('returns hostile metadata as plain JSON text without an HTML field', function () {
    $resource = portalPreviewResource($this->portalPreviewDatasetType);
    portalPreviewDescription($resource, '<script>alert("abstract")</script> & safe text');
    $resource->titles()->firstOrFail()->update(['value' => '<img src=x onerror=alert(1)> title']);

    $response = $this->getJson(route('portal.doi.resource-preview', ['resourceId' => $resource->id]))
        ->assertOk()
        ->assertJsonPath('abstract', '<script>alert("abstract")</script> & safe text')
        ->assertJsonMissingPath('citation.html');

    expect($response->json('citation.text'))
        ->toContain('<img src=x onerror=alert(1)> title');
});

it('applies the public portal rate limit to preview requests', function () {
    config([
        'bot_protection.enabled' => true,
        'bot_protection.limits.public_portal_per_minute' => 1,
    ]);
    $resource = portalPreviewResource($this->portalPreviewDatasetType);
    RateLimiter::clear('portal:public:203.0.113.91');

    $request = fn () => $this
        ->withServerVariables([
            'REMOTE_ADDR' => '203.0.113.91',
            'HTTP_USER_AGENT' => 'Mozilla/5.0',
        ])
        ->getJson(route('portal.doi.resource-preview', ['resourceId' => $resource->id]));

    $request()->assertOk();
    $request()->assertTooManyRequests();
});
