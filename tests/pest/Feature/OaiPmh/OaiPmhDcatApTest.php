<?php

declare(strict_types=1);

use App\Models\Description;
use App\Models\Format;
use App\Models\LandingPage;
use App\Models\OaiPmhFormatRecord;
use App\Models\Resource;
use App\Models\ResourceType;
use App\Models\Title;
use App\Services\DcatAp\DcatApRecordService;
use App\Services\DcatAp\RdfDocument;
use Illuminate\Support\Facades\DB;

function oaiDcatResource(array $attributes = []): Resource
{
    $resource = Resource::factory()->create($attributes);
    Title::factory()->create(['resource_id' => $resource->id]);
    Description::factory()->abstract()->create(['resource_id' => $resource->id]);
    LandingPage::factory()->published()->create(['resource_id' => $resource->id, 'doi_prefix' => $resource->doi]);

    return $resource->refresh();
}

function oaiDcatQuery(string $verb, array $arguments = []): string
{
    return '/oai-pmh?'.http_build_query(['verb' => $verb, ...$arguments]);
}

function oaiDcatXPath(string $xml): DOMXPath
{
    $document = new DOMDocument;
    expect($document->loadXML($xml, LIBXML_NONET))->toBeTrue();
    $xpath = new DOMXPath($document);
    $xpath->registerNamespace('oai', 'http://www.openarchives.org/OAI/2.0/');
    foreach (RdfDocument::NS as $prefix => $namespace) {
        $xpath->registerNamespace($prefix, $namespace);
    }

    return $xpath;
}

beforeEach(fn () => config(['dcatap.enabled' => true, 'oaipmh.page_size' => 2]));

it('registers the format independently of ISO and excludes ineligible identifiers', function () {
    $resource = oaiDcatResource();
    config(['iso19115.enabled' => false]);
    $xml = $this->get(oaiDcatQuery('ListMetadataFormats'))->assertOk()->getContent();
    expect($xml)->toContain('<metadataPrefix>dcat_ap</metadataPrefix>', '<metadataNamespace>http://data.europa.eu/r5r/</metadataNamespace>')->not->toContain('<metadataPrefix>iso19115_3</metadataPrefix>');
    $resource->descriptions->first()->delete();
    $this->get(oaiDcatQuery('ListMetadataFormats', ['identifier' => config('oaipmh.identifier_prefix').':'.$resource->doi]))->assertSee('<metadataPrefix>dcat_ap</metadataPrefix>', false);
    $never = Resource::factory()->create();
    LandingPage::factory()->published()->create(['resource_id' => $never->id]);
    $this->get(oaiDcatQuery('GetRecord', ['identifier' => config('oaipmh.identifier_prefix').':'.$never->doi, 'metadataPrefix' => 'dcat_ap']))->assertSee('code="cannotDisseminateFormat"', false);
    config(['dcatap.enabled' => false]);
    $this->get(oaiDcatQuery('ListMetadataFormats'))->assertDontSee('<metadataPrefix>dcat_ap</metadataPrefix>', false);
});

it('embeds the same RDF as the public download', function () {
    $resource = oaiDcatResource();
    $arguments = ['identifier' => config('oaipmh.identifier_prefix').':'.$resource->doi, 'metadataPrefix' => 'dcat_ap'];
    $oai = oaiDcatXPath($this->get(oaiDcatQuery('GetRecord', $arguments))->assertOk()->getContent());
    $public = oaiDcatXPath($this->get($resource->landingPage->getPublicPath().'/metadata/dcat-ap.rdf')->assertOk()->getContent());
    // Namespace-aware DOM comparison; semantic graph isomorphism is also checked by test:dcat-ap.
    $embedded = $oai->query('//oai:metadata/rdf:RDF')->item(0);
    expect($embedded)->not->toBeNull();
    $document = new DOMDocument;
    $document->appendChild($document->importNode($embedded, true));
    expect($oai->evaluate('string(//dct:title)'))->toBe($public->evaluate('string(//dct:title)'))
        ->and($oai->evaluate('count(//rdf:Description)'))->toBe($public->evaluate('count(//rdf:Description)'))
        ->and($document->schemaValidate(public_path('schemas/oai-pmh/dcat-ap/3.0.1/rdf-envelope.xsd')))->toBeTrue();
    $this->post('/oai-pmh', ['verb' => 'GetRecord', ...$arguments])->assertOk()->assertSee('http://www.w3.org/ns/dcat#Dataset', false);
});

