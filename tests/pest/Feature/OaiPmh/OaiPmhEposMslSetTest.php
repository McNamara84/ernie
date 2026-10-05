<?php

declare(strict_types=1);

use App\Models\Description;
use App\Models\DescriptionType;
use App\Models\LandingPage;
use App\Models\OaiPmhDeletedRecord;
use App\Models\OaiPmhHarvest;
use App\Models\OaiPmhResumptionToken;
use App\Models\Resource;
use App\Models\ResourceType;
use App\Models\Subject;
use App\Models\Title;
use App\Models\TitleType;
use App\Services\ResourceStorageService;
use App\Services\Subjects\SubjectDuplicateCleanupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 5)->setTime(8, 0));
});

/** @param list<string> $keywords */
function createEposMslResource(array $keywords = ['EPOS'], bool $published = true, string $type = 'dataset'): Resource
{
    $resourceType = ResourceType::firstOrCreate(['slug' => $type], ['name' => ucfirst($type), 'is_active' => true]);
    $resource = Resource::factory()->create(['resource_type_id' => $resourceType->id, 'publication_year' => 2026]);
    LandingPage::factory()->create([
        'resource_id' => $resource->id, 'is_published' => $published, 'published_at' => $published ? now() : null,
    ]);
    foreach ($keywords as $keyword) {
        Subject::factory()->create(['resource_id' => $resource->id, 'value' => $keyword]);
    }
    Title::create([
        'resource_id' => $resource->id, 'value' => 'Project resource',
        'title_type_id' => TitleType::firstOrCreate(['slug' => 'MainTitle'], ['name' => 'Main Title', 'is_active' => true])->id,
    ]);
    Description::create([
        'resource_id' => $resource->id, 'value' => 'Project metadata for harvesting.',
        'description_type_id' => DescriptionType::firstOrCreate(['slug' => 'Abstract'], ['name' => 'Abstract', 'is_active' => true])->id,
    ]);

    return $resource->refresh();
}

/** @return list<SimpleXMLElement> */
function eposMslHeaders(string $content): array
{
    $xml = simplexml_load_string($content);
    $xml->registerXPathNamespace('oai', 'http://www.openarchives.org/OAI/2.0/');

    return $xml->xpath('//oai:header') ?: [];
}

/** @return list<string> */
function eposMslIdentifiers(string $content): array
{
    return array_map(fn (SimpleXMLElement $header): string => (string) $header->identifier, eposMslHeaders($content));
}

function eposMslId(Resource $resource): string
{
    return config('oaipmh.identifier_prefix').':'.$resource->doi;
}

test('the project set is discoverable even without published records', function () {
    $xml = simplexml_load_string($this->get('/oai-pmh?verb=ListSets')->assertOk()->getContent());
    expect((string) $xml->ListSets->set->setSpec)->toBe('epos-msl')
        ->and((string) $xml->ListSets->set->setName)->toBe('EPOS-MSL Project');
    $xml->registerXPathNamespace('dc', 'http://purl.org/dc/elements/1.1/');
    expect((string) $xml->xpath('//dc:description')[0])->toContain('EPOS or MSL');
    $empty = simplexml_load_string($this->get('/oai-pmh?verb=ListRecords&metadataPrefix=oai_dc&set=epos-msl')->getContent());
    expect((string) $empty->error['code'])->toBe('noRecordsMatch');
});

test('project harvesting returns the published union once in every format', function (string $verb, string $format) {
    $epos = createEposMslResource(['ePoS']);
    $msl = createEposMslResource(["\t MSL \r\n"]);
    $both = createEposMslResource(['EPOS', 'MSL', 'EPOS']);
    createEposMslResource(['EPOS-MSL']);
    createEposMslResource(['EPOS'], published: false);
    $controlled = createEposMslResource([]);
    Subject::factory()->msl()->create(['resource_id' => $controlled->id, 'value' => 'EPOS']);
    $withoutId = createEposMslResource();
    $withoutId->update(['doi' => null]);
    $withoutPage = createEposMslResource();
    $withoutPage->landingPage->delete();

    $response = $this->get("/oai-pmh?verb={$verb}&metadataPrefix={$format}&set=epos-msl")->assertOk();
    expect(eposMslIdentifiers($response->getContent()))->toEqualCanonicalizing([
        eposMslId($epos), eposMslId($msl), eposMslId($both),
    ]);
    foreach (eposMslHeaders($response->getContent()) as $header) {
        expect(array_map('strval', iterator_to_array($header->setSpec, false)))->toContain('epos-msl');
    }
})->with(['ListRecords', 'ListIdentifiers'])->with(['oai_dc', 'oai_datacite', 'iso19115_3']);

