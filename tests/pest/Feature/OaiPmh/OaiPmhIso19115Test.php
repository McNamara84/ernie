<?php

declare(strict_types=1);

use App\Models\Description;
use App\Models\DescriptionType;
use App\Models\LandingPage;
use App\Models\OaiPmhDeletedRecord;
use App\Models\Resource;
use App\Models\ResourceType;
use App\Models\Title;
use App\Models\TitleType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createIsoOaiResource(string $doi, string $resourceTypeSlug = 'dataset'): Resource
{
    $resourceType = ResourceType::firstOrCreate(
        ['slug' => $resourceTypeSlug],
        ['name' => ucfirst(str_replace('-', ' ', $resourceTypeSlug)), 'is_active' => true],
    );
    $resource = Resource::factory()->create([
        'doi' => $doi,
        'resource_type_id' => $resourceType->id,
    ]);
    LandingPage::factory()->published()->create([
        'resource_id' => $resource->id,
        'doi_prefix' => $doi,
        'slug' => 'oai-'.str_replace(['10.', '/', '.'], ['', '-', '-'], $doi),
    ]);

    $titleType = TitleType::firstOrCreate(
        ['slug' => 'MainTitle'],
        ['name' => 'Main Title', 'is_active' => true],
    );
    Title::create([
        'resource_id' => $resource->id,
        'value' => "ISO record {$doi}",
        'title_type_id' => $titleType->id,
    ]);

    $descriptionType = DescriptionType::firstOrCreate(
        ['slug' => 'Abstract'],
        ['name' => 'Abstract', 'is_active' => true],
    );
    Description::create([
        'resource_id' => $resource->id,
        'value' => 'ISO 19115-3 OAI-PMH integration test.',
        'description_type_id' => $descriptionType->id,
    ]);

    return $resource->refresh();
}

function isoOaiIdentifier(string $doi): string
{
    return config('oaipmh.identifier_prefix').':'.$doi;
}

test('ListMetadataFormats advertises the current ISO schema and namespace', function () {
    $response = $this->get('/oai-pmh?verb=ListMetadataFormats')->assertOk();
    $xml = simplexml_load_string($response->getContent());
    $isoFormat = null;

    foreach ($xml->ListMetadataFormats->metadataFormat as $format) {
        if ((string) $format->metadataPrefix === 'iso19115_3') {
            $isoFormat = $format;
            break;
        }
    }

    expect($isoFormat)->not->toBeNull()
        ->and((string) $isoFormat->schema)->toBe('https://schemas.isotc211.org/19115/-1/mdb/1.3.0/mdb.xsd')
        ->and((string) $isoFormat->metadataNamespace)->toBe('https://schemas.isotc211.org/19115/-1/mdb/1.3');
});

test('ListMetadataFormats exposes ISO selectively per live identifier', function () {
    $dataset = createIsoOaiResource('10.5880/iso.formats.dataset');
    $project = createIsoOaiResource('10.5880/iso.formats.project', 'project');

    $datasetContent = $this->get(
        '/oai-pmh?verb=ListMetadataFormats&identifier='.isoOaiIdentifier($dataset->doi),
    )->getContent();
    $projectContent = $this->get(
        '/oai-pmh?verb=ListMetadataFormats&identifier='.isoOaiIdentifier($project->doi),
    )->getContent();

    expect($datasetContent)->toContain('<metadataPrefix>iso19115_3</metadataPrefix>')
        ->and($projectContent)->not->toContain('<metadataPrefix>iso19115_3</metadataPrefix>')
        ->and($projectContent)->toContain('<metadataPrefix>oai_dc</metadataPrefix>')
        ->and($projectContent)->toContain('<metadataPrefix>oai_datacite</metadataPrefix>');
});

