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

test('ICGEM membership agrees in SQL and headers for the own DOI and model intersection', function (?string $doi, string $type, bool $matches) {
    $resourceType = ResourceType::firstOrCreate(['slug' => $type], ['name' => ucfirst($type)]);
    $resource = Resource::factory()->create(['doi' => $doi, 'resource_type_id' => $resourceType->id]);
    $service = app(OaiPmhSetService::class);

    expect($service->applySetFilter(Resource::query(), 'icgem')->whereKey($resource->id)->exists())->toBe($matches)
        ->and(in_array('icgem', $service->getSetsForResource($resource), true))->toBe($matches);
})->with([
    'first reference' => ['10.5880/icgem.2026.004', 'model', true],
    'second reference' => ['10.5880/icgem.2015.1', 'model', true],
    'ASCII mixed case' => ['10.5880/iCgEm.2026.004', 'model', true],
    'substring inside suffix' => ['10.9999/prefix.10.5880/ICGEM.tail', 'model', true],
    'no suffix boundary required' => ['10.5880/icgemother', 'model', true],
    'other DOI' => ['10.5880/other.model', 'model', false],
    'dataset' => ['10.5880/icgem.dataset', 'dataset', false],
    'software' => ['10.5880/icgem.software', 'software', false],
    'no DOI' => [null, 'model', false],
    'empty DOI' => ['', 'model', false],
    'accent' => ['10.5880/ícgem.model', 'model', false],
    'Unicode case folding' => ['10.5880/İCGEM.model', 'model', false],
    'literal decimal point' => ['10x5880/icgem.model', 'model', false],
]);

test('missing type evidence never qualifies as an ICGEM model', function () {
    expect(app(IcgemSetService::class)->matches('10.5880/icgem.2026.004', null))->toBeFalse();
});

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
    expect((string) $set->xpath('.//dc:description')[0])->toBe(app(IcgemSetService::class)->definition()['description']);
    foreach (['ListIdentifiers', 'ListRecords'] as $verb) {
        $empty = simplexml_load_string($this->get('/oai-pmh?verb='.$verb.'&metadataPrefix=oai_datacite&set=icgem')->getContent());
        expect((string) $empty->error['code'])->toBe('noRecordsMatch');
    }
    $this->get('/oai-pmh/docs')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('oai-pmh/docs')->where('icgemSet', app(IcgemSetService::class)->definition()));
});

test('ICGEM harvesting includes only published models regardless of datacenter or keywords', function (string $verb, string $format) {
    $first = createIcgemResource();
    $second = createIcgemResource('10.5880/icgem.2015.1');
    $second->update(['datacenter_id' => Datacenter::factory()->create(['name' => 'Another datacenter'])->id]);
    Subject::factory()->create(['resource_id' => $second->id, 'value' => 'EPOS']);
    createIcgemResource('10.5880/icgem.dataset', 'dataset');
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
    expect(icgemIdentifiers($content))->toEqualCanonicalizing([icgemId($first->doi), icgemId($second->doi)]);
    foreach (icgemHeaders($content) as $header) {
        expect(array_map('strval', iterator_to_array($header->setSpec, false)))->toContain('icgem', 'resourcetype:model');
    }
})->with(['ListIdentifiers', 'ListRecords'])->with(['oai_dc', 'oai_datacite', 'iso19115_3']);

test('ICGEM membership appears in unfiltered and single-record responses', function (string $format) {
    $resource = createIcgemResource();
    foreach (['ListRecords', 'ListIdentifiers', 'GetRecord'] as $verb) {
        $identifier = $verb === 'GetRecord' ? '&identifier='.urlencode(icgemId($resource->doi)) : '';
        $content = $this->get("/oai-pmh?verb={$verb}&metadataPrefix={$format}{$identifier}")->assertOk()->getContent();
        expect($content)->toContain('<setSpec>icgem</setSpec>');
    }
})->with(['oai_dc', 'oai_datacite', 'iso19115_3']);

