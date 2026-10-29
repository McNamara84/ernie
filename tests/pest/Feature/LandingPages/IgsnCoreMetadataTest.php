<?php

declare(strict_types=1);

use App\Http\Controllers\IgsnCoreSchemaController;
use App\Http\Controllers\PublicMetadataExportController;
use App\Models\Description;
use App\Models\GeoLocation;
use App\Models\LandingPage;
use App\Models\Resource;
use App\Models\ResourceCreator;
use App\Models\ResourceType;
use App\Models\Title;
use App\Services\Igsn\Export\IgsnCoreSchemaResolver;
use App\Services\Igsn\Export\IgsnCoreSourceBuilder;
use App\Services\Igsn\Export\IgsnCoreVocabulary;
use App\Services\Igsn\Export\IgsnCoreXmlExporter;
use App\Services\Igsn\Export\IgsnCoreXmlValidator;
use App\Services\LandingPageMetadataLinkService;

covers(IgsnCoreSchemaController::class, PublicMetadataExportController::class, IgsnCoreSchemaResolver::class, LandingPageMetadataLinkService::class, IgsnCoreXmlExporter::class, IgsnCoreXmlValidator::class, IgsnCoreSourceBuilder::class, IgsnCoreVocabulary::class);

function coreLandingPage(bool $physical = true, bool $published = true): LandingPage
{
    $resource = Resource::factory()->create(['doi' => '10.58052/IE/CORE001']);
    if ($physical) {
        $type = ResourceType::firstOrCreate(['slug' => 'physical-object'], ['name' => 'PhysicalObject']);
        $resource->update(['resource_type_id' => $type->id]);
    }
    Title::factory()->create(['resource_id' => $resource->id]);
    ResourceCreator::factory()->create(['resource_id' => $resource->id]);

    return LandingPage::factory()->create(['resource_id' => $resource->id, 'doi_prefix' => $resource->doi, 'slug' => 'core-sample', 'is_published' => $published]);
}

test('downloads only locally validated Core XML on the canonical public route with nested DOI suffixes', function () {
    $page = coreLandingPage();
    $response = $this->get($page->getPublicPath().'/metadata/igsn-core-2.0.xml')->assertOk()
        ->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
        ->assertHeader('Content-Disposition', 'attachment; filename="core-sample-igsn-core-2.0.xml"');
    expect(app(IgsnCoreXmlValidator::class)->validate($response->getContent())->errors)->toBe([]);
});

test('downloads titles and descriptions when their languages and the resource language are missing', function () {
    $page = coreLandingPage();
    $page->resource->update(['language_id' => null]);
    $page->resource->titles()->update(['value' => 'Sample without language', 'language' => null]);
    Description::factory()->abstract()->create(['resource_id' => $page->resource_id, 'value' => 'Description without language', 'language' => null]);

    $response = $this->get($page->getPublicPath().'/metadata/igsn-core-2.0.xml')->assertOk()
        ->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
        ->assertSee('Sample without language', false)->assertSee('Description without language', false);

    expect(app(IgsnCoreXmlValidator::class)->validate($response->getContent())->errors)->toBe([]);
});

test('rejects unpublished pages and regular resources', function (bool $physical, bool $published) {
    $page = coreLandingPage($physical, $published);
    $this->get($page->getPublicPath().'/metadata/igsn-core-2.0.xml')->assertNotFound();
})->with([[true, false], [false, true], [false, false]]);

test('downloads numeric location labels as strings without losing or duplicating them', function (string $label) {
    $page = coreLandingPage();
    GeoLocation::factory()->create(['resource_id' => $page->resource_id, 'place' => $label, 'city' => $label]);

    $response = $this->get($page->getPublicPath().'/metadata/igsn-core-2.0.xml')->assertOk();
    expect(app(IgsnCoreXmlValidator::class)->validate($response->getContent())->errors)->toBe([]);
    $document = new DOMDocument;
    expect($document->loadXML($response->getContent()))->toBeTrue();
    $xpath = new DOMXPath($document);
    $xpath->registerNamespace('i', 'urn:FAIR-AIMS:Core:1');
    expect($xpath->evaluate('string(//i:geoLocationPlace)'))->toBe($label)
        ->and($xpath->evaluate('count(//i:geoLocationPlace)'))->toBe(1.0);
})->with(['123', '0', '-123', '00123']);