test('project membership is in unfiltered and single-record headers', function (string $format) {
    $resource = createEposMslResource();
    foreach (['ListRecords', 'ListIdentifiers', 'GetRecord'] as $verb) {
        $identifier = $verb === 'GetRecord' ? '&identifier='.urlencode(eposMslId($resource)) : '';
        $content = $this->get("/oai-pmh?verb={$verb}&metadataPrefix={$format}{$identifier}")->assertOk()->getContent();
        expect((string) eposMslHeaders($content)[0]->setSpec[2])->toBe('epos-msl');
    }
})->with(['oai_dc', 'oai_datacite', 'iso19115_3']);

test('POST supports the project set and format eligibility', function () {
    $dataset = createEposMslResource();
    $project = createEposMslResource(type: 'project');
    $response = $this->post('/oai-pmh', ['verb' => 'ListIdentifiers', 'metadataPrefix' => 'iso19115_3', 'set' => 'epos-msl'])->assertOk();
    expect(eposMslIdentifiers($response->getContent()))->toBe([eposMslId($dataset)]);
    $dc = $this->post('/oai-pmh', ['verb' => 'ListIdentifiers', 'metadataPrefix' => 'oai_dc', 'set' => 'epos-msl'])->assertOk();
    expect(eposMslIdentifiers($dc->getContent()))->toEqualCanonicalizing([eposMslId($dataset), eposMslId($project)]);
});

test('direct subject changes advance the datestamp without creating a deletion on set exit', function () {
    $resource = createEposMslResource([]);
    $this->travel(1)->hours();
    $subject = Subject::factory()->create(['resource_id' => $resource->id, 'value' => 'EPOS']);
    $content = $this->get('/oai-pmh?verb=ListIdentifiers&metadataPrefix=oai_dc&set=epos-msl&from=2026-10-05T09:00:00Z&until=2026-10-05T09:00:00Z')->getContent();
    expect(eposMslIdentifiers($content))->toBe([eposMslId($resource)])
        ->and((string) eposMslHeaders($content)[0]->datestamp)->toBe('2026-10-05T09:00:00Z');
    $this->travel(1)->hours();
    $subject->update(['value' => 'MSL']);
    expect($resource->fresh()->updated_at->toIso8601ZuluString())->toBe('2026-10-05T10:00:00Z');
    $this->travel(1)->hours();
    $subject->delete();
    $content = $this->get('/oai-pmh?verb=ListIdentifiers&metadataPrefix=oai_dc&from=2026-10-05T11:00:00Z')->getContent();
    expect((string) eposMslHeaders($content)[0]->datestamp)->toBe('2026-10-05T11:00:00Z')
        ->and($content)->not->toContain('<setSpec>epos-msl</setSpec>')
        ->and(OaiPmhDeletedRecord::count())->toBe(0);
});

test('subject metadata changes can remove membership and roll back with the resource datestamp', function () {
    $resource = createEposMslResource();
    $subject = $resource->subjects->first();
    $this->travel(1)->hours();
    DB::beginTransaction();
    $subject->update(['value_uri' => 'https://example.org/epos']);
    expect($resource->fresh()->updated_at->hour)->toBe(9);
    DB::rollBack();
    expect($resource->fresh()->updated_at->hour)->toBe(8)
        ->and($subject->fresh()->value_uri)->toBeNull();
});

test('moving a subject timestamps both owning resources and a no-op save preserves time', function () {
    $first = createEposMslResource();
    $second = createEposMslResource([]);
    $subject = $first->subjects->first();
    $this->travel(1)->hours();
    $subject->update(['resource_id' => $second->id]);
    expect($first->fresh()->updated_at->hour)->toBe(9)
        ->and($second->fresh()->updated_at->hour)->toBe(9);
    $this->travel(1)->hours();
    $subject->save();
    expect($second->fresh()->updated_at->hour)->toBe(9);
});