test('POST exports current DataCite model metadata and inclusive incremental updates', function () {
    $resource = createIcgemResource();
    $this->travel(1)->hours();
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
        ->and($content)->toContain('Updated gravity model', 'resourceTypeGeneral="Model"', $resource->doi)
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
        'datestamp' => '2020-01-01 00:00:00', 'sets' => ['resourcetype:model', 'year:2015'],
    ]);
    $original = $record->fresh()->getRawOriginal();
    $args = $verb === 'GetRecord' ? '&identifier='.urlencode($record->oai_identifier) : '&set=icgem';
    $content = $this->get("/oai-pmh?verb={$verb}&metadataPrefix={$format}{$args}")->assertOk()->getContent();
    $header = icgemHeaders($content)[0];
    expect((string) $header['status'])->toBe('deleted')
        ->and((string) $header->datestamp)->toBe('2020-01-01T00:00:00Z')
        ->and($content)->toContain('<setSpec>icgem</setSpec>')->not->toContain('<metadata>')
        ->and($record->fresh()->getRawOriginal())->toBe($original);
    if ($verb !== 'GetRecord') {
        $unfiltered = $this->get("/oai-pmh?verb={$verb}&metadataPrefix={$format}")->getContent();
        expect($unfiltered)->toContain('<setSpec>icgem</setSpec>');
    }
})->with(['ListIdentifiers', 'ListRecords', 'GetRecord'])->with(['oai_dc', 'oai_datacite', 'iso19115_3']);

test('historical ICGEM selection requires the saved model type and matching DOI', function (?array $sets, string $doi, bool $matches) {
    $record = OaiPmhDeletedRecord::create([
        'doi' => $doi, 'oai_identifier' => icgemId($doi), 'datestamp' => now(), 'sets' => $sets,
    ]);
    $service = app(OaiPmhSetService::class);
    expect($service->applyDeletedSetFilter(OaiPmhDeletedRecord::query(), 'icgem')->exists())->toBe($matches)
        ->and(in_array('icgem', $service->getSetsForDeletedRecord($record), true))->toBe($matches);
    if ($matches) {
        expect(array_count_values($service->getSetsForDeletedRecord($record))['icgem'])->toBe(1);
    }
})->with([
    'unknown type' => [null, '10.5880/icgem.unknown', false],
    'empty sets' => [[], '10.5880/icgem.empty', false],
    'wrong type' => [['resourcetype:dataset'], '10.5880/icgem.dataset', false],
    'wrong DOI' => [['resourcetype:model'], '10.5880/unrelated', false],
    'accent' => [['resourcetype:model'], '10.5880/ícgem.accent', false],
    'historical model' => [['resourcetype:model'], '10.5880/ICGEM.old', true],
    'already stored' => [['resourcetype:model', 'icgem'], '10.5880/icgem.stored', true],
]);

test('ICGEM historical membership uses saved evidence rather than the current resource type', function () {
    $resource = createIcgemResource(published: false);
    $record = OaiPmhDeletedRecord::create([
        'doi' => $resource->doi, 'oai_identifier' => icgemId($resource->doi),
        'datestamp' => now(), 'sets' => ['resourcetype:model'],
    ]);
    $dataset = ResourceType::firstOrCreate(['slug' => 'dataset'], ['name' => 'Dataset']);
    $resource->update(['resource_type_id' => $dataset->id]);
    expect(app(OaiPmhSetService::class)->getSetsForDeletedRecord($record))->toContain('icgem');
});