test('keeps published tombstone metadata available', function () {
    $page = coreLandingPage();
    $page->update(['is_tombstone' => true]);
    $this->get($page->getPublicPath().'/metadata/igsn-core-2.0.xml')->assertOk();
});

test('returns a safe metadata error without an attachment for incomplete samples', function () {
    $page = coreLandingPage();
    $page->resource->creators()->delete();
    $this->get($page->getPublicPath().'/metadata/igsn-core-2.0.xml')
        ->assertUnprocessable()->assertHeaderMissing('Content-Disposition')
        ->assertJsonPath('code', 'igsn_core_metadata_incomplete')->assertJsonPath('fields.0', 'creators');
});

test('fails closed if the stylesheet or schema is unavailable or produces invalid XML', function (string $failure) {
    $page = coreLandingPage();
    if ($failure === 'schema') {
        config(['igsn_core.directory' => storage_path('does-not-exist')]);
    } elseif ($failure === 'stylesheet') {
        config(['igsn_core.stylesheet' => storage_path('does-not-exist.xsl')]);
    } else {
        $path = tempnam(sys_get_temp_dir(), 'core-xsl');
        file_put_contents($path, '<xsl:stylesheet version="1.0" xmlns:xsl="http://www.w3.org/1999/XSL/Transform"><xsl:template match="/"><invalid/></xsl:template></xsl:stylesheet>');
        config(['igsn_core.stylesheet' => $path]);
    }
    try {
        $this->get($page->getPublicPath().'/metadata/igsn-core-2.0.xml')->assertStatus(500)
            ->assertHeaderMissing('Content-Disposition')->assertJsonPath('code', 'igsn_core_export_failed')
            ->assertDontSee(storage_path(), false);
    } finally {
        if (isset($path)) {
            unlink($path);
        }
    }
})->with(['schema', 'stylesheet', 'invalid XML']);

test('exposes Core in metadata discovery only for published physical objects', function (bool $physical, bool $published) {
    $page = coreLandingPage($physical, $published);
    $service = app(LandingPageMetadataLinkService::class);
    $links = $service->for($page->resource, $page);
    $core = array_values(array_filter($links, fn (array $link) => $link['format'] === 'igsn-core-2.0'));
    expect($core)->toHaveCount($physical && $published ? 1 : 0);
    if ($core !== []) {
        expect($core[0]['url'])->toBe(url($page->getPublicPath().'/metadata/igsn-core-2.0.xml'))
            ->and($core[0]['profile'])->toBe(app(IgsnCoreSchemaResolver::class)->url());
        $signposting = $service->signpostingFor($page->resource, $page, $links, ['mimeType' => null, 'contentLinks' => [], 'repositories' => []], null);
        expect(array_column($signposting, 'href'))->toContain($core[0]['url']);
    }
})->with([[true, true], [true, false], [false, true]]);

test('serves every original schema artifact byte for byte and supports immutable HEAD and ETag responses', function () {
    $schemas = app(IgsnCoreSchemaResolver::class);
    foreach ($schemas->files() as $file) {
        $response = $this->get($schemas->url($file['path']))->assertOk();
        ob_start();
        $response->baseResponse->sendContent();
        $bytes = ob_get_clean();
        expect(strlen($bytes))->toBe($file['bytes'])
            ->and(hash('sha256', $bytes))->toBe($file['sha256'])
            ->and(sha1('blob '.strlen($bytes)."\0".$bytes))->toBe($file['git_blob']);
    }
    $head = $this->head($schemas->url())->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($head->headers->get('Cache-Control'))->toContain('public', 'immutable', 'max-age=31536000');
    $this->get($schemas->url(), ['If-None-Match' => $head->headers->get('ETag')])->assertStatus(304);
    foreach (['manifest.json', 'catalog.xml', 'validation.xsd', 'README.md'] as $path) {
        $this->get($schemas->url($path))->assertOk();
    }
});

test('does not expose unlisted files or other snapshots', function (string $path) {
    $this->get(app(IgsnCoreSchemaResolver::class)->url($path))->assertNotFound();
})->with(['.env', 'upstream/IGSN_Core_by_GFZ_DataServices.xsd', 'DataCite4.7/include/../../../../.env', 'IGSN_Geo_by_GFZ_DataServices.xsd', 'igsn/1.3/metadata.xsd']);

test('unknown schema snapshots return 404', function () {
    $this->get('/schemas/igsn/2.0/core/'.str_repeat('0', 40).'/IGSN_Core_by_GFZ_DataServices.xsd')->assertNotFound();
});
