<?php

declare(strict_types=1);

use App\Http\Controllers\PublicMetadataExportController;
use App\Models\LandingPage;
use App\Models\Resource;
use App\Models\ResourceType;
use App\Models\Title;
use App\Services\Iso19115\Iso19115JsonExporter;
use App\Services\Iso19115\Iso19115JsonValidator;
use App\Services\LandingPageMetadataLinkService;
use App\Support\Iso19115\Iso19115ValidationResult;

covers(PublicMetadataExportController::class, LandingPageMetadataLinkService::class);

function isoJsonLandingPage(string $type = 'dataset', bool $published = true): LandingPage
{
    $resourceType = ResourceType::firstOrCreate(['slug' => $type], ['name' => $type, 'is_active' => true]);
    $resource = Resource::factory()->create(['resource_type_id' => $resourceType->id, 'doi' => '10.5880/multi/segment/test']);
    Title::factory()->create(['resource_id' => $resource->id, 'value' => 'Download JSON']);

    return LandingPage::factory()->create(['resource_id' => $resource->id, 'doi_prefix' => $resource->doi, 'slug' => 'json-export', 'is_published' => $published, 'template' => 'default_gfz']);
}

test('downloads valid ISO JSON anonymously using the canonical multi-segment DOI route', function () {
    $landingPage = isoJsonLandingPage();
    $response = $this->get($landingPage->getPublicPath().'/metadata/iso-19115-4.json')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/json; charset=UTF-8')
        ->assertHeader('Content-Disposition', 'attachment; filename="json-export-iso-19115-4.json"')
        ->assertJsonPath('type', 'Feature')
        ->assertJsonPath('id', '10.5880/multi/segment/test')
        ->assertJsonPath('properties.identificationInfo.citation.title', 'Download JSON');
    expect(app(Iso19115JsonValidator::class)->validate($response->json())->errors)->toBe([]);
});

test('does not expose ISO JSON for physical objects unsupported types or drafts', function (string $type, bool $published) {
    $landingPage = isoJsonLandingPage($type, $published);
    $this->get($landingPage->getPublicPath().'/metadata/iso-19115-4.json')->assertNotFound();
    $links = app(LandingPageMetadataLinkService::class)->for($landingPage->resource, $landingPage);
    expect(array_column($links, 'format'))->not->toContain('iso19115-4');
    if ($published && $type === 'physical-object') {
        $this->get($landingPage->getPublicPath().'/metadata/iso-19115-3.xml')->assertOk();
    }
})->with([['physical-object', true], ['project', true], ['dataset', false]]);

test('respects both ISO switches and never affects DataCite downloads', function (string $key) {
    $landingPage = isoJsonLandingPage();
    config([$key => false]);
    $this->get($landingPage->getPublicPath().'/metadata/iso-19115-4.json')->assertNotFound();
    $this->get($landingPage->getPublicPath().'/metadata/datacite.json')->assertOk();
    expect(array_column(app(LandingPageMetadataLinkService::class)->for($landingPage->resource, $landingPage), 'format'))->not->toContain('iso19115-4');
})->with(['iso19115.json.enabled', 'iso19115.enabled']);

test('returns not found for unknown DOI and slug pairs', function () {
    $this->get('/10.5880/missing/not-found/metadata/iso-19115-4.json')->assertNotFound();
});

test('never delivers a file when generation or validation fails', function (bool $throws) {
    $landingPage = isoJsonLandingPage();
    if ($throws) {
        $this->mock(Iso19115JsonExporter::class)->shouldReceive('export')->once()->andThrow(new RuntimeException('private details'));
    } else {
        $this->mock(Iso19115JsonValidator::class)->shouldReceive('validate')->once()->andReturn(new Iso19115ValidationResult(['/properties/contact: missing']));
    }
    $this->getJson($landingPage->getPublicPath().'/metadata/iso-19115-4.json')
        ->assertStatus(500)->assertHeaderMissing('Content-Disposition')->assertDontSee('private details');
})->with([false, true]);

test('advertises ISO JSON in HTML HTTP and Inertia metadata and keeps cached responses consistent', function () {
    $landingPage = isoJsonLandingPage();
    $url = url($landingPage->getPublicPath().'/metadata/iso-19115-4.json');
    for ($request = 0; $request < 2; $request++) {
        $response = $this->get($landingPage->getPublicPath())->assertOk()
            ->assertSee($url, escape: false)
            ->assertInertia(fn ($page) => $page->where('supportsIso19115Json', true)
                ->where('metadataLinks.4.url', $url)->where('metadataLinks.4.mediaType', 'application/json'));
        expect($response->headers->get('Link'))->toContain('<'.$url.'>; rel="describedby"; type="application/json"');
    }
});

test('draft previews advertise availability without public metadata links', function () {
    $landingPage = isoJsonLandingPage(published: false);
    $this->get($landingPage->getPublicPath().'?preview='.$landingPage->preview_token)->assertOk()
        ->assertHeaderMissing('Link')
        ->assertInertia(fn ($page) => $page->where('isPreview', true)->where('supportsIso19115Json', true)->where('metadataLinks', []));
});