test('ListRecords returns ISO 19115-3 metadata for eligible resources', function () {
    $resource = createIsoOaiResource('10.5880/iso.list.dataset');

    $content = $this->get('/oai-pmh?verb=ListRecords&metadataPrefix=iso19115_3')
        ->assertOk()
        ->getContent();

    expect($content)->toContain(isoOaiIdentifier($resource->doi))
        ->and($content)->toContain('<mdb:MD_Metadata')
        ->and($content)->toContain('https://schemas.isotc211.org/19115/-1/mdb/1.3')
        ->and($content)->toContain("ISO record {$resource->doi}");
});

test('GetRecord returns ISO for eligible resources and cannotDisseminateFormat for excluded types', function () {
    $dataset = createIsoOaiResource('10.5880/iso.get.dataset');
    $project = createIsoOaiResource('10.5880/iso.get.project', 'project');

    $datasetContent = $this->get(
        '/oai-pmh?verb=GetRecord&identifier='.isoOaiIdentifier($dataset->doi).'&metadataPrefix=iso19115_3',
    )->assertOk()->getContent();
    $projectXml = simplexml_load_string(
        $this->get(
            '/oai-pmh?verb=GetRecord&identifier='.isoOaiIdentifier($project->doi).'&metadataPrefix=iso19115_3',
        )->getContent(),
    );

    expect($datasetContent)->toContain('<mdb:MD_Metadata')
        ->and((string) $projectXml->error['code'])->toBe('cannotDisseminateFormat');
});

test('ISO list verbs exclude unsupported live resource types before counting', function () {
    $dataset = createIsoOaiResource('10.5880/iso.filter.dataset');
    $project = createIsoOaiResource('10.5880/iso.filter.project', 'project');

    $recordContent = $this->get('/oai-pmh?verb=ListRecords&metadataPrefix=iso19115_3')->getContent();
    $identifierContent = $this->get('/oai-pmh?verb=ListIdentifiers&metadataPrefix=iso19115_3')->getContent();

    expect($recordContent)->toContain($dataset->doi)
        ->and($recordContent)->not->toContain($project->doi)
        ->and($identifierContent)->toContain($dataset->doi)
        ->and($identifierContent)->not->toContain($project->doi);
});

test('ISO ListRecords returns noRecordsMatch when only excluded types exist', function () {
    createIsoOaiResource('10.5880/iso.only.project', 'project');

    $xml = simplexml_load_string(
        $this->get('/oai-pmh?verb=ListRecords&metadataPrefix=iso19115_3')->getContent(),
    );

    expect((string) $xml->error['code'])->toBe('noRecordsMatch');
});

test('ISO deleted records are filtered by their persisted resource-type set', function () {
    OaiPmhDeletedRecord::create([
        'oai_identifier' => isoOaiIdentifier('10.5880/iso.deleted.dataset'),
        'doi' => '10.5880/iso.deleted.dataset',
        'datestamp' => now(),
        'sets' => ['resourcetype:project', 'resourcetype:dataset', 'year:2024'],
    ]);
    OaiPmhDeletedRecord::create([
        'oai_identifier' => isoOaiIdentifier('10.5880/iso.deleted.project'),
        'doi' => '10.5880/iso.deleted.project',
        'datestamp' => now(),
        'sets' => ['resourcetype:project', 'year:2024'],
    ]);

    $content = $this->get('/oai-pmh?verb=ListRecords&metadataPrefix=iso19115_3')->getContent();
    $excludedXml = simplexml_load_string(
        $this->get(
            '/oai-pmh?verb=GetRecord&identifier='.isoOaiIdentifier('10.5880/iso.deleted.project').'&metadataPrefix=iso19115_3',
        )->getContent(),
    );

    expect($content)->toContain('10.5880/iso.deleted.dataset')
        ->and($content)->toContain('status="deleted"')
        ->and($content)->not->toContain('10.5880/iso.deleted.project')
        ->and((string) $excludedXml->error['code'])->toBe('cannotDisseminateFormat');
});

