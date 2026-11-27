<?php

declare(strict_types=1);

use App\Models\Datacenter;
use App\Models\Description;
use App\Models\DescriptionType;
use App\Models\IdentifierType;
use App\Models\LandingPage;
use App\Models\OaiPmhDeletedRecord;
use App\Models\RelatedIdentifier;
use App\Models\RelationType;
use App\Models\Resource;
use App\Models\ResourceType;
use App\Models\Subject;
use App\Models\Title;
use App\Models\TitleType;
use App\Services\OaiPmh\IcgemSetService;
use App\Services\OaiPmh\OaiPmhResumptionTokenService;
use App\Services\OaiPmh\OaiPmhSetService;
use App\Services\ResourceStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 11, 2)->setTime(8, 0));
});

function createIcgemResource(string $doi = '10.5880/icgem.2026.004', string $type = 'model', bool $published = true): Resource
{
    $resourceType = ResourceType::firstOrCreate(['slug' => $type], ['name' => ucfirst($type), 'is_active' => true]);
    $resource = Resource::factory()->create(['doi' => $doi, 'resource_type_id' => $resourceType->id, 'publication_year' => 2026]);
    LandingPage::factory()->create([
        'resource_id' => $resource->id, 'doi_prefix' => $doi,
        'is_published' => $published, 'published_at' => $published ? now() : null,
    ]);
    Title::create([
        'resource_id' => $resource->id, 'value' => 'ICGEM model metadata',
        'title_type_id' => TitleType::firstOrCreate(['slug' => 'MainTitle'], ['name' => 'Main Title', 'is_active' => true])->id,
    ]);
    Description::create([
        'resource_id' => $resource->id, 'value' => 'A gravity model for selective harvesting.',
        'description_type_id' => DescriptionType::firstOrCreate(['slug' => 'Abstract'], ['name' => 'Abstract', 'is_active' => true])->id,
    ]);

    return $resource->refresh();
}

/** @return list<SimpleXMLElement> */
function icgemHeaders(string $content): array
{
    $xml = simplexml_load_string($content);
    $xml->registerXPathNamespace('oai', 'http://www.openarchives.org/OAI/2.0/');

    return $xml->xpath('//oai:header') ?: [];
}

/** @return list<string> */
function icgemIdentifiers(string $content): array
{
    return array_map(fn (SimpleXMLElement $header): string => (string) $header->identifier, icgemHeaders($content));
}

function icgemId(string $doi): string
{
    return config('oaipmh.identifier_prefix').':'.$doi;
}

test('ICGEM membership agrees in SQL and headers for the start of the own DOI', function (?string $doi, string $type, bool $matches) {
    $resourceType = ResourceType::firstOrCreate(['slug' => $type], ['name' => ucfirst($type)]);
    $resource = Resource::factory()->create(['doi' => $doi, 'resource_type_id' => $resourceType->id]);
    $service = app(OaiPmhSetService::class);

    expect($service->applySetFilter(Resource::query(), 'icgem')->whereKey($resource->id)->exists())->toBe($matches)
        ->and(in_array('icgem', $service->getSetsForResource($resource), true))->toBe($matches);
})->with([
    'first reference' => ['10.5880/icgem.2026.004', 'model', true],
    'dataset' => ['10.5880/ICGEM.2015.1', 'dataset', true],
    'mixed case software DOI' => ['10.5880/iCgEm2026', 'software', true],
    'exact suffix' => ['10.5880/icgem', 'text', true],
    'other registrant' => ['10.1234/icgem.1', 'model', false],
    'substring inside GFZ suffix' => ['10.5880/alt.10.5880/ICGEM.tail', 'model', false],
    'substring inside another suffix' => ['10.9999/prefix.10.5880/ICGEM.tail', 'model', false],
    'no suffix boundary required' => ['10.5880/icgemother', 'model', true],
    'other DOI' => ['10.5880/other.model', 'model', false],
    'no DOI' => [null, 'model', false],
    'empty DOI' => ['', 'model', false],
    'accent' => ['10.5880/ícgem.model', 'model', false],
    'Unicode case folding' => ['10.5880/İCGEM.model', 'model', false],
    'literal decimal point' => ['10x5880/icgem.model', 'model', false],
]);

