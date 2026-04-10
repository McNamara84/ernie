<?php

declare(strict_types=1);

use App\Models\Description;
use App\Models\DescriptionType;
use App\Models\LandingPage;
use App\Models\OaiPmhDeletedRecord;
use App\Models\OaiPmhResumptionToken;
use App\Models\Resource;
use App\Models\Title;
use App\Models\TitleType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

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

/**
 * Build OAI identifier from a DOI using the configured prefix.
 */
function oaiId(string $doi): string
{
    return config('oaipmh.identifier_prefix') . ':' . $doi;
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
    $identifier = oaiId('10.5880/test.2024.001');

    $response = $this->get("/oai-pmh?verb=ListMetadataFormats&identifier={$identifier}");

    $response->assertStatus(200);
    $xml = simplexml_load_string($response->getContent());

    expect(count($xml->ListMetadataFormats->metadataFormat))->toBe(2);
});

test('ListMetadataFormats with unknown identifier returns idDoesNotExist', function () {
    $response = $this->get('/oai-pmh?verb=ListMetadataFormats&identifier=' . oaiId('10.9999/nonexistent'));

    $response->assertStatus(200);
    $xml = simplexml_load_string($response->getContent());

    expect((string) $xml->error['code'])->toBe('idDoesNotExist');
});

// ===================================================================
// Verb: ListSets
// ===================================================================

test('ListSets returns resource type and year sets', function () {
    $resource = createOaiPmhResource(['publication_year' => 2024]);

    $response = $this->get('/oai-pmh?verb=ListSets');

    $response->assertStatus(200);
    $xml = simplexml_load_string($response->getContent());

    $specs = [];
    foreach ($xml->ListSets->set as $set) {
        $specs[] = (string) $set->setSpec;
    }

    expect($specs)->toContain('resourcetype:' . $resource->resourceType->slug)
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

    expect($content)->toContain(oaiId('10.5880/test.2024.001'))
        ->and($content)->toContain('Test Dataset Title')
        ->and($content)->toContain('dc:title');
});

test('ListRecords with oai_datacite returns DataCite metadata', function () {
    createOaiPmhResource(['doi' => '10.5880/test.2024.002']);

    $response = $this->get('/oai-pmh?verb=ListRecords&metadataPrefix=oai_datacite');

    $response->assertStatus(200);
    $content = $response->getContent();

    expect($content)->toContain(oaiId('10.5880/test.2024.002'));
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
        'oai_identifier' => oaiId('10.5880/deleted.2024.001'),
        'doi' => '10.5880/deleted.2024.001',
        'datestamp' => now(),
        'sets' => ['resourcetype:dataset'],
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

    expect($content)->toContain(oaiId('10.5880/hdr.2024.001'))
        ->and($content)->not->toContain('dc:title');
});

// ===================================================================
// Verb: GetRecord
// ===================================================================

test('GetRecord returns a single record', function () {
    createOaiPmhResource(['doi' => '10.5880/single.2024.001']);

    $response = $this->get('/oai-pmh?verb=GetRecord&identifier=' . oaiId('10.5880/single.2024.001') . '&metadataPrefix=oai_dc');

    $response->assertStatus(200);
    $content = $response->getContent();

    expect($content)->toContain(oaiId('10.5880/single.2024.001'))
        ->and($content)->toContain('dc:title');
});

test('GetRecord with nonexistent identifier returns idDoesNotExist', function () {
    $response = $this->get('/oai-pmh?verb=GetRecord&identifier=' . oaiId('10.9999/nonexistent') . '&metadataPrefix=oai_dc');

    $xml = simplexml_load_string($response->getContent());

    expect((string) $xml->error['code'])->toBe('idDoesNotExist');
});

test('GetRecord for deleted record returns status deleted', function () {
    OaiPmhDeletedRecord::create([
        'oai_identifier' => oaiId('10.5880/deleted.001'),
        'doi' => '10.5880/deleted.001',
        'datestamp' => now(),
        'sets' => ['resourcetype:dataset'],
    ]);

    $response = $this->get('/oai-pmh?verb=GetRecord&identifier=' . oaiId('10.5880/deleted.001') . '&metadataPrefix=oai_dc');

    $content = $response->getContent();

    expect($content)->toContain('status="deleted"');
});

test('GetRecord without identifier returns badArgument', function () {
    $response = $this->get('/oai-pmh?verb=GetRecord&metadataPrefix=oai_dc');

    $xml = simplexml_load_string($response->getContent());

    expect((string) $xml->error['code'])->toBe('badArgument');
});

