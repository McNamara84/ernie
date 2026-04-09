<?php

declare(strict_types=1);

use App\Models\LandingPage;
use App\Models\OaiPmhDeletedRecord;
use App\Models\Resource;
use App\Models\Title;
use App\Models\TitleType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Create a published resource with landing page for OAI-PMH testing.
 *
 * @param  array<string, mixed>  $attributes
 */
function createOaiPmhResource(array $attributes = []): Resource
{
    $resource = Resource::factory()->create($attributes);

    LandingPage::factory()->published()->create([
        'resource_id' => $resource->id,
    ]);

    $titleType = TitleType::firstOrCreate(
        ['slug' => 'MainTitle'],
        ['name' => 'Main Title', 'slug' => 'MainTitle', 'is_active' => true],
    );

    Title::create([
        'resource_id' => $resource->id,
        'value' => 'Test Dataset Title',
        'title_type_id' => $titleType->id,
    ]);

    return $resource->refresh();
}

// ===================================================================
// Verb: Identify
// ===================================================================

test('Identify returns valid OAI-PMH response', function () {
    $response = $this->get('/oai-pmh?verb=Identify');

    $response->assertStatus(200)
        ->assertHeader('Content-Type', 'text/xml; charset=utf-8');

    $xml = simplexml_load_string($response->getContent());

    expect($xml->getName())->toBe('OAI-PMH')
        ->and((string) $xml->Identify->repositoryName)->toBe('ERNIE – GFZ Data Publication Repository')
        ->and((string) $xml->Identify->protocolVersion)->toBe('2.0')
        ->and((string) $xml->Identify->adminEmail)->toBe('datapub@gfz.de')
        ->and((string) $xml->Identify->deletedRecord)->toBe('persistent')
        ->and((string) $xml->Identify->granularity)->toBe('YYYY-MM-DDThh:mm:ssZ');
});

test('Identify includes sample identifier when published resources exist', function () {
    $resource = createOaiPmhResource(['doi' => '10.5880/GFZ.1.2.2024.001']);

    $response = $this->get('/oai-pmh?verb=Identify');
    $content = $response->getContent();

    expect($content)->toContain('10.5880/GFZ.1.2.2024.001');
});

// ===================================================================
// Verb: ListMetadataFormats
// ===================================================================

test('ListMetadataFormats returns oai_dc and oai_datacite', function () {
    $response = $this->get('/oai-pmh?verb=ListMetadataFormats');

    $response->assertStatus(200);
    $xml = simplexml_load_string($response->getContent());

    $prefixes = [];
    foreach ($xml->ListMetadataFormats->metadataFormat as $format) {
        $prefixes[] = (string) $format->metadataPrefix;
    }

    expect($prefixes)->toContain('oai_dc')
        ->and($prefixes)->toContain('oai_datacite');
});

test('ListMetadataFormats with valid identifier returns formats', function () {
    $resource = createOaiPmhResource(['doi' => '10.5880/test.2024.001']);
    $identifier = 'oai:ernie.gfz.de:10.5880/test.2024.001';

    $response = $this->get("/oai-pmh?verb=ListMetadataFormats&identifier={$identifier}");

    $response->assertStatus(200);
    $xml = simplexml_load_string($response->getContent());

    expect(count($xml->ListMetadataFormats->metadataFormat))->toBe(2);
});

test('ListMetadataFormats with unknown identifier returns idDoesNotExist', function () {
    $response = $this->get('/oai-pmh?verb=ListMetadataFormats&identifier=oai:ernie.gfz.de:10.9999/nonexistent');

    $response->assertStatus(200);
    $xml = simplexml_load_string($response->getContent());

    expect((string) $xml->error['code'])->toBe('idDoesNotExist');
});

// ===================================================================
// Verb: ListSets
// ===================================================================

test('ListSets returns resource type and year sets', function () {
    createOaiPmhResource(['publication_year' => 2024]);

    $response = $this->get('/oai-pmh?verb=ListSets');

    $response->assertStatus(200);
    $xml = simplexml_load_string($response->getContent());

    $specs = [];
    foreach ($xml->ListSets->set as $set) {
        $specs[] = (string) $set->setSpec;
    }

    expect($specs)->toContain('resourcetype:Dataset')
        ->and($specs)->toContain('year:2024');
});

test('ListSets with resumptionToken returns badResumptionToken', function () {
    $response = $this->get('/oai-pmh?verb=ListSets&resumptionToken=sometoken');

    $xml = simplexml_load_string($response->getContent());

    expect((string) $xml->error['code'])->toBe('badResumptionToken');
});

// ===================================================================
// Verb: ListRecords
// ===================================================================