test('editor removal of all keywords updates the returned resource and the incremental feed', function () {
    $service = app(ResourceStorageService::class);
    $type = ResourceType::factory()->create();
    $data = ['year' => 2026, 'resourceType' => $type->id, 'language' => 'en', 'freeKeywords' => ['EPOS'], 'titles' => []];
    [$resource] = $service->store($data);
    $resource->update(['doi' => '10.5880/epos.editor']);
    LandingPage::factory()->published()->create(['resource_id' => $resource->id]);
    $this->travel(1)->hours();
    [$updated] = $service->store([...$data, 'resourceId' => $resource->id, 'freeKeywords' => []]);
    expect($updated->updated_at->hour)->toBe(9)->and($updated->subjects)->toHaveCount(0);
    $content = $this->get('/oai-pmh?verb=ListIdentifiers&metadataPrefix=oai_dc&from=2026-10-05T09:00:00Z')->getContent();
    expect(eposMslIdentifiers($content))->toBe([eposMslId($resource)])
        ->and($content)->not->toContain('<setSpec>epos-msl</setSpec>');
});

test('resource deletion retains fresh project membership across the subject cascade', function () {
    $resource = createEposMslResource([]);
    $resource->load('subjects');
    Subject::factory()->create(['resource_id' => $resource->id, 'value' => 'EPOS']);
    $id = eposMslId($resource);
    $this->travel(1)->hours();
    $resource->delete();
    expect(Subject::where('resource_id', $resource->id)->exists())->toBeFalse()
        ->and(OaiPmhDeletedRecord::first()->sets)->toContain('epos-msl');
    foreach (['ListRecords', 'ListIdentifiers', 'GetRecord'] as $verb) {
        $args = $verb === 'GetRecord' ? '&identifier='.urlencode($id) : '&set=epos-msl';
        $content = $this->get("/oai-pmh?verb={$verb}&metadataPrefix=oai_dc{$args}")->assertOk()->getContent();
        expect((string) eposMslHeaders($content)[0]['status'])->toBe('deleted')
            ->and($content)->not->toContain('<metadata>');
    }
});

test('depublication refreshes subjects and republication restores a current project record', function () {
    $resource = createEposMslResource([]);
    $page = $resource->landingPage;
    $page->setRelation('resource', $resource->load('subjects'));
    Subject::factory()->create(['resource_id' => $resource->id, 'value' => 'MSL']);
    $this->travel(1)->hours();
    $page->unpublish();
    expect(OaiPmhDeletedRecord::first()->sets)->toContain('epos-msl');
    $this->travel(1)->hours();
    $page->publish();
    expect(OaiPmhDeletedRecord::count())->toBe(0);
    $content = $this->get('/oai-pmh?verb=ListIdentifiers&metadataPrefix=oai_dc&set=epos-msl&from=2026-10-05T10:00:00Z')->getContent();
    expect(eposMslIdentifiers($content))->toBe([eposMslId($resource)]);
});

test('project pagination does not skip unchanged resources after an earlier member leaves', function (string $verb) {
    config(['oaipmh.page_size' => 1]);
    $first = createEposMslResource();
    $second = createEposMslResource();
    $third = createEposMslResource();
    $initial = simplexml_load_string($this->get("/oai-pmh?verb={$verb}&metadataPrefix=oai_dc&set=epos-msl")->getContent());
    $token = (string) $initial->{$verb}->resumptionToken;
    $this->travel(1)->hours();
    $first->subjects->first()->delete();
    $next = $this->get("/oai-pmh?verb={$verb}&resumptionToken={$token}")->assertOk()->getContent();
    expect(eposMslIdentifiers($next))->toBe([eposMslId($second)]);
    $nextToken = (string) simplexml_load_string($next)->{$verb}->resumptionToken;
    $last = $this->get("/oai-pmh?verb={$verb}&resumptionToken={$nextToken}")->getContent();
    expect(eposMslIdentifiers($last))->toBe([eposMslId($third)])
        ->and((string) simplexml_load_string($last)->{$verb}->resumptionToken)->toBe('');
})->with(['ListRecords', 'ListIdentifiers']);

test('identifier harvesting loads subjects once per page rather than once per record', function () {
    for ($i = 0; $i < 4; $i++) {
        createEposMslResource();
    }
    DB::enableQueryLog();
    $this->get('/oai-pmh?verb=ListIdentifiers&metadataPrefix=oai_dc')->assertOk();
    $table = DB::connection()->getQueryGrammar()->wrapTable('subjects');
    $subjectLoads = collect(DB::getQueryLog())->filter(fn (array $entry): bool => str_starts_with($entry['query'], 'select * from '.$table));
    DB::disableQueryLog();
    expect($subjectLoads)->toHaveCount(1);
});