test('ICGEM withdrawals retain membership through deletion and depublication', function (bool $delete) {
    $resource = createIcgemResource();
    $this->travel(1)->hours();
    if ($delete) {
        $resource->delete();
    } else {
        $resource->landingPage->unpublish();
    }
    expect(OaiPmhDeletedRecord::first()->sets)->toContain('icgem', 'resourcetype:model');
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

test('resource-type changes enter and leave ICGEM without a false global deletion', function () {
    $resource = createIcgemResource(type: 'dataset');
    $model = ResourceType::firstOrCreate(['slug' => 'model'], ['name' => 'Model']);
    $oldType = $resource->resource_type_id;
    $this->travel(1)->hours();
    $resource->update(['resource_type_id' => $model->id]);
    $content = $this->get('/oai-pmh?verb=ListIdentifiers&metadataPrefix=oai_dc&set=icgem&from=2026-11-02T09:00:00Z')->getContent();
    expect(icgemIdentifiers($content))->toBe([icgemId($resource->doi)]);
    $this->travel(1)->hours();
    $resource->update(['resource_type_id' => $oldType]);
    $content = $this->get('/oai-pmh?verb=GetRecord&metadataPrefix=oai_dc&identifier='.urlencode(icgemId($resource->doi)))->getContent();
    expect($content)->not->toContain('<setSpec>icgem</setSpec>')
        ->and(OaiPmhDeletedRecord::count())->toBe(0);
});

test('deletion uses the current type even when the previous type relation was cached', function (bool $enters) {
    $resource = createIcgemResource(type: $enters ? 'dataset' : 'model');
    $resource->load('resourceType');
    $type = ResourceType::firstOrCreate(['slug' => $enters ? 'model' : 'dataset'], ['name' => $enters ? 'Model' : 'Dataset']);
    $resource->update(['resource_type_id' => $type->id]);
    $resource->delete();

    $record = OaiPmhDeletedRecord::where('doi', $resource->doi)->first();
    expect($record->sets)->toContain('resourcetype:'.$type->slug)
        ->and(in_array('icgem', $record->sets, true))->toBe($enters);
})->with([false, true]);

test('DOI changes retire the old ICGEM identity using its original type and invalidate tokens', function (bool $changeType) {
    config(['oaipmh.page_size' => 1]);
    $resource = createIcgemResource();
    createIcgemResource('10.5880/icgem.2015.1');
    $xml = simplexml_load_string($this->get('/oai-pmh?verb=ListIdentifiers&metadataPrefix=oai_dc&set=icgem')->getContent());
    $token = (string) $xml->ListIdentifiers->resumptionToken;
    $oldDoi = $resource->doi;
    $this->travel(1)->hours();
    $changes = ['doi' => '10.5880/icgem.changed'];
    if ($changeType) {
        $changes['resource_type_id'] = ResourceType::firstOrCreate(['slug' => 'dataset'], ['name' => 'Dataset'])->id;
    }
    $resource->update($changes);
    $invalid = simplexml_load_string($this->get('/oai-pmh?verb=ListIdentifiers&resumptionToken='.$token)->getContent());
    expect((string) $invalid->error['code'])->toBe('badResumptionToken')
        ->and(OaiPmhDeletedRecord::where('doi', $oldDoi)->first()->sets)->toContain('icgem', 'resourcetype:model');
    config(['oaipmh.page_size' => 100]);
    $content = $this->get('/oai-pmh?verb=ListIdentifiers&metadataPrefix=oai_dc&set=icgem&from=2026-11-02T09:00:00Z')->getContent();
    expect(icgemIdentifiers($content))->toBe($changeType ? [icgemId($oldDoi)] : [icgemId($oldDoi), icgemId($resource->doi)]);
})->with([false, true]);

test('ICGEM harvesting counts at least 50 active models across every page without duplicates', function (string $verb) {
    config(['oaipmh.page_size' => 10]);
    $expected = ['10.5880/icgem.2026.004', '10.5880/icgem.2015.1'];
    for ($i = 0; $i < 53; $i++) {
        $expected[] = '10.5880/icgem.fixture.'.$i;
    }
    foreach ($expected as $doi) {
        createIcgemResource($doi);
    }
    createIcgemResource('10.5880/icgem.exclude', 'dataset');
    $deleted = createIcgemResource('10.5880/icgem.deleted');
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

test('ICGEM snapshot pagination retains unchanged models after an earlier model leaves', function (string $verb) {
    config(['oaipmh.page_size' => 1]);
    $first = createIcgemResource();
    $second = createIcgemResource('10.5880/icgem.second');
    $third = createIcgemResource('10.5880/icgem.third');
    $xml = simplexml_load_string($this->get('/oai-pmh?verb='.$verb.'&metadataPrefix=oai_dc&set=icgem')->getContent());
    $token = (string) $xml->{$verb}->resumptionToken;
    $first->update(['resource_type_id' => ResourceType::firstOrCreate(['slug' => 'dataset'], ['name' => 'Dataset'])->id]);
    $next = $this->get('/oai-pmh?verb='.$verb.'&resumptionToken='.$token)->getContent();
    expect(icgemIdentifiers($next))->toBe([icgemId($second->doi)]);
    $lastToken = (string) simplexml_load_string($next)->{$verb}->resumptionToken;
    $last = $this->get('/oai-pmh?verb='.$verb.'&resumptionToken='.$lastToken)->getContent();
    expect(icgemIdentifiers($last))->toBe([icgemId($third->doi)]);
})->with(['ListIdentifiers', 'ListRecords']);

test('legacy ICGEM tokens also derive historical deleted headers', function (string $verb) {
    OaiPmhDeletedRecord::create([
        'doi' => '10.5880/icgem.legacy', 'oai_identifier' => icgemId('10.5880/icgem.legacy'),
        'datestamp' => now(), 'sets' => ['resourcetype:model'],
    ]);
    $token = app(OaiPmhResumptionTokenService::class)->create($verb, 'oai_dc', 'icgem', null, null, 0, 1);
    expect($token->harvest_id)->toBeNull();
    $content = $this->get('/oai-pmh?verb='.$verb.'&resumptionToken='.$token->token)->getContent();
    expect($content)->toContain('<setSpec>icgem</setSpec>')
        ->and((string) icgemHeaders($content)[0]['status'])->toBe('deleted');
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