test('only the exact ICGEM set spec is accepted', function () {
    $service = app(OaiPmhSetService::class);
    expect($service->isValidSetSpec('icgem'))->toBeTrue();
    foreach (['ICGEM', 'icgem:extra', ' icgem', 'icgem '] as $spec) {
        expect($service->isValidSetSpec($spec))->toBeFalse();
    }
    // Laravel trims surrounding request whitespace before OAI validation.
    foreach (['ICGEM', 'icgem:extra'] as $spec) {
        $xml = simplexml_load_string($this->get('/oai-pmh?verb=ListRecords&metadataPrefix=oai_dc&set='.urlencode($spec))->getContent());
        expect((string) $xml->error['code'])->toBe('badArgument');
    }
});

test('ICGEM is discoverable and documented even when empty', function () {
    $xml = simplexml_load_string($this->get('/oai-pmh?verb=ListSets')->assertOk()->getContent());
    $xml->registerXPathNamespace('oai', 'http://www.openarchives.org/OAI/2.0/');
    $set = $xml->xpath('//oai:set[oai:setSpec="icgem"]')[0];
    expect((string) $set->setName)->toBe('ICGEM Models');
    $set->registerXPathNamespace('dc', 'http://purl.org/dc/elements/1.1/');
    expect((string) $set->xpath('.//dc:description')[0])->toContain('any type', 'own DOI starts with 10.5880/icgem', 'ignoring ASCII letter case');
    foreach (['ListIdentifiers', 'ListRecords'] as $verb) {
        $empty = simplexml_load_string($this->get('/oai-pmh?verb='.$verb.'&metadataPrefix=oai_datacite&set=icgem')->getContent());
        expect((string) $empty->error['code'])->toBe('noRecordsMatch');
    }
    $this->get('/oai-pmh/docs')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('oai-pmh/docs')->where('icgemSet', app(IcgemSetService::class)->definition()));
});

test('ICGEM harvesting includes every published type available in the requested format', function (string $verb, string $format) {
    $model = createIcgemResource();
    $dataset = createIcgemResource('10.5880/ICGEM.2015.1', 'dataset');
    $dataset->update(['datacenter_id' => Datacenter::factory()->create(['name' => 'Another datacenter'])->id]);
    Subject::factory()->create(['resource_id' => $dataset->id, 'value' => 'EPOS']);
    $software = createIcgemResource('10.5880/iCgEm2026', 'software');
    $text = createIcgemResource('10.5880/icgem', 'text');
    createIcgemResource('10.1234/icgem.1');
    createIcgemResource('10.5880/alt.10.5880/icgem.1');
    createIcgemResource('10.5880/other.model');
    createIcgemResource('10.5880/icgem.draft', published: false);
    $withoutPage = createIcgemResource('10.5880/icgem.without-page');
    $withoutPage->landingPage->delete();
    $unrelated = createIcgemResource('10.5880/unrelated');
    RelatedIdentifier::create([
        'resource_id' => $unrelated->id, 'identifier' => '10.5880/icgem.related',
        'identifier_type_id' => IdentifierType::firstOrCreate(['name' => 'DOI'], ['slug' => 'DOI'])->id,
        'relation_type_id' => RelationType::firstOrCreate(['name' => 'Cites'], ['slug' => 'Cites'])->id,
    ]);
    $withoutDoi = createIcgemResource('10.5880/icgem.without-doi', published: false);
    $withoutDoi->update(['doi' => null]);
    $withoutDoi->landingPage->publish();

    $content = $this->get("/oai-pmh?verb={$verb}&metadataPrefix={$format}&set=icgem")->assertOk()->getContent();
    $expected = [icgemId($model->doi) => 'model', icgemId($dataset->doi) => 'dataset', icgemId($software->doi) => 'software'];
    if ($format !== 'iso19115_3') {
        $expected[icgemId($text->doi)] = 'text';
    }
    expect(icgemIdentifiers($content))->toEqualCanonicalizing(array_keys($expected));
    foreach (icgemHeaders($content) as $header) {
        expect(array_map('strval', iterator_to_array($header->setSpec, false)))->toContain('icgem', 'resourcetype:'.$expected[(string) $header->identifier]);
    }
})->with(['ListIdentifiers', 'ListRecords'])->with(['oai_dc', 'oai_datacite', 'iso19115_3']);