test('ListRecords with oai_dc returns Dublin Core metadata', function () {
    createOaiPmhResource(['doi' => '10.5880/test.2024.001', 'publication_year' => 2024]);

    $response = $this->get('/oai-pmh?verb=ListRecords&metadataPrefix=oai_dc');

    $response->assertStatus(200);
    $content = $response->getContent();

    expect($content)->toContain('oai:ernie.gfz.de:10.5880/test.2024.001')
        ->and($content)->toContain('Test Dataset Title')
        ->and($content)->toContain('dc:title');
});

test('ListRecords with oai_datacite returns DataCite metadata', function () {
    createOaiPmhResource(['doi' => '10.5880/test.2024.002']);

    $response = $this->get('/oai-pmh?verb=ListRecords&metadataPrefix=oai_datacite');

    $response->assertStatus(200);
    $content = $response->getContent();

    expect($content)->toContain('oai:ernie.gfz.de:10.5880/test.2024.002');
});

test('ListRecords without metadataPrefix returns badArgument', function () {
    $response = $this->get('/oai-pmh?verb=ListRecords');

    $xml = simplexml_load_string($response->getContent());

    expect((string) $xml->error['code'])->toBe('badArgument');
});

test('ListRecords with invalid metadataPrefix returns cannotDisseminateFormat', function () {
    $response = $this->get('/oai-pmh?verb=ListRecords&metadataPrefix=invalid_format');

    $xml = simplexml_load_string($response->getContent());

    expect((string) $xml->error['code'])->toBe('cannotDisseminateFormat');
});

test('ListRecords with set filter returns only matching records', function () {
    createOaiPmhResource(['doi' => '10.5880/a.2024.001', 'publication_year' => 2024]);
    createOaiPmhResource(['doi' => '10.5880/a.2023.001', 'publication_year' => 2023]);

    $response = $this->get('/oai-pmh?verb=ListRecords&metadataPrefix=oai_dc&set=year:2024');

    $content = $response->getContent();

    expect($content)->toContain('10.5880/a.2024.001')
        ->and($content)->not->toContain('10.5880/a.2023.001');
});

test('ListRecords with date range filtering works', function () {
    $resource = createOaiPmhResource(['doi' => '10.5880/date.2024.001']);

    // Use current time range to ensure the record is within range
    $from = now()->subDay()->format('Y-m-d');
    $until = now()->addDay()->format('Y-m-d');

    $response = $this->get("/oai-pmh?verb=ListRecords&metadataPrefix=oai_dc&from={$from}&until={$until}");

    $content = $response->getContent();

    expect($content)->toContain('10.5880/date.2024.001');
});

test('ListRecords with from > until returns badArgument', function () {
    $response = $this->get('/oai-pmh?verb=ListRecords&metadataPrefix=oai_dc&from=2025-01-01&until=2024-01-01');

    $xml = simplexml_load_string($response->getContent());

    expect((string) $xml->error['code'])->toBe('badArgument');
});

test('ListRecords with no matching records returns noRecordsMatch', function () {
    // No published resources exist
    $response = $this->get('/oai-pmh?verb=ListRecords&metadataPrefix=oai_dc');

    $xml = simplexml_load_string($response->getContent());

    expect((string) $xml->error['code'])->toBe('noRecordsMatch');
});

test('ListRecords includes deleted records on the first page', function () {
    createOaiPmhResource(['doi' => '10.5880/live.2024.001']);

    OaiPmhDeletedRecord::create([
        'oai_identifier' => 'oai:ernie.gfz.de:10.5880/deleted.2024.001',
        'doi' => '10.5880/deleted.2024.001',
        'datestamp' => now(),
        'sets' => ['resourcetype:Dataset'],
    ]);

    $response = $this->get('/oai-pmh?verb=ListRecords&metadataPrefix=oai_dc');

    $content = $response->getContent();

    expect($content)->toContain('status="deleted"')
        ->and($content)->toContain('10.5880/deleted.2024.001')
        ->and($content)->toContain('10.5880/live.2024.001');
});

// ===================================================================
// Verb: ListIdentifiers
// ===================================================================

test('ListIdentifiers returns headers without metadata', function () {
    createOaiPmhResource(['doi' => '10.5880/hdr.2024.001']);

    $response = $this->get('/oai-pmh?verb=ListIdentifiers&metadataPrefix=oai_dc');

    $response->assertStatus(200);
    $content = $response->getContent();

    expect($content)->toContain('oai:ernie.gfz.de:10.5880/hdr.2024.001')
        ->and($content)->not->toContain('dc:title');
});

// ===================================================================
// Verb: GetRecord
// ===================================================================

test('GetRecord returns a single record', function () {
    createOaiPmhResource(['doi' => '10.5880/single.2024.001']);

    $response = $this->get('/oai-pmh?verb=GetRecord&identifier=oai:ernie.gfz.de:10.5880/single.2024.001&metadataPrefix=oai_dc');

    $response->assertStatus(200);
    $content = $response->getContent();

    expect($content)->toContain('oai:ernie.gfz.de:10.5880/single.2024.001')
        ->and($content)->toContain('dc:title');
});

