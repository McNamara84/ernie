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
use Illuminate\Support\Facades\File;

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
        config(['igsn_core.revisions.preview-1' => storage_path('does-not-exist')]);
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

test('serves portable schemas with revalidated aliases and immutable revision URLs', function (?string $revision) {
    $schemas = app(IgsnCoreSchemaResolver::class);
    foreach ($schemas->files() as $file) {
        $response = $this->get($schemas->url($file['path'], $revision))->assertOk()
            ->assertHeader('Content-Type', 'application/xml; charset=UTF-8')->assertHeaderMissing('Set-Cookie');
        ob_start();
        $response->baseResponse->sendContent();
        $bytes = ob_get_clean();
        expect(strlen($bytes))->toBe($file['bytes'])
            ->and(hash('sha256', $bytes))->toBe($file['sha256']);
    }
    $url = $schemas->url(revision: $revision);
    $head = $this->head($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeaderMissing('Set-Cookie');
    if ($revision === null) {
        expect($head->headers->get('Cache-Control'))->toContain('public', 'no-cache')->not->toContain('immutable');
    } else {
        expect($head->headers->get('Cache-Control'))->toContain('public', 'immutable', 'max-age=31536000');
    }
    $this->get($url, ['If-None-Match' => $head->headers->get('ETag')])->assertStatus(304);
    $this->head($url, ['If-None-Match' => $head->headers->get('ETag')])->assertStatus(304);
    $this->get($url, ['If-None-Match' => '"outdated"'])->assertOk();
    foreach (['manifest.json', 'README.md'] as $path) {
        $this->get($schemas->url($path, $revision))->assertOk();
    }
})->with([null, 'preview-1']);

test('validates exported XML using only downloaded schemas and the standard XML loader', function (?string $revision) {
    $page = coreLandingPage();
    $xml = $this->get($page->getPublicPath().'/metadata/igsn-core-2.0.xml')->assertOk()->getContent();
    $export = new DOMDocument;
    expect($export->loadXML($xml))->toBeTrue();
    expect($export->documentElement->getAttributeNS('http://www.w3.org/2001/XMLSchema-instance', 'schemaLocation'))
        ->toBe('urn:FAIR-AIMS:Core:1 '.url('/schemas/igsn/2.0/core.xsd'));

    $base = '/schemas/igsn/2.0/'.($revision === null ? '' : $revision.'/');
    $directory = sys_get_temp_dir().'/core-download-'.bin2hex(random_bytes(8));
    $pending = ['core.xsd'];
    $downloaded = [];
    $previousLoader = libxml_get_external_entity_loader();
    $previousErrors = libxml_use_internal_errors(true);
    libxml_clear_errors();
    libxml_set_external_entity_loader(null);
    try {
        // Follow only imports/includes from the public entry point, without the
        // manifest, ERNIE's resolver, a catalog, or a wrapper XSD.
        while ($pending !== []) {
            $path = array_shift($pending);
            if (isset($downloaded[$path])) {
                continue;
            }
            $response = $this->get($base.$path)->assertOk();
            ob_start();
            $response->baseResponse->sendContent();
            $bytes = ob_get_clean();
            File::ensureDirectoryExists(dirname($directory.'/'.$path));
            file_put_contents($directory.'/'.$path, $bytes);
            $downloaded[$path] = true;
            $schema = new DOMDocument;
            expect($schema->loadXML($bytes, LIBXML_NONET))->toBeTrue();
            $xpath = new DOMXPath($schema);
            $xpath->registerNamespace('xs', 'http://www.w3.org/2001/XMLSchema');
            foreach ($xpath->query('/xs:schema/xs:import/@schemaLocation | /xs:schema/xs:include/@schemaLocation') as $location) {
                $relative = $location->nodeValue;
                expect($relative)->not->toContain(':', '\\', '..')->not->toStartWith('/');
                $pending[] = (dirname($path) === '.' ? '' : dirname($path).'/').$relative;
            }
        }
        expect($downloaded)->toHaveCount(13);
        foreach ([$xml, file_get_contents(base_path('tests/pest/Fixtures/igsn-core/full.xml'))] as $documentXml) {
            $document = new DOMDocument;
            expect($document->loadXML($documentXml, LIBXML_NONET))->toBeTrue();
            expect($document->schemaValidate($directory.'/core.xsd', LIBXML_NONET))->toBeTrue();
            expect(libxml_get_errors())->toBe([]);
        }
        $invalid = new DOMDocument;
        $invalid->loadXML(str_replace(' igsn:', ' ', $xml), LIBXML_NONET);
        expect($invalid->schemaValidate($directory.'/core.xsd', LIBXML_NONET))->toBeFalse();
    } finally {
        libxml_set_external_entity_loader($previousLoader);
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrors);
        File::deleteDirectory($directory);
    }
})->with([null, 'preview-1']);