test('GetRecord without metadataPrefix returns badArgument', function () {
    $response = $this->get('/oai-pmh?verb=GetRecord&identifier=' . oaiId('10.5880/test') . '');

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

    expect((string) $xml->error['code'])->toBe('badResumptionToken')
        ->and((string) $xml->request['resumptionToken'])->toBe('nonexistent_token');
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

// ===================================================================
// CSRF Exclusion (POST without CSRF token)
// ===================================================================

test('POST requests work without CSRF token', function () {
    // Simulate an external harvester (no session/CSRF)
    $response = $this->post('/oai-pmh', ['verb' => 'Identify']);

    $response->assertStatus(200);
    $xml = simplexml_load_string($response->getContent());

    expect((string) $xml->Identify->repositoryName)->not->toBeEmpty();
});

// ===================================================================
// Resumption Token Verb Mismatch
// ===================================================================

test('resumption token issued for different verb returns badResumptionToken', function () {
    $token = OaiPmhResumptionToken::create([
        'token' => 'verb-mismatch-token',
        'verb' => 'ListIdentifiers',
        'metadata_prefix' => 'oai_dc',
        'cursor' => 100,
        'complete_list_size' => 200,
        'expires_at' => now()->addDay(),
    ]);

    // Try using the ListIdentifiers token with ListRecords
    $response = $this->get('/oai-pmh?verb=ListRecords&resumptionToken=verb-mismatch-token');

    $xml = simplexml_load_string($response->getContent());

    expect((string) $xml->error['code'])->toBe('badResumptionToken')
        ->and((string) $xml->request['resumptionToken'])->toBe('verb-mismatch-token');
});

// ===================================================================
// Idempotent Resumption Token Reuse
// ===================================================================

test('resumption token can be reused for retry on transient failure', function () {
    // Create enough resources to trigger pagination
    for ($i = 1; $i <= 3; $i++) {
        createOaiPmhResource(['doi' => "10.5880/page.2024.{$i}"]);
    }

    // Create a resumption token pointing to cursor=1
    $token = OaiPmhResumptionToken::create([
        'token' => 'retry-token',
        'verb' => 'ListRecords',
        'metadata_prefix' => 'oai_dc',
        'cursor' => 1,
        'complete_list_size' => 3,
        'expires_at' => now()->addDay(),
    ]);

    // First request
    $response1 = $this->get('/oai-pmh?verb=ListRecords&resumptionToken=retry-token');
    $response1->assertStatus(200);

    // Token should still exist (idempotent reuse)
    expect(OaiPmhResumptionToken::where('token', 'retry-token')->exists())->toBeTrue();

    // Second request (retry) should also succeed
    $response2 = $this->get('/oai-pmh?verb=ListRecords&resumptionToken=retry-token');
    $response2->assertStatus(200);
});

// ===================================================================
// Request Element Echoes resumptionToken
// ===================================================================

test('request element echoes resumptionToken attribute when using token', function () {
    createOaiPmhResource(['doi' => '10.5880/echo.2024.001']);

    $token = OaiPmhResumptionToken::create([
        'token' => 'echo-test-token',
        'verb' => 'ListRecords',
        'metadata_prefix' => 'oai_dc',
        'cursor' => 0,
        'complete_list_size' => 1,
        'expires_at' => now()->addDay(),
    ]);

    $response = $this->get('/oai-pmh?verb=ListRecords&resumptionToken=echo-test-token');

    $xml = simplexml_load_string($response->getContent());

    expect((string) $xml->request['resumptionToken'])->toBe('echo-test-token');
});

// ===================================================================
// Stricter Set Spec Validation
// ===================================================================

test('empty set value after prefix returns badArgument', function () {
    $response = $this->get('/oai-pmh?verb=ListRecords&metadataPrefix=oai_dc&set=year:');

    $xml = simplexml_load_string($response->getContent());

    expect((string) $xml->error['code'])->toBe('badArgument');
});

test('non-numeric year set value returns badArgument', function () {
    $response = $this->get('/oai-pmh?verb=ListRecords&metadataPrefix=oai_dc&set=year:abcd');

    $xml = simplexml_load_string($response->getContent());

    expect((string) $xml->error['code'])->toBe('badArgument');
});

test('five-digit year set value returns badArgument', function () {
    $response = $this->get('/oai-pmh?verb=ListRecords&metadataPrefix=oai_dc&set=year:20241');

    $xml = simplexml_load_string($response->getContent());

    expect((string) $xml->error['code'])->toBe('badArgument');
});

test('empty resourcetype value returns badArgument', function () {
    $response = $this->get('/oai-pmh?verb=ListRecords&metadataPrefix=oai_dc&set=resourcetype:');

    $xml = simplexml_load_string($response->getContent());

    expect((string) $xml->error['code'])->toBe('badArgument');
});

// ===================================================================
// Granularity Mismatch
// ===================================================================

test('mixed date granularity between from and until returns badArgument', function () {
    $response = $this->get('/oai-pmh?verb=ListRecords&metadataPrefix=oai_dc&from=2024-01-01&until=2024-12-31T23:59:59Z');

    $xml = simplexml_load_string($response->getContent());

    expect((string) $xml->error['code'])->toBe('badArgument')
        ->and((string) $xml->error)->toContain('granularity');
});

// ===================================================================
// Dublin Core Description Mapping
// ===================================================================

test('ListRecords oai_dc includes dc:description from resource', function () {
    $resource = createOaiPmhResource(['doi' => '10.5880/desc.2024.001']);

    $descType = DescriptionType::firstOrCreate(
        ['slug' => 'Abstract'],
        ['name' => 'Abstract', 'slug' => 'Abstract', 'is_active' => true],
    );

    Description::create([
        'resource_id' => $resource->id,
        'value' => 'This is a test abstract for DC mapping',
        'description_type_id' => $descType->id,
    ]);

    $response = $this->get('/oai-pmh?verb=ListRecords&metadataPrefix=oai_dc');

    $content = $response->getContent();

    expect($content)->toContain('dc:description')
        ->and($content)->toContain('This is a test abstract for DC mapping');
});

// ===================================================================
// Identify repositoryIdentifier from config
// ===================================================================

test('Identify response derives repositoryIdentifier from config', function () {
    $response = $this->get('/oai-pmh?verb=Identify');

    $content = $response->getContent();

    // repositoryIdentifier should match the part after "oai:" in identifier_prefix
    $expectedId = str_replace('oai:', '', (string) config('oaipmh.identifier_prefix'));

    expect($content)->toContain("<repositoryIdentifier>{$expectedId}</repositoryIdentifier>");
});

// ===================================================================
// Set Spec with Spaces (OAI-PMH grammar violation)
// ===================================================================

test('resourcetype set spec with spaces returns badArgument', function () {
    $response = $this->get('/oai-pmh?verb=ListRecords&metadataPrefix=oai_dc&set=resourcetype:Physical Object');

    $xml = simplexml_load_string($response->getContent());

    expect((string) $xml->error['code'])->toBe('badArgument');
});

// ===================================================================
// Effective Datestamp (GREATEST of updated_at, published_at)
// ===================================================================

test('datestamp reflects published_at when it is newer than updated_at', function () {
    $resource = Resource::factory()->create(['doi' => '10.5880/datestamp.2024.001']);

    // Set updated_at to an older date without triggering model events
    Resource::withoutTimestamps(fn () => Resource::where('id', $resource->id)->update([
        'updated_at' => '2024-01-01 00:00:00',
    ]));

    // Set published_at to a newer date
    $publishedAt = Carbon::parse('2024-06-15 12:00:00');
    LandingPage::factory()->create([
        'resource_id' => $resource->id,
        'is_published' => true,
        'published_at' => $publishedAt,
    ]);

    $response = $this->get('/oai-pmh?verb=GetRecord&identifier=' . oaiId('10.5880/datestamp.2024.001') . '&metadataPrefix=oai_dc');

    $content = $response->getContent();

    expect($content)->toContain('2024-06-15T12:00:00Z');
});

test('datestamp reflects updated_at when it is newer than published_at', function () {
    $updatedAt = Carbon::parse('2024-09-01 10:00:00');
    $resource = Resource::factory()->create([
        'doi' => '10.5880/datestamp.2024.002',
        'updated_at' => $updatedAt,
    ]);

    LandingPage::factory()->create([
        'resource_id' => $resource->id,
        'is_published' => true,
        'published_at' => Carbon::parse('2024-01-01 00:00:00'),
    ]);

    $response = $this->get('/oai-pmh?verb=GetRecord&identifier=' . oaiId('10.5880/datestamp.2024.002') . '&metadataPrefix=oai_dc');

    $content = $response->getContent();

    expect($content)->toContain('2024-09-01T10:00:00Z');
});