test('GetRecord with nonexistent identifier returns idDoesNotExist', function () {
    $response = $this->get('/oai-pmh?verb=GetRecord&identifier=oai:ernie.gfz.de:10.9999/nonexistent&metadataPrefix=oai_dc');

    $xml = simplexml_load_string($response->getContent());

    expect((string) $xml->error['code'])->toBe('idDoesNotExist');
});

test('GetRecord for deleted record returns status deleted', function () {
    OaiPmhDeletedRecord::create([
        'oai_identifier' => 'oai:ernie.gfz.de:10.5880/deleted.001',
        'doi' => '10.5880/deleted.001',
        'datestamp' => now(),
        'sets' => ['resourcetype:Dataset'],
    ]);

    $response = $this->get('/oai-pmh?verb=GetRecord&identifier=oai:ernie.gfz.de:10.5880/deleted.001&metadataPrefix=oai_dc');

    $content = $response->getContent();

    expect($content)->toContain('status="deleted"');
});

test('GetRecord without identifier returns badArgument', function () {
    $response = $this->get('/oai-pmh?verb=GetRecord&metadataPrefix=oai_dc');

    $xml = simplexml_load_string($response->getContent());

    expect((string) $xml->error['code'])->toBe('badArgument');
});

test('GetRecord without metadataPrefix returns badArgument', function () {
    $response = $this->get('/oai-pmh?verb=GetRecord&identifier=oai:ernie.gfz.de:10.5880/test');

    $xml = simplexml_load_string($response->getContent());

    expect((string) $xml->error['code'])->toBe('badArgument');
});

// ===================================================================
// Error Handling
// ===================================================================

test('request without verb returns badVerb error', function () {
    $response = $this->get('/oai-pmh');

    $response->assertStatus(200);
    $xml = simplexml_load_string($response->getContent());

    expect((string) $xml->error['code'])->toBe('badVerb');
});

test('request with invalid verb returns badVerb error', function () {
    $response = $this->get('/oai-pmh?verb=InvalidVerb');

    $xml = simplexml_load_string($response->getContent());

    expect((string) $xml->error['code'])->toBe('badVerb');
});

test('request with illegal argument returns badArgument error', function () {
    $response = $this->get('/oai-pmh?verb=Identify&illegalParam=value');

    $xml = simplexml_load_string($response->getContent());

    expect((string) $xml->error['code'])->toBe('badArgument');
});

test('resumptionToken cannot be combined with other arguments', function () {
    $response = $this->get('/oai-pmh?verb=ListRecords&resumptionToken=abc&metadataPrefix=oai_dc');

    $xml = simplexml_load_string($response->getContent());

    expect((string) $xml->error['code'])->toBe('badArgument');
});

test('invalid resumptionToken returns badResumptionToken', function () {
    $response = $this->get('/oai-pmh?verb=ListRecords&resumptionToken=nonexistent_token');

    $xml = simplexml_load_string($response->getContent());

    expect((string) $xml->error['code'])->toBe('badResumptionToken');
});

test('invalid set specification returns badArgument', function () {
    $response = $this->get('/oai-pmh?verb=ListRecords&metadataPrefix=oai_dc&set=invalid:set');

    $xml = simplexml_load_string($response->getContent());

    expect((string) $xml->error['code'])->toBe('badArgument');
});

test('invalid date format returns badArgument', function () {
    $response = $this->get('/oai-pmh?verb=ListRecords&metadataPrefix=oai_dc&from=not-a-date');

    $xml = simplexml_load_string($response->getContent());

    expect((string) $xml->error['code'])->toBe('badArgument');
});

// ===================================================================
// POST Support
// ===================================================================

test('OAI-PMH endpoint supports POST requests', function () {
    $response = $this->post('/oai-pmh', ['verb' => 'Identify']);

    $response->assertStatus(200);
    $xml = simplexml_load_string($response->getContent());

    expect((string) $xml->Identify->repositoryName)->toBe('ERNIE – GFZ Data Publication Repository');
});

// ===================================================================
// XML Response Structure
// ===================================================================

test('all responses include OAI-PMH envelope with responseDate and request element', function () {
    $response = $this->get('/oai-pmh?verb=Identify');

    $xml = simplexml_load_string($response->getContent());

    expect((string) $xml->responseDate)->not->toBeEmpty()
        ->and((string) $xml->request)->not->toBeEmpty();
});

// ===================================================================
// Docs Page
// ===================================================================

test('OAI-PMH docs page is accessible', function () {
    $response = $this->get('/oai-pmh/docs');

    $response->assertStatus(200);
});
