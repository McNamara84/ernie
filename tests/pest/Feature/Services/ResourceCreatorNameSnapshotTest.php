<?php

declare(strict_types=1);

use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceCreator;
use App\Models\Title;
use App\Services\Citations\LandingPageCslItemMapperService;
use App\Services\DataCiteJsonExporter;
use App\Services\DataCiteXmlExporter;
use App\Services\Editor\EditorDataTransformer;
use App\Services\Iso19115\Iso19115XmlExporter;
use App\Services\LandingPageResourceTransformer;
use App\Services\OaiPmh\DublinCoreMapper;
use App\Services\SchemaOrgJsonLdExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('keeps two resource spellings for one ORCID consistent across human and machine outputs', function (): void {
    $person = Person::factory()->create([
        'given_name' => 'Philipp',
        'family_name' => 'Sommer',
        'name_identifier' => '0000-0001-6171-7716',
        'name_identifier_scheme' => 'ORCID',
        'scheme_uri' => 'https://orcid.org/',
    ]);
    $resourceWithInitial = Resource::factory()->withDoi('10.5880/gfz.1.4.2021.008')->create();
    $resourceWithoutInitial = Resource::factory()->withDoi('10.5880/gfz.1.4.2021.005')->create();
    Title::factory()->create(['resource_id' => $resourceWithInitial->id, 'value' => 'Initial snapshot']);
    Title::factory()->create(['resource_id' => $resourceWithoutInitial->id, 'value' => 'Global spelling']);

    ResourceCreator::factory()->create([
        'resource_id' => $resourceWithInitial->id,
        'creatorable_type' => Person::class,
        'creatorable_id' => $person->id,
        'position' => 1,
        'name_snapshot' => 'Sommer, Philipp S.',
        'given_name_snapshot' => 'Philipp S.',
        'family_name_snapshot' => 'Sommer',
    ]);
    ResourceCreator::factory()->create([
        'resource_id' => $resourceWithoutInitial->id,
        'creatorable_type' => Person::class,
        'creatorable_id' => $person->id,
        'position' => 1,
        'name_snapshot' => 'Sommer, Philipp',
        'given_name_snapshot' => 'Philipp',
        'family_name_snapshot' => 'Sommer',
    ]);

    $resourceWithInitial->refresh();
    $resourceWithoutInitial->refresh();
    $jsonWithInitial = (new DataCiteJsonExporter)->export($resourceWithInitial);
    $jsonWithoutInitial = (new DataCiteJsonExporter)->export($resourceWithoutInitial);

    expect(Person::query()->where('name_identifier', '0000-0001-6171-7716')->count())->toBe(1)
        ->and($person->fresh()->given_name)->toBe('Philipp')
        ->and($jsonWithInitial['data']['attributes']['creators'][0])->toMatchArray([
            'name' => 'Sommer, Philipp S.',
            'givenName' => 'Philipp S.',
            'familyName' => 'Sommer',
        ])->and($jsonWithoutInitial['data']['attributes']['creators'][0])->toMatchArray([
            'name' => 'Sommer, Philipp',
            'givenName' => 'Philipp',
            'familyName' => 'Sommer',
        ]);

    $xml = (new DataCiteXmlExporter)->export($resourceWithInitial->fresh());
    expect($xml)->toContain('<creatorName nameType="Personal">Sommer, Philipp S.</creatorName>')
        ->and($xml)->toContain('<givenName>Philipp S.</givenName>');

    $landingTransformer = new LandingPageResourceTransformer;
    $landingResource = $resourceWithInitial->fresh()->load($landingTransformer->requiredRelations());
    $landing = $landingTransformer->transform($landingResource);
    expect($landing['creators'][0]['creatorable'])->toMatchArray([
        'name' => 'Sommer, Philipp S.',
        'given_name' => 'Philipp S.',
        'family_name' => 'Sommer',
    ])->and((new EditorDataTransformer)->transformCreators($landingResource)['authors'][0])
        ->toMatchArray(['firstName' => 'Philipp S.', 'lastName' => 'Sommer'])
        ->and((new LandingPageCslItemMapperService)->map($landingResource)['author'][0])
        ->toBe(['family' => 'Sommer', 'given' => 'Philipp S.'])
        ->and((new DublinCoreMapper)->map($landingResource)['creator'][0])
        ->toBe('Sommer, Philipp S.');

    $schemaOrg = (new SchemaOrgJsonLdExporter)->export($landingResource);
    expect($schemaOrg['creator']['@list'][0])->toMatchArray([
        'familyName' => 'Sommer',
        'givenName' => 'Philipp S.',
    ]);

    $iso = app(Iso19115XmlExporter::class)->export($landingResource);
    expect($iso)->toContain('Sommer, Philipp S.');
});
