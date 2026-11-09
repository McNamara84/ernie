<?php

declare(strict_types=1);

use App\Models\Description;
use App\Models\Format;
use App\Models\IdentifierType;
use App\Models\LandingPage;
use App\Models\RelationType;
use App\Models\Resource;
use App\Models\Title;
use App\Services\DcatAp\DcatApRdfXmlExporter;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\DcatApExampleMetadata;
use Tests\Fixtures\SchemaOrgExampleMetadata;

it('produces live-fixture and synthetic RDF artifacts for independent offline validation', function () {
    config(['dcatap.enabled' => true]);
    Http::preventStrayRequests();
    $report = [];
    $resources = [];
    foreach (['intermagnet', 'energy', 'collection'] as $name) {
        $resources[$name] = DcatApExampleMetadata::load($name);
    }
    $resource = Resource::factory()->create(['doi' => '10.5880/dcat.synthetic']);
    Title::factory()->create(['resource_id' => $resource->id, 'value' => 'Synthetic export fixture']);
    SchemaOrgExampleMetadata::addTo($resource);
    Description::factory()->methods()->create(['resource_id' => $resource->id, 'value' => 'Reproducible method.']);
    $format = Format::create(['resource_id' => $resource->id, 'value' => 'text/csv']);
    $size = $resource->sizes()->create(['numeric_value' => 2048, 'unit' => 'B']);
    LandingPage::factory()->published()->create(['resource_id' => $resource->id, 'doi_prefix' => $resource->doi,
        'ftp_url' => 'https://example.org/data.csv', 'ftp_format_id' => $format->id, 'ftp_size_id' => $size->id]);
    $resources['synthetic'] = $resource->refresh();
    $doiType = IdentifierType::firstOrCreate(['slug' => 'DOI'], ['name' => 'DOI']);
    $derived = RelationType::firstOrCreate(['slug' => 'IsDerivedFrom'], ['name' => 'IsDerivedFrom']);
    $resource->relatedIdentifiers()->create(['identifier' => '10.5880/external.reference', 'identifier_type_id' => $doiType->id, 'relation_type_id' => $derived->id]);
    $resources['synthetic'] = $resource->refresh();
    foreach (['ambiguous-rights', 'tombstone'] as $name) {
        $edge = Resource::factory()->create(['doi' => '10.5880/dcat.'.$name]);
        Title::factory()->create(['resource_id' => $edge->id, 'value' => $name]);
        Description::factory()->abstract()->create(['resource_id' => $edge->id, 'value' => 'Metadata retained for harvesting.']);
        $edge->resourceRights()->create(['rights_text' => 'License A', 'rights_uri' => 'https://example.org/license-a']);
        $edge->resourceRights()->create(['rights_text' => 'License B', 'rights_uri' => 'https://example.org/license-b']);
        $edge->geoLocations()->create(['point_longitude' => 0, 'point_latitude' => 0, 'position' => 0]);
        $edgeFormat = Format::create(['resource_id' => $edge->id, 'value' => 'application/x-netcdf']);
        LandingPage::factory()->published()->create(['resource_id' => $edge->id, 'doi_prefix' => $edge->doi,
            'ftp_url' => 'https://example.org/data.nc', 'ftp_format_id' => $edgeFormat->id,
            'is_tombstone' => $name === 'tombstone', 'tombstone_statement' => $name === 'tombstone' ? 'The data were withdrawn.' : null]);
        $resources[$name] = $edge->refresh();
    }
    foreach ($resources as $name => $resource) {
        $rdf = app(DcatApRdfXmlExporter::class)->export($resource);
        $download = $this->get($resource->landingPage->getPublicPath().'/metadata/dcat-ap.rdf')->assertOk()->getContent();
        expect($download)->toBe($rdf);
        $oai = $this->get('/oai-pmh?'.http_build_query(['verb' => 'GetRecord', 'metadataPrefix' => 'dcat_ap', 'identifier' => config('oaipmh.identifier_prefix').':'.$resource->doi]))->assertOk()->getContent();
        $report[] = ['name' => $name, 'rdf' => $rdf, 'oai' => $oai];
    }
    $token = getenv('ERNIE_DCAT_REPORT_TOKEN');
    if ($token !== false && $token !== '') {
        if (preg_match('/\A[a-f0-9]{32}\z/', $token) !== 1) {
            throw new RuntimeException('Invalid DCAT-AP report token');
        }
        file_put_contents(storage_path('framework/testing/dcat-ap-'.$token.'.json'), json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
});