it('persists format-only deletion and restores eligibility with an incremental datestamp', function () {
    $resource = oaiDcatResource();
    $record = OaiPmhFormatRecord::where('resource_id', $resource->id)->firstOrFail();
    $initial = $record->datestamp->copy();
    $this->travel(1)->hours();
    $resource->descriptions->first()->delete();
    expect($record->refresh()->status)->toBe('deleted')->and($record->datestamp->greaterThan($initial))->toBeTrue();
    $arguments = ['identifier' => config('oaipmh.identifier_prefix').':'.$resource->doi];
    $this->get(oaiDcatQuery('GetRecord', [...$arguments, 'metadataPrefix' => 'dcat_ap']))->assertSee('status="deleted"', false)->assertDontSee('<metadata>', false);
    $this->get(oaiDcatQuery('GetRecord', [...$arguments, 'metadataPrefix' => 'oai_dc']))->assertDontSee('status="deleted"', false)->assertSee('<metadata>', false);
    $this->travel(1)->hours();
    Description::factory()->abstract()->create(['resource_id' => $resource->id]);
    expect($record->refresh()->status)->toBe('active')->and(OaiPmhFormatRecord::count())->toBe(1);
    $this->get(oaiDcatQuery('ListIdentifiers', ['metadataPrefix' => 'dcat_ap', 'from' => $initial->addHour()->format('Y-m-d\TH:i:s\Z')]))->assertSee($resource->doi);
});