test('ISO pagination calculates completeListSize and cursors after eligibility filtering', function () {
    config(['oaipmh.page_size' => 1]);
    $firstDataset = createIsoOaiResource('10.5880/iso.page.dataset.1');
    $secondDataset = createIsoOaiResource('10.5880/iso.page.dataset.2');
    $project = createIsoOaiResource('10.5880/iso.page.project', 'project');

    $firstResponse = $this->get('/oai-pmh?verb=ListRecords&metadataPrefix=iso19115_3')->assertOk();
    $firstXml = simplexml_load_string($firstResponse->getContent());
    $firstToken = $firstXml->ListRecords->resumptionToken;

    expect((string) $firstToken)->not->toBeEmpty()
        ->and((int) $firstToken['completeListSize'])->toBe(2)
        ->and((int) $firstToken['cursor'])->toBe(0)
        ->and($firstResponse->getContent())->not->toContain($project->doi);

    $secondResponse = $this->get('/oai-pmh?verb=ListRecords&resumptionToken='.urlencode((string) $firstToken))->assertOk();
    $secondXml = simplexml_load_string($secondResponse->getContent());
    $secondToken = $secondXml->ListRecords->resumptionToken;
    $combined = $firstResponse->getContent().$secondResponse->getContent();

    expect($combined)->toContain($firstDataset->doi)
        ->and($combined)->toContain($secondDataset->doi)
        ->and($combined)->not->toContain($project->doi)
        ->and((string) $secondToken)->toBeEmpty()
        ->and((int) $secondToken['completeListSize'])->toBe(2)
        ->and((int) $secondToken['cursor'])->toBe(1);
});

test('ISO feature flag removes the OAI format and rejects ISO harvesting', function () {
    createIsoOaiResource('10.5880/iso.disabled.dataset');
    config(['iso19115.enabled' => false]);

    $formatsContent = $this->get('/oai-pmh?verb=ListMetadataFormats')->getContent();
    $listXml = simplexml_load_string(
        $this->get('/oai-pmh?verb=ListRecords&metadataPrefix=iso19115_3')->getContent(),
    );

    expect($formatsContent)->not->toContain('<metadataPrefix>iso19115_3</metadataPrefix>')
        ->and((string) $listXml->error['code'])->toBe('cannotDisseminateFormat');
});

test('ISO resumption tokens fail cleanly when the format is disabled between pages', function () {
    config(['oaipmh.page_size' => 1]);
    createIsoOaiResource('10.5880/iso.token.disabled.1');
    createIsoOaiResource('10.5880/iso.token.disabled.2');

    $firstXml = simplexml_load_string(
        $this->get('/oai-pmh?verb=ListRecords&metadataPrefix=iso19115_3')->getContent(),
    );
    $token = (string) $firstXml->ListRecords->resumptionToken;
    expect($token)->not->toBeEmpty();

    config(['iso19115.enabled' => false]);
    $secondXml = simplexml_load_string(
        $this->get('/oai-pmh?verb=ListRecords&resumptionToken='.urlencode($token))->getContent(),
    );

    expect((string) $secondXml->error['code'])->toBe('badResumptionToken')
        ->and((string) $secondXml->error)
        ->toContain('no longer supported');
});
test('OAI documentation receives the ISO format and configured eligible types', function () {
    $this->get('/oai-pmh/docs')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where(
                'metadataFormats.iso19115_3.schema',
                'https://schemas.isotc211.org/19115/-1/mdb/1.3.0/mdb.xsd',
            )
            ->where(
                'metadataFormats.iso19115_3.namespace',
                'https://schemas.isotc211.org/19115/-1/mdb/1.3',
            )
            ->where('isoEligibleResourceTypeSlugs', array_keys(config('iso19115.resource_scopes')))
        );
});

test('OAI documentation follows the ISO feature flag', function () {
    config(['iso19115.enabled' => false]);

    $this->get('/oai-pmh/docs')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->missing('metadataFormats.iso19115_3')
            ->where('isoEligibleResourceTypeSlugs', [])
        );
});
