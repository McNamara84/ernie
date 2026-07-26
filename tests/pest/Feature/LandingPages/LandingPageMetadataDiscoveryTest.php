<?php

declare(strict_types=1);

use App\Http\Controllers\LandingPagePublicController;
use App\Models\LandingPage;
use App\Models\Resource;
use App\Models\ResourceType;
use App\Services\LandingPageMetadataLinkService;

covers(LandingPagePublicController::class, LandingPageMetadataLinkService::class);

/**
 * @return array{0: Resource, 1: LandingPage}
 */
function landingPageForMetadataDiscovery(string $resourceTypeSlug = 'dataset', bool $published = true): array
{
    $resourceType = ResourceType::firstOrCreate(
        ['slug' => $resourceTypeSlug],
        ['name' => ucfirst(str_replace('-', ' ', $resourceTypeSlug)), 'is_active' => true],
    );
    $resource = Resource::factory()->create([
        'doi' => '10.5880/test.discovery.001',
        'resource_type_id' => $resourceType->id,
    ]);
    $landingPageFactory = LandingPage::factory();
    $landingPage = ($published ? $landingPageFactory->published() : $landingPageFactory->draft())->create([
        'resource_id' => $resource->id,
        'doi_prefix' => $resource->doi,
        'slug' => 'metadata-discovery',
        'template' => 'default_gfz',
    ]);

    return [$resource->refresh(), $landingPage];
}

test('eligible landing pages expose canonical metadata links and ISO describedby header', function () {
    [$resource, $landingPage] = landingPageForMetadataDiscovery();
    $metadataBaseUrl = url($landingPage->getPublicPath().'/metadata');
    $isoUrl = "{$metadataBaseUrl}/iso-19115-3.xml";
    $linkService = app(LandingPageMetadataLinkService::class);
    $linkHeader = $linkService->toHttpLinkHeader($linkService->for($resource, $landingPage));

    $this->get($landingPage->getPublicPath())
        ->assertOk()
        ->assertHeader('Link', $linkHeader)
        ->assertInertia(fn ($page) => $page
            ->has('metadataLinks', 4)
            ->where('metadataLinks.0.format', 'datacite-xml')
            ->where('metadataLinks.0.url', "{$metadataBaseUrl}/datacite.xml")
            ->where('metadataLinks.1.format', 'datacite-json')
            ->where('metadataLinks.1.url', "{$metadataBaseUrl}/datacite.json")
            ->where('metadataLinks.2.format', 'datacite-jsonld')
            ->where('metadataLinks.2.url', "{$metadataBaseUrl}/datacite.jsonld")
            ->where('metadataLinks.3.format', 'iso19115-3')
            ->where('metadataLinks.3.url', $isoUrl)
            ->where('metadataLinks.3.mediaType', 'application/xml')
            ->where('metadataLinks.3.profile', config('iso19115.profile'))
        );
});

test('excluded resource types retain DataCite links without advertising ISO metadata', function () {
    [$resource, $landingPage] = landingPageForMetadataDiscovery('project');
    $linkService = app(LandingPageMetadataLinkService::class);
    $linkHeader = $linkService->toHttpLinkHeader($linkService->for($resource, $landingPage));

    $this->get($landingPage->getPublicPath())
        ->assertOk()
        ->assertHeader('Link', $linkHeader)
        ->assertInertia(fn ($page) => $page
            ->has('metadataLinks', 3)
            ->where('metadataLinks.0.format', 'datacite-xml')
            ->where('metadataLinks.1.format', 'datacite-json')
            ->where('metadataLinks.2.format', 'datacite-jsonld')
        );

    expect($linkHeader)->not->toContain('iso-19115-3.xml');
});

test('ISO feature flag removes discovery without affecting DataCite representations', function () {
    [$resource, $landingPage] = landingPageForMetadataDiscovery();
    config(['iso19115.enabled' => false]);
    $linkService = app(LandingPageMetadataLinkService::class);
    $linkHeader = $linkService->toHttpLinkHeader($linkService->for($resource, $landingPage));

    $this->get($landingPage->getPublicPath())
        ->assertOk()
        ->assertHeader('Link', $linkHeader)
        ->assertInertia(fn ($page) => $page->has('metadataLinks', 3));
});

test('draft previews never advertise public metadata endpoints', function () {
    [, $landingPage] = landingPageForMetadataDiscovery(published: false);

    $this->get($landingPage->getPublicPath().'?preview='.$landingPage->preview_token)
        ->assertOk()
        ->assertHeaderMissing('Link')
        ->assertInertia(fn ($page) => $page
            ->where('isPreview', true)
            ->where('metadataLinks', [])
        );
});

test('HTTP metadata link serialization rejects unsafe URLs and escapes quoted parameters', function () {
    $service = app(LandingPageMetadataLinkService::class);
    $safeLink = [
        'format' => 'test',
        'standard' => 'Test',
        'label' => 'Test',
        'url' => 'https://example.org/metadata.xml',
        'mediaType' => 'application/xml; note="quoted"',
        'profile' => "https://example.org/profile\r\nignored",
    ];
    $unsafeLink = [
        ...$safeLink,
        'url' => "https://example.org/metadata.xml>\r\nX-Test: injected",
    ];

    expect($service->toHttpLinkHeader([$safeLink, $unsafeLink]))
        ->toBe(
            '<https://example.org/metadata.xml>; rel="describedby"; '
            .'type="application/xml; note=\\"quoted\\""; '
            .'profile="https://example.org/profileignored"',
        )
        ->and($service->toHttpLinkHeader([$unsafeLink]))->toBeNull();
});
