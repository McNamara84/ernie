<?php

declare(strict_types=1);

use App\Enums\DcatApFormat;
use App\Models\Description;
use App\Models\Format;
use App\Models\IdentifierType;
use App\Models\LandingPage;
use App\Models\RelationType;
use App\Models\Resource;
use App\Models\ResourceType;
use App\Models\Title;
use App\Models\TitleType;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\DcatApExampleMetadata;
use Tests\Fixtures\SchemaOrgExampleMetadata;

it('produces live-fixture and synthetic RDF artifacts for independent offline validation', function () {
    config(['dcatap.enabled' => true, 'dcatap.epos.enabled' => true, 'oaipmh.page_size' => 100]);
    Storage::fake('local');
    Storage::disk('local')->put('gemet-thesaurus.json', json_encode([[
        'id' => 'http://www.eionet.europa.eu/gemet/concept/42', 'text' => 'Observation',
        'description' => 'A test definition from a local vocabulary snapshot.',
    ]], JSON_THROW_ON_ERROR));
    Http::preventStrayRequests();
    $report = [];
    $resources = [];
    foreach (['intermagnet', 'energy', 'collection'] as $name) {
        $resources[$name] = DcatApExampleMetadata::load($name);
    }
    $model = ResourceType::firstOrCreate(['slug' => 'model'], ['name' => 'Model']);
    $resource = Resource::factory()->create(['doi' => '10.5880/dcat.synthetic', 'resource_type_id' => $model->id]);
    Title::factory()->create(['resource_id' => $resource->id, 'value' => 'Synthetic export fixture']);
    $translated = TitleType::firstOrCreate(['slug' => 'TranslatedTitle'], ['name' => 'Translated Title']);
    Title::factory()->create(['resource_id' => $resource->id, 'value' => 'Übersetzter Titel', 'title_type_id' => $translated->id, 'language' => 'de']);
    SchemaOrgExampleMetadata::addTo($resource);
    foreach (['Erste Beschreibung.', 'Zweite Beschreibung.'] as $abstract) {
        Description::factory()->abstract()->create(['resource_id' => $resource->id, 'value' => $abstract, 'language' => 'de']);
    }
    Description::factory()->methods()->create(['resource_id' => $resource->id, 'value' => 'Reproducible method.']);
    $format = Format::create(['resource_id' => $resource->id, 'value' => 'text/csv']);
    $size = $resource->sizes()->create(['numeric_value' => 2048, 'unit' => 'B']);
    LandingPage::factory()->published()->create(['resource_id' => $resource->id, 'doi_prefix' => $resource->doi,
        'ftp_url' => 'https://example.org/data.csv', 'ftp_format_id' => $format->id, 'ftp_size_id' => $size->id]);
    $resource->subjects()->create(['value' => 'Observation', 'subject_scheme' => 'GEMET', 'value_uri' => 'http://www.eionet.europa.eu/gemet/concept/42']);
    $resource->alternateIdentifiers()->create(['type' => 'Local', 'value' => 'OBS-42']);
    $resource->instruments()->create(['instrument_pid' => '10.5880/example.instrument', 'instrument_pid_type' => 'DOI', 'instrument_name' => 'Fluxgate']);
    $doiType = IdentifierType::firstOrCreate(['slug' => 'DOI'], ['name' => 'DOI']);
    $derived = RelationType::firstOrCreate(['slug' => 'IsDerivedFrom'], ['name' => 'IsDerivedFrom']);
    $resource->relatedIdentifiers()->create(['identifier' => '10.5880/external.reference', 'identifier_type_id' => $doiType->id, 'relation_type_id' => $derived->id]);
    $resources['synthetic'] = $resource->refresh();
    foreach (['ambiguous-rights' => 'image', 'tombstone' => 'sound'] as $name => $slug) {
        $type = ResourceType::firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug)]);
        $edge = Resource::factory()->create(['doi' => '10.5880/dcat.'.$name, 'resource_type_id' => $type->id]);
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
    $transect = Resource::factory()->create(['doi' => '10.5880/epos.dateline']);
    Title::factory()->create(['resource_id' => $transect->id, 'value' => 'Dateline transect']);
    Description::factory()->abstract()->create(['resource_id' => $transect->id, 'value' => 'Measurements across the antimeridian.']);
    $transect->geoLocations()->create(['geo_type' => 'line', 'polygon_points' => [
        ['longitude' => 170, 'latitude' => 0], ['longitude' => -170, 'latitude' => 2],
    ]]);
    LandingPage::factory()->published()->create(['resource_id' => $transect->id, 'doi_prefix' => $transect->doi]);
    $resources['epos-dateline'] = $transect->refresh();

    foreach ($resources as $name => $resource) {
        $identifier = config('oaipmh.identifier_prefix').':'.$resource->doi;
        $profiles = $name === 'epos-dateline' ? [DcatApFormat::Epos] : DcatApFormat::cases();
        foreach ($profiles as $format) {
            $rdf = $format->exporter()->export($resource);
            $download = $this->get($resource->landingPage->getPublicPath().'/metadata/'.$format->slug().'.rdf')->assertOk()->getContent();
            expect($download)->toBe($rdf);
            $oai = $this->get('/oai-pmh?'.http_build_query(['verb' => 'GetRecord', 'metadataPrefix' => $format->value, 'identifier' => $identifier]))->assertOk()->getContent();
            $report[] = ['name' => $name, 'profile' => $format->value, 'type' => $resource->resourceType->slug, 'identifier' => $identifier, 'rdf' => $rdf, 'oai' => $oai];
        }
    }
    $list = $this->get('/oai-pmh?verb=ListRecords&metadataPrefix=epos_dcat_ap')->assertOk()->getContent();
    expect(substr_count($list, '<metadata>'))->toBe(count($resources));
    $token = getenv('ERNIE_DCAT_REPORT_TOKEN');
    if ($token !== false && $token !== '') {
        if (preg_match('/\A[a-f0-9]{32}\z/', $token) !== 1) {
            throw new RuntimeException('Invalid DCAT-AP report token');
        }
        file_put_contents(storage_path('framework/testing/dcat-ap-'.$token.'.json'), json_encode(['records' => $report, 'eposList' => $list], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
});