test('updates the short alias without changing existing revision URLs or reusing stale ETags', function () {
    $alias = '/schemas/igsn/2.0/core.xsd';
    $fixed = '/schemas/igsn/2.0/preview-1/core.xsd';
    $etag = $this->head($alias)->assertOk()->headers->get('ETag');
    $directory = sys_get_temp_dir().'/core-revision-'.bin2hex(random_bytes(8));
    File::copyDirectory(app(IgsnCoreSchemaResolver::class)->directory(), $directory);
    try {
        $manifest = json_decode(file_get_contents($directory.'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        $manifest['revision'] = 'preview-2';
        $bytes = file_get_contents($directory.'/core.xsd')."\n<!-- Next revision fixture. -->\n";
        file_put_contents($directory.'/core.xsd', $bytes);
        foreach ($manifest['files'] as &$file) {
            if ($file['path'] === 'core.xsd') {
                $file['sha256'] = hash('sha256', $bytes);
                $file['bytes'] = strlen($bytes);
            }
        }
        unset($file);
        file_put_contents($directory.'/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        config(['igsn_core.revision' => 'preview-2', 'igsn_core.revisions.preview-2' => $directory]);
        $updated = $this->get($alias, ['If-None-Match' => $etag])->assertOk();
        expect($updated->headers->get('ETag'))->not->toBe($etag);
        $this->get($fixed, ['If-None-Match' => $etag])->assertStatus(304);
        $this->head('/schemas/igsn/2.0/preview-2/core.xsd')->assertOk()
            ->assertHeader('ETag', $updated->headers->get('ETag'));
    } finally {
        File::deleteDirectory($directory);
    }
});

test('does not expose unlisted files or retired validation helpers', function (string $path) {
    foreach ([null, 'preview-1'] as $revision) {
        $this->get(app(IgsnCoreSchemaResolver::class)->url($path, $revision))->assertNotFound();
    }
})->with(['.env', 'upstream/IGSN_Core_by_GFZ_DataServices.xsd', 'DataCite4.7/include/../../../../.env', 'IGSN_Geo_by_GFZ_DataServices.xsd', 'igsn/1.3/metadata.xsd', 'IGSN_Core_by_GFZ_DataServices.xsd', 'validation.xsd', 'catalog.xml']);

test('removed snapshot URLs and unknown revisions return 404 without redirects', function (string $path) {
    $this->get('/schemas/igsn/2.0/'.$path)->assertNotFound()->assertHeaderMissing('Location');
    $this->head('/schemas/igsn/2.0/'.$path)->assertNotFound()->assertHeaderMissing('Location');
})->with([
    'core/321df7287f8540960b99aeb55113cbf22274380c/IGSN_Core_by_GFZ_DataServices.xsd',
    'core/321df7287f8540960b99aeb55113cbf22274380c/DataCite4.7/metadata.xsd',
    'core/321df7287f8540960b99aeb55113cbf22274380c/validation.xsd',
    'preview-999/core.xsd',
    'preview-999/DataCite4.7/metadata.xsd',
]);