test('ICGEM membership appears in unfiltered and single-record responses', function (string $format) {
    $resource = createIcgemResource(type: 'dataset');
    foreach (['ListRecords', 'ListIdentifiers', 'GetRecord'] as $verb) {
        $identifier = $verb === 'GetRecord' ? '&identifier='.urlencode(icgemId($resource->doi)) : '';
        $content = $this->get("/oai-pmh?verb={$verb}&metadataPrefix={$format}{$identifier}")->assertOk()->getContent();
        expect($content)->toContain('<setSpec>icgem</setSpec>');
    }
})->with(['oai_dc', 'oai_datacite', 'iso19115_3']);

test('a full harvest includes an older dataset and the next incremental harvest exports its updated metadata', function () {
    $this->travelTo(now()->setYear(2020));
    $resource = createIcgemResource(type: 'dataset');
    $this->travelTo(now()->setYear(2026)->setTime(9, 0));
    $full = $this->get('/oai-pmh?verb=ListRecords&metadataPrefix=oai_datacite&set=icgem')->assertOk()->getContent();
    expect(icgemIdentifiers($full))->toBe([icgemId($resource->doi)])
        ->and((string) icgemHeaders($full)[0]->datestamp)->toBe('2020-11-02T08:00:00Z')
        ->and($full)->toContain('resourceTypeGeneral="Dataset"');
    [$updated, $isUpdate] = app(ResourceStorageService::class)->store([
        'resourceId' => $resource->id, 'year' => 2026, 'resourceType' => $resource->resource_type_id,
        'language' => 'en', 'version' => '1.0',
        'titles' => [['title' => 'Updated gravity model', 'titleType' => 'MainTitle']],
    ]);
    expect($updated->id)->toBe($resource->id)->and($isUpdate)->toBeTrue()
        ->and($updated->updated_at->toIso8601ZuluString())->toBe('2026-11-02T09:00:00Z');
    $content = $this->post('/oai-pmh', [
        'verb' => 'ListRecords', 'metadataPrefix' => 'oai_datacite', 'set' => 'icgem',
        'from' => '2026-11-02T09:00:00Z', 'until' => '2026-11-02T09:00:00Z',
    ])->assertOk()->getContent();
    expect(icgemIdentifiers($content))->toBe([icgemId($resource->doi)])
        ->and($content)->toContain('Updated gravity model', 'resourceTypeGeneral="Dataset"', $resource->doi)
        ->and((string) icgemHeaders($content)[0]->datestamp)->toBe('2026-11-02T09:00:00Z');
    $empty = simplexml_load_string($this->get('/oai-pmh?verb=ListIdentifiers&metadataPrefix=oai_dc&set=icgem&until=2026-11-02T08:59:59Z')->getContent());
    expect((string) $empty->error['code'])->toBe('noRecordsMatch');
});

test('disabling ISO preserves ICGEM Dublin Core and DataCite harvesting', function () {
    config(['iso19115.enabled' => false]);
    createIcgemResource();
    $iso = simplexml_load_string($this->get('/oai-pmh?verb=ListRecords&metadataPrefix=iso19115_3&set=icgem')->getContent());
    expect((string) $iso->error['code'])->toBe('cannotDisseminateFormat');
    foreach (['oai_dc', 'oai_datacite'] as $format) {
        expect(icgemHeaders($this->get('/oai-pmh?verb=ListRecords&metadataPrefix='.$format.'&set=icgem')->getContent()))->toHaveCount(1);
    }
});

test('a child-only editor save rolls its OAI datestamp back with its metadata', function () {
    $resource = createIcgemResource();
    $this->travel(1)->hours();
    DB::beginTransaction();
    try {
        [$updated, $isUpdate] = app(ResourceStorageService::class)->store([
            'resourceId' => $resource->id, 'year' => 2026, 'resourceType' => $resource->resource_type_id,
            'language' => 'en', 'version' => '1.0',
            'titles' => [['title' => 'Rolled back model', 'titleType' => 'MainTitle']],
        ]);
        expect($isUpdate)->toBeTrue()->and($updated->updated_at->hour)->toBe(9);
    } finally {
        DB::rollBack();
    }
    expect($resource->fresh()->updated_at->hour)->toBe(8)
        ->and($resource->fresh()->titles->first()->value)->toBe('ICGEM model metadata');
});