it('reconciles the final transaction state without transient withdrawals or rollback changes', function () {
    $resource = oaiDcatResource();
    $record = OaiPmhFormatRecord::where('resource_id', $resource->id)->firstOrFail();
    $this->travel(1)->hours();
    DB::transaction(function () use ($resource, $record): void {
        $resource->descriptions->first()->delete();
        expect($record->fresh()->status)->toBe('active');
        Description::factory()->abstract()->create(['resource_id' => $resource->id]);
    });
    expect($record->refresh()->status)->toBe('active');
    $before = $record->getAttributes();
    try {
        DB::transaction(function () use ($resource): void {
            $resource->refresh()->descriptions->first()->delete();
            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }
    expect($record->refresh()->getAttributes())->toBe($before);
});

it('invalidates snapshots when eligibility changes and preserves other page identities on normal edits', function () {
    $resources = collect([oaiDcatResource(), oaiDcatResource(), oaiDcatResource(), oaiDcatResource()]);
    $first = oaiDcatXPath($this->get(oaiDcatQuery('ListRecords', ['metadataPrefix' => 'dcat_ap']))->getContent());
    $token = $first->evaluate('string(//oai:resumptionToken)');
    expect($token)->not->toBe('')->and($first->evaluate('string(//oai:resumptionToken/@completeListSize)'))->toBe('4');
    $resources[0]->titles->first()->update(['value' => 'Corrected title']);
    $page = $this->get(oaiDcatQuery('ListRecords', ['resumptionToken' => $token]))->assertOk();
    $retry = $this->get(oaiDcatQuery('ListRecords', ['resumptionToken' => $token]))->assertOk();
    expect(oaiDcatXPath($page->getContent())->evaluate('string(//oai:header/oai:identifier)'))->toBe(oaiDcatXPath($retry->getContent())->evaluate('string(//oai:header/oai:identifier)'));
    $resources[0]->refresh()->descriptions->first()->delete();
    $this->get(oaiDcatQuery('ListRecords', ['resumptionToken' => $token]))->assertSee('code="badResumptionToken"', false);
});

it('retains old DOI and last eligible sets after identity changes, unpublishing and deletion', function () {
    $resource = oaiDcatResource(['doi' => '10.5880/dcat.old', 'publication_year' => 2020]);
    $old = config('oaipmh.identifier_prefix').':'.$resource->doi;
    $resource->update(['doi' => '10.5880/dcat.new']);
    $this->get(oaiDcatQuery('GetRecord', ['metadataPrefix' => 'dcat_ap', 'identifier' => $old]))->assertSee('status="deleted"', false);
    $resource->refresh()->landingPage->update(['is_published' => false]);
    $this->get(oaiDcatQuery('ListIdentifiers', ['metadataPrefix' => 'dcat_ap', 'set' => 'year:2020']))->assertSee('dcat.old')->assertSee('dcat.new');
    $resource->delete();
    expect(OaiPmhFormatRecord::where('status', 'deleted')->count())->toBe(2);
});

it('uses a new availability datestamp on backfill and preserves history while disabled', function () {
    config(['dcatap.enabled' => false]);
    $resource = oaiDcatResource(['updated_at' => '2001-01-01']);
    expect(OaiPmhFormatRecord::count())->toBe(0);
    config(['dcatap.enabled' => true]);
    $this->artisan('dcat-ap:sync')->assertSuccessful();
    $record = OaiPmhFormatRecord::firstOrFail();
    expect($record->datestamp->isSameDay(now()))->toBeTrue();
    config(['dcatap.enabled' => false]);
    $resource->descriptions->first()->delete();
    expect($record->fresh()->status)->toBe('active');
    config(['dcatap.enabled' => true]);
    app(DcatApRecordService::class)->reconcile($resource->id);
    expect($record->fresh()->status)->toBe('deleted');
});

it('updates incremental timestamps for download metadata and shared publisher changes', function () {
    $resource = oaiDcatResource();
    $format = Format::create(['resource_id' => $resource->id, 'value' => 'text/csv']);
    $size = $resource->sizes()->create(['numeric_value' => 1, 'unit' => 'KiB']);
    $resource->landingPage->update(['ftp_url' => 'https://example.org/data.csv', 'ftp_format_id' => $format->id, 'ftp_size_id' => $size->id]);
    $record = OaiPmhFormatRecord::where('resource_id', $resource->id)->firstOrFail();
    foreach ([fn () => $size->update(['numeric_value' => 2]), fn () => $format->update(['value' => 'application/zip']), fn () => $resource->publisher->update(['name' => 'Updated publisher'])] as $mutation) {
        $before = $record->refresh()->datestamp->copy();
        $this->travel(1)->hours();
        $mutation();
        expect($record->refresh()->datestamp->greaterThan($before))->toBeTrue();
    }
    $before = $record->getAttributes();
    $this->travel(1)->hours();
    $resource->landingPage->update(['view_count' => 42]);
    expect($record->refresh()->getAttributes())->toBe($before);
});

it('retains only the last eligible sets when a type becomes unsupported and exposes historical sets', function () {
    $resource = oaiDcatResource(['publication_year' => 1980]);
    $software = ResourceType::firstOrCreate(['slug' => 'software'], ['name' => 'Software']);
    $resource->update(['resource_type_id' => $software->id, 'publication_year' => 2000]);
    $record = OaiPmhFormatRecord::where('resource_id', $resource->id)->firstOrFail();
    expect($record->status)->toBe('deleted')->and($record->sets)->toContain('resourcetype:dataset', 'year:1980')->not->toContain('resourcetype:software');
    $this->get(oaiDcatQuery('ListSets'))->assertSee('<setSpec>year:1980</setSpec>', false);
    $this->get(oaiDcatQuery('ListRecords', ['metadataPrefix' => 'dcat_ap', 'set' => 'resourcetype:software']))->assertSee('code="noRecordsMatch"', false);
});

it('rejects expired, wrong-verb and disabled-format tokens and validates normal date arguments', function () {
    oaiDcatResource();
    oaiDcatResource();
    oaiDcatResource();
    $xml = oaiDcatXPath($this->get(oaiDcatQuery('ListRecords', ['metadataPrefix' => 'dcat_ap']))->getContent());
    $token = $xml->evaluate('string(//oai:resumptionToken)');
    $this->get(oaiDcatQuery('ListIdentifiers', ['resumptionToken' => $token]))->assertSee('code="badResumptionToken"', false);
    $this->get(oaiDcatQuery('ListRecords', ['metadataPrefix' => 'dcat_ap', 'from' => '2026-01-01', 'until' => '2026-01-02T00:00:00Z']))->assertSee('code="badArgument"', false);
    config(['dcatap.enabled' => false]);
    $this->get(oaiDcatQuery('ListRecords', ['resumptionToken' => $token]))->assertSee('code="badResumptionToken"', false);
    config(['dcatap.enabled' => true]);
    $this->travel(2)->days();
    $this->get(oaiDcatQuery('ListRecords', ['resumptionToken' => $token]))->assertSee('code="badResumptionToken"', false);
});

it('does not retire records because of a global configuration failure', function () {
    $resource = oaiDcatResource();
    config(['dcatap.catalog.title' => '']);
    expect(fn () => app(DcatApRecordService::class)->reconcile($resource->id))->toThrow(LogicException::class);
    expect(OaiPmhFormatRecord::where('resource_id', $resource->id)->first()->status)->toBe('active');
});

it('rejects continued date-bounded harvests after a record moves beyond until', function () {
    $this->freezeSecond();
    $resources = collect([oaiDcatResource(), oaiDcatResource(), oaiDcatResource()]);
    $arguments = ['metadataPrefix' => 'dcat_ap', 'from' => now()->format('Y-m-d\TH:i:s\Z'), 'until' => now()->format('Y-m-d\TH:i:s\Z')];
    $first = oaiDcatXPath($this->post('/oai-pmh', ['verb' => 'ListRecords', ...$arguments])->assertOk()->getContent());
    expect($first->evaluate('string(//oai:resumptionToken/@completeListSize)'))->toBe('3');
    $token = $first->evaluate('string(//oai:resumptionToken)');
    $this->travel(1)->hours();
    $resources[2]->titles->first()->update(['value' => 'Updated after the requested interval']);
    $this->get(oaiDcatQuery('ListRecords', ['resumptionToken' => $token]))->assertSee('code="badResumptionToken"', false);
});

it('eager loads metadata without adding a query for every harvested resource', function () {
    for ($index = 0; $index < 10; $index++) {
        oaiDcatResource();
    }
    $counts = [];
    DB::enableQueryLog();
    try {
        foreach ([2, 10] as $size) {
            config(['oaipmh.page_size' => $size]);
            DB::flushQueryLog();
            $response = $this->get(oaiDcatQuery('ListRecords', ['metadataPrefix' => 'dcat_ap']))->assertOk();
            $counts[] = count(DB::getQueryLog());
            expect(oaiDcatXPath($response->getContent())->evaluate('count(//oai:record)'))->toBe((float) $size);
        }
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
    expect($counts[1])->toBeLessThanOrEqual($counts[0] + 3);
});