test('project responses validate against the official offline OAI and Dublin Core schemas', function () {
    config(['oaipmh.page_size' => 1]);
    createEposMslResource();
    $deleted = createEposMslResource(['MSL']);
    $deleted->delete();
    foreach (['ListSets', 'ListIdentifiers', 'ListRecords'] as $verb) {
        $args = $verb === 'ListSets' ? '' : '&metadataPrefix=oai_dc&set=epos-msl';
        $content = $this->get("/oai-pmh?verb={$verb}{$args}")->assertOk()->getContent();
        $document = new DOMDocument;
        $document->loadXML($content, LIBXML_NONET);
        expect($document->schemaValidate(base_path('tests/pest/Fixtures/oai-pmh/validation.xsd')))->toBeTrue();
    }
});

test('a second free keyword keeps membership after the first is removed', function () {
    $resource = createEposMslResource(['EPOS', 'MSL']);
    $this->travel(1)->hours();
    $resource->subjects->first()->delete();
    $content = $this->get('/oai-pmh?verb=ListIdentifiers&metadataPrefix=oai_dc&set=epos-msl&from=2026-10-05')->getContent();
    expect(eposMslIdentifiers($content))->toBe([eposMslId($resource)])
        ->and((string) eposMslHeaders($content)[0]->datestamp)->toBe('2026-10-05T09:00:00Z');
});

test('a vocabulary assignment removes membership and clearing it reintroduces the resource', function () {
    $resource = createEposMslResource();
    $subject = $resource->subjects->first();
    $this->travel(1)->hours();
    $subject->update(['subject_scheme' => 'Example vocabulary']);
    $response = $this->get('/oai-pmh?verb=ListIdentifiers&metadataPrefix=oai_dc&set=epos-msl')->getContent();
    expect((string) simplexml_load_string($response)->error['code'])->toBe('noRecordsMatch')
        ->and($resource->fresh()->updated_at->hour)->toBe(9);
    $this->travel(1)->hours();
    $subject->update(['subject_scheme' => null, 'language' => 'de']);
    $response = $this->get('/oai-pmh?verb=ListIdentifiers&metadataPrefix=oai_dc&set=epos-msl&from=2026-10-05T10:00:00Z')->getContent();
    expect(eposMslIdentifiers($response))->toBe([eposMslId($resource)]);
});

test('bulk duplicate cleanup timestamps changed resources but a dry run does not', function () {
    $resource = createEposMslResource(['EPOS', 'EPOS']);
    $this->travel(1)->hours();
    $service = app(SubjectDuplicateCleanupService::class);
    $service->run(includeFree: true);
    expect($resource->fresh()->updated_at->hour)->toBe(8);
    $result = $service->run(apply: true, includeFree: true);
    expect($result['errors'])->toBe(0)->and($result['duplicate_subjects'])->toBe(1)
        ->and($resource->fresh()->updated_at->hour)->toBe(9);
});

test('the docs controller exposes the central project definition', function () {
    $this->get('/oai-pmh/docs')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('oai-pmh/docs')->where('projectSet.spec', 'epos-msl')->where('projectSet.name', 'EPOS-MSL Project')
        ->where('projectSet.description', fn (string $description): bool => str_contains($description, 'EPOS or MSL')));
});

test('snapshot pages preserve unchanged items on retries after other items change', function () {
    config(['oaipmh.page_size' => 2]);
    createEposMslResource();
    createEposMslResource();
    $third = createEposMslResource();
    $fourth = createEposMslResource();
    $xml = simplexml_load_string($this->get('/oai-pmh?verb=ListIdentifiers&metadataPrefix=oai_dc&set=epos-msl')->getContent());
    $token = (string) $xml->ListIdentifiers->resumptionToken;
    $initial = $this->get('/oai-pmh?verb=ListIdentifiers&resumptionToken='.$token)->getContent();
    expect(eposMslIdentifiers($initial))->toBe([eposMslId($third), eposMslId($fourth)]);
    $this->travel(1)->hours();
    $third->landingPage->unpublish();
    createEposMslResource();
    $retry = $this->get('/oai-pmh?verb=ListIdentifiers&resumptionToken='.$token)->getContent();
    expect(eposMslIdentifiers($retry))->toBe([eposMslId($fourth)])
        ->and(OaiPmhHarvest::count())->toBe(1);
});