test('historical ICGEM withdrawals are derived without changing persisted evidence', function (string $verb, string $format) {
    $record = OaiPmhDeletedRecord::create([
        'doi' => '10.5880/ICGEM.historical', 'oai_identifier' => icgemId('10.5880/ICGEM.historical'),
        'datestamp' => '2020-01-01 00:00:00', 'sets' => ['resourcetype:dataset', 'year:2015'],
    ]);
    $original = $record->fresh()->getRawOriginal();
    $args = $verb === 'GetRecord' ? '&identifier='.urlencode($record->oai_identifier) : '&set=icgem';
    $content = $this->get("/oai-pmh?verb={$verb}&metadataPrefix={$format}{$args}")->assertOk()->getContent();
    $header = icgemHeaders($content)[0];
    expect((string) $header['status'])->toBe('deleted')
        ->and((string) $header->datestamp)->toBe('2020-01-01T00:00:00Z')
        ->and($content)->toContain('<setSpec>icgem</setSpec>', '<setSpec>resourcetype:dataset</setSpec>', '<setSpec>year:2015</setSpec>')->not->toContain('<metadata>')
        ->and($record->fresh()->getRawOriginal())->toBe($original);
    if ($verb !== 'GetRecord') {
        $unfiltered = $this->get("/oai-pmh?verb={$verb}&metadataPrefix={$format}")->getContent();
        expect($unfiltered)->toContain('<setSpec>icgem</setSpec>');
    }
})->with(['ListIdentifiers', 'ListRecords', 'GetRecord'])->with(['oai_dc', 'oai_datacite', 'iso19115_3']);

test('historical ICGEM selection and headers use the saved DOI', function (?array $sets, string $doi, bool $matches) {
    $record = OaiPmhDeletedRecord::create([
        'doi' => $doi, 'oai_identifier' => icgemId($doi), 'datestamp' => now(), 'sets' => $sets,
    ]);
    $service = app(OaiPmhSetService::class);
    $headerSets = array_count_values($service->getSetsForStoredRecord($record));
    expect($service->applyStoredSetFilter(OaiPmhDeletedRecord::query(), 'icgem')->exists())->toBe($matches)
        ->and($headerSets['icgem'] ?? 0)->toBe((int) $matches);
})->with([
    'unknown type' => [null, '10.5880/icgem.unknown', true],
    'empty sets' => [[], '10.5880/icgem.empty', true],
    'dataset' => [['resourcetype:dataset'], '10.5880/icgem.dataset', true],
    'wrong DOI' => [['resourcetype:model'], '10.5880/unrelated', false],
    'accent' => [['resourcetype:model'], '10.5880/ícgem.accent', false],
    'historical model' => [['resourcetype:model'], '10.5880/ICGEM.old', true],
    'already stored' => [['resourcetype:model', 'icgem', 'icgem'], '10.5880/icgem.stored', true],
    'stale membership' => [['icgem', 'resourcetype:model'], '10.5880/alt.10.5880/icgem.1', false],
]);

test('a previously stored substring match is removed from deleted headers on read', function () {
    $doi = '10.5880/alt.10.5880/icgem.1';
    $record = OaiPmhDeletedRecord::create([
        'doi' => $doi, 'oai_identifier' => icgemId($doi),
        'datestamp' => now(), 'sets' => ['icgem', 'resourcetype:model'],
    ]);
    $this->get('/oai-pmh?verb=GetRecord&metadataPrefix=oai_dc&identifier='.urlencode($record->oai_identifier))
        ->assertSee('status="deleted"', false)->assertSee('<setSpec>resourcetype:model</setSpec>', false)->assertDontSee('<setSpec>icgem</setSpec>', false);
    $this->get('/oai-pmh?verb=ListIdentifiers&metadataPrefix=oai_dc&set=icgem')->assertSee('code="noRecordsMatch"', false);
    expect($record->fresh()->sets)->toBe(['icgem', 'resourcetype:model']);
});

test('ICGEM withdrawals retain membership through deletion and depublication', function (bool $delete) {
    $resource = createIcgemResource(type: 'dataset');
    $this->travel(1)->hours();
    if ($delete) {
        $resource->delete();
    } else {
        $resource->landingPage->unpublish();
    }
    expect(OaiPmhDeletedRecord::first()->sets)->toContain('icgem', 'resourcetype:dataset');
    $content = $this->get('/oai-pmh?verb=ListRecords&metadataPrefix=oai_datacite&set=icgem&from=2026-11-02T09:00:00Z')->getContent();
    expect((string) icgemHeaders($content)[0]['status'])->toBe('deleted');
    if (! $delete) {
        $this->travel(1)->hours();
        $resource->landingPage->publish();
        expect(OaiPmhDeletedRecord::count())->toBe(0);
        $content = $this->get('/oai-pmh?verb=ListRecords&metadataPrefix=oai_datacite&set=icgem&from=2026-11-02T10:00:00Z')->getContent();
        expect((string) icgemHeaders($content)[0]['status'])->toBe('');
    }
})->with([true, false]);

test('resource-type changes update the type set while retaining ICGEM membership', function () {
    $resource = createIcgemResource(type: 'dataset');
    $model = ResourceType::firstOrCreate(['slug' => 'model'], ['name' => 'Model']);
    $oldType = $resource->resource_type_id;
    $this->travel(1)->hours();
    $resource->update(['resource_type_id' => $model->id]);
    $content = $this->get('/oai-pmh?verb=ListIdentifiers&metadataPrefix=oai_dc&set=icgem&from=2026-11-02T09:00:00Z')->getContent();
    expect(icgemIdentifiers($content))->toBe([icgemId($resource->doi)])
        ->and($content)->toContain('<setSpec>icgem</setSpec>', '<setSpec>resourcetype:model</setSpec>')->not->toContain('<setSpec>resourcetype:dataset</setSpec>');
    $this->travel(1)->hours();
    $resource->update(['resource_type_id' => $oldType]);
    $content = $this->get('/oai-pmh?verb=GetRecord&metadataPrefix=oai_dc&identifier='.urlencode(icgemId($resource->doi)))->getContent();
    expect($content)->toContain('<setSpec>icgem</setSpec>', '<setSpec>resourcetype:dataset</setSpec>')->not->toContain('<setSpec>resourcetype:model</setSpec>')
        ->and(OaiPmhDeletedRecord::count())->toBe(0);
});

test('deletion records ICGEM and the current type even when the previous type was cached', function (string $oldType, string $newType) {
    $resource = createIcgemResource(type: $oldType);
    $resource->load('resourceType');
    $type = ResourceType::firstOrCreate(['slug' => $newType], ['name' => ucfirst($newType)]);
    $resource->update(['resource_type_id' => $type->id]);
    $resource->delete();

    $record = OaiPmhDeletedRecord::where('doi', $resource->doi)->first();
    expect($record->sets)->toContain('icgem', 'resourcetype:'.$newType)->not->toContain('resourcetype:'.$oldType);
})->with([['dataset', 'model'], ['model', 'dataset']]);