test('snapshot pagination survives deleted records disappearing on republication', function () {
    config(['oaipmh.page_size' => 1]);
    $deleted = createEposMslResource();
    $live = createEposMslResource();
    $deleted->landingPage->unpublish();
    $xml = simplexml_load_string($this->get('/oai-pmh?verb=ListRecords&metadataPrefix=oai_dc&set=epos-msl')->getContent());
    $token = (string) $xml->ListRecords->resumptionToken;
    $this->travel(1)->hours();
    $deleted->landingPage->publish();
    $next = $this->get('/oai-pmh?verb=ListRecords&resumptionToken='.$token)->getContent();
    expect(eposMslIdentifiers($next))->toBe([eposMslId($live)]);
});

test('snapshot cursors count returned records when a page loses and regains a member', function (string $verb) {
    config(['oaipmh.page_size' => 2]);
    $resources = collect(range(1, 6))->map(fn () => createEposMslResource());
    $xml = simplexml_load_string($this->get('/oai-pmh?verb='.$verb.'&metadataPrefix=oai_dc&set=epos-msl')->getContent());
    $firstToken = (string) $xml->{$verb}->resumptionToken;
    $thirdSubject = $resources[2]->subjects->first();
    $this->travel(1)->hours();
    $thirdSubject->update(['value' => 'geology']);

    $content = $this->get('/oai-pmh?verb='.$verb.'&resumptionToken='.$firstToken)->getContent();
    $xml = simplexml_load_string($content);
    $secondToken = (string) $xml->{$verb}->resumptionToken;
    $storedToken = OaiPmhResumptionToken::where('token', $secondToken)->first();
    expect(eposMslIdentifiers($content))->toBe([eposMslId($resources[3])])
        ->and((int) $xml->{$verb}->resumptionToken['cursor'])->toBe(2)
        ->and($storedToken->cursor)->toBe(3)
        ->and($storedToken->harvest_position)->toBe(4);

    $final = $this->get('/oai-pmh?verb='.$verb.'&resumptionToken='.$secondToken)->getContent();
    $xml = simplexml_load_string($final);
    expect(eposMslIdentifiers($final))->toBe([eposMslId($resources[4]), eposMslId($resources[5])])
        ->and((int) $xml->{$verb}->resumptionToken['cursor'])->toBe(3)
        ->and((string) $xml->{$verb}->resumptionToken)->toBe('');

    $this->travel(1)->hours();
    $thirdSubject->update(['value' => 'EPOS']);
    $retry = $this->get('/oai-pmh?verb='.$verb.'&resumptionToken='.$firstToken)->getContent();
    expect(eposMslIdentifiers($retry))->toBe([eposMslId($resources[2]), eposMslId($resources[3])]);
})->with(['ListRecords', 'ListIdentifiers']);

test('snapshot pagination requests a restart when a whole page disappears', function (bool $keepLast) {
    config(['oaipmh.page_size' => 1]);
    createEposMslResource();
    $second = createEposMslResource();
    $third = createEposMslResource();
    $xml = simplexml_load_string($this->get('/oai-pmh?verb=ListIdentifiers&metadataPrefix=oai_dc&set=epos-msl')->getContent());
    $token = (string) $xml->ListIdentifiers->resumptionToken;
    $second->delete();
    if (! $keepLast) {
        $third->delete();
    }
    $content = $this->get('/oai-pmh?verb=ListIdentifiers&resumptionToken='.$token)->getContent();
    expect((string) simplexml_load_string($content)->error['code'])->toBe('badResumptionToken');
})->with([true, false]);

test('later page tokens share the initial snapshot and expiration', function () {
    config(['oaipmh.page_size' => 1]);
    for ($i = 0; $i < 4; $i++) {
        createEposMslResource();
    }
    $xml = simplexml_load_string($this->get('/oai-pmh?verb=ListIdentifiers&metadataPrefix=oai_dc&set=epos-msl')->getContent());
    $first = OaiPmhResumptionToken::where('token', (string) $xml->ListIdentifiers->resumptionToken)->first();
    $this->travel(1)->hours();
    $xml = simplexml_load_string($this->get('/oai-pmh?verb=ListIdentifiers&resumptionToken='.$first->token)->getContent());
    $second = OaiPmhResumptionToken::where('token', (string) $xml->ListIdentifiers->resumptionToken)->first();
    expect($second->harvest_id)->toBe($first->harvest_id)
        ->and($second->expires_at->equalTo($first->expires_at))->toBeTrue()
        ->and($second->set_spec)->toBe('epos-msl');
    $this->travel(24)->hours();
    $expired = simplexml_load_string($this->get('/oai-pmh?verb=ListIdentifiers&resumptionToken='.$second->token)->getContent());
    expect((string) $expired->error['code'])->toBe('badResumptionToken');
});