test('DOI changes retire the old ICGEM identity and select the new identity by its DOI', function (string $newDoi, bool $newMember) {
    config(['oaipmh.page_size' => 1]);
    $resource = createIcgemResource();
    createIcgemResource('10.5880/icgem.2015.1');
    $xml = simplexml_load_string($this->get('/oai-pmh?verb=ListIdentifiers&metadataPrefix=oai_dc&set=icgem')->getContent());
    $token = (string) $xml->ListIdentifiers->resumptionToken;
    $oldDoi = $resource->doi;
    $this->travel(1)->hours();
    $resource->update([
        'doi' => $newDoi, 'resource_type_id' => ResourceType::firstOrCreate(['slug' => 'dataset'], ['name' => 'Dataset'])->id,
    ]);
    $invalid = simplexml_load_string($this->get('/oai-pmh?verb=ListIdentifiers&resumptionToken='.$token)->getContent());
    expect((string) $invalid->error['code'])->toBe('badResumptionToken')
        ->and(OaiPmhDeletedRecord::where('doi', $oldDoi)->first()->sets)->toContain('icgem', 'resourcetype:model');
    config(['oaipmh.page_size' => 100]);
    $content = $this->get('/oai-pmh?verb=ListIdentifiers&metadataPrefix=oai_dc&set=icgem&from=2026-11-02T09:00:00Z')->getContent();
    expect(icgemIdentifiers($content))->toBe($newMember ? [icgemId($oldDoi), icgemId($newDoi)] : [icgemId($oldDoi)])
        ->and((string) icgemHeaders($content)[0]['status'])->toBe('deleted');
})->with([['10.5880/icgem.changed', true], ['10.1234/icgem.changed', false]]);

test('a resource can enter ICGEM through a DOI change', function () {
    $resource = createIcgemResource('10.1234/icgem.original', 'software');
    $oldDoi = $resource->doi;
    $resource->update(['doi' => '10.5880/icgem.replacement']);

    $content = $this->get('/oai-pmh?verb=ListIdentifiers&metadataPrefix=oai_dc&set=icgem')->assertOk()->getContent();
    expect(icgemIdentifiers($content))->toBe([icgemId($resource->doi)])
        ->and((string) icgemHeaders($content)[0]['status'])->toBe('')
        ->and(OaiPmhDeletedRecord::where('doi', $oldDoi)->sole()->sets)->not->toContain('icgem');
});

test('ICGEM harvesting counts mixed resource types across every page without duplicates', function (string $verb) {
    config(['oaipmh.page_size' => 10]);
    $expected = ['10.5880/icgem.2026.004', '10.5880/icgem.2015.1'];
    for ($i = 0; $i < 53; $i++) {
        $expected[] = '10.5880/icgem.fixture.'.$i;
    }
    $types = ['model', 'dataset', 'software'];
    foreach ($expected as $index => $doi) {
        createIcgemResource($doi, $types[$index % count($types)]);
    }
    createIcgemResource('10.1234/icgem.exclude', 'dataset');
    $deleted = createIcgemResource('10.5880/icgem.deleted', 'software');
    $deleted->delete();
    $url = '/oai-pmh?verb='.$verb.'&metadataPrefix=oai_datacite&set=icgem';
    $active = [];
    $withdrawn = [];
    $pages = 0;
    do {
        $content = $this->get($url)->assertOk()->getContent();
        $xml = simplexml_load_string($content);
        expect(isset($xml->error))->toBeFalse();
        foreach (icgemHeaders($content) as $header) {
            if ((string) $header['status'] === 'deleted') {
                $withdrawn[] = (string) $header->identifier;
            } else {
                $active[] = (string) $header->identifier;
            }
        }
        $token = (string) $xml->{$verb}->resumptionToken;
        $url = '/oai-pmh?verb='.$verb.'&resumptionToken='.urlencode($token);
        expect(++$pages)->toBeLessThanOrEqual(6);
    } while ($token !== '');
    expect($active)->toHaveCount(55)->toEqualCanonicalizing(array_map('icgemId', $expected))
        ->and(array_unique($active))->toHaveCount(55)
        ->and($withdrawn)->toBe([icgemId($deleted->doi)])
        ->and($pages)->toBe(6);
})->with(['ListIdentifiers', 'ListRecords']);

test('a type change between ICGEM pages retains the record at its snapshot position', function (string $verb) {
    config(['oaipmh.page_size' => 1]);
    createIcgemResource();
    $second = createIcgemResource('10.5880/icgem.second');
    $third = createIcgemResource('10.5880/icgem.third');
    $xml = simplexml_load_string($this->get('/oai-pmh?verb='.$verb.'&metadataPrefix=oai_dc&set=icgem')->getContent());
    $token = (string) $xml->{$verb}->resumptionToken;
    $second->update(['resource_type_id' => ResourceType::firstOrCreate(['slug' => 'dataset'], ['name' => 'Dataset'])->id]);
    $next = $this->get('/oai-pmh?verb='.$verb.'&resumptionToken='.$token)->getContent();
    expect(icgemIdentifiers($next))->toBe([icgemId($second->doi)])
        ->and($next)->toContain('<setSpec>icgem</setSpec>', '<setSpec>resourcetype:dataset</setSpec>');
    $lastToken = (string) simplexml_load_string($next)->{$verb}->resumptionToken;
    $last = $this->get('/oai-pmh?verb='.$verb.'&resumptionToken='.$lastToken)->getContent();
    expect(icgemIdentifiers($last))->toBe([icgemId($third->doi)]);
})->with(['ListIdentifiers', 'ListRecords']);

test('a legacy ICGEM token cannot resume past the last prefix match', function (string $verb) {
    config(['oaipmh.page_size' => 1]);
    $member = createIcgemResource('10.5880/icgem.first');
    createIcgemResource('10.5880/archive.10.5880/icgem.second');

    // The first page returned the member. The old substring filter counted both models.
    $token = app(OaiPmhResumptionTokenService::class)->create($verb, 'oai_dc', 'icgem', null, null, cursor: 1, completeListSize: 2);
    $xml = simplexml_load_string($this->get('/oai-pmh?verb='.$verb.'&resumptionToken='.$token->token)->assertOk()->getContent());

    expect((string) $xml->error['code'])->toBe('badResumptionToken')
        ->and((string) $xml->request['resumptionToken'])->toBe($token->token)
        ->and(isset($xml->{$verb}))->toBeFalse();
    $this->assertDatabaseCount('oai_pmh_resumption_tokens', 1);

    $restart = $this->get('/oai-pmh?verb='.$verb.'&metadataPrefix=oai_dc&set=icgem')->assertOk()->getContent();
    expect(icgemIdentifiers($restart))->toBe([icgemId($member->doi)])
        ->and(isset(simplexml_load_string($restart)->{$verb}->resumptionToken))->toBeFalse();
})->with(['ListIdentifiers', 'ListRecords']);

test('legacy ICGEM tokens can advance from historical deleted headers to live records', function (string $verb) {
    config(['oaipmh.page_size' => 1]);
    OaiPmhDeletedRecord::create([
        'doi' => '10.5880/icgem.legacy', 'oai_identifier' => icgemId('10.5880/icgem.legacy'),
        'datestamp' => now(), 'sets' => null,
    ]);
    $live = createIcgemResource('10.5880/icgem.live');
    $token = app(OaiPmhResumptionTokenService::class)->create($verb, 'oai_dc', 'icgem', null, null, 0, 2);
    expect($token->harvest_id)->toBeNull();
    $content = $this->get('/oai-pmh?verb='.$verb.'&resumptionToken='.$token->token)->assertOk()->getContent();
    expect($content)->toContain('<setSpec>icgem</setSpec>')
        ->and((string) icgemHeaders($content)[0]['status'])->toBe('deleted');

    $next = (string) simplexml_load_string($content)->{$verb}->resumptionToken;
    $last = $this->get('/oai-pmh?verb='.$verb.'&resumptionToken='.$next)->assertOk()->getContent();
    $end = simplexml_load_string($last)->{$verb}->resumptionToken;
    expect(icgemIdentifiers($last))->toBe([icgemId($live->doi)])
        ->and((string) $end['cursor'])->toBe('1')
        ->and((string) $end)->toBe('');
})->with(['ListIdentifiers', 'ListRecords']);

test('ICGEM responses validate against the offline OAI and Dublin Core schemas', function () {
    createIcgemResource();
    $deleted = createIcgemResource('10.5880/icgem.deleted');
    $deleted->delete();
    foreach (['ListSets', 'ListIdentifiers', 'ListRecords'] as $verb) {
        $args = $verb === 'ListSets' ? '' : '&metadataPrefix=oai_dc&set=icgem';
        $document = new DOMDocument;
        $document->loadXML($this->get('/oai-pmh?verb='.$verb.$args)->getContent(), LIBXML_NONET);
        expect($document->schemaValidate(base_path('tests/pest/Fixtures/oai-pmh/validation.xsd')))->toBeTrue();
    }
});
