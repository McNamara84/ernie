<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Citations\RelatedIdentifierCitationLabelService;
use App\Services\DataCiteToResourceTransformer;
use Database\Seeders\ContributorTypeSeeder;
use Database\Seeders\DescriptionTypeSeeder;
use Database\Seeders\IdentifierTypeSeeder;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\PublisherSeeder;
use Database\Seeders\RelationTypeSeeder;
use Database\Seeders\ResourceTypeSeeder;
use Database\Seeders\TitleTypeSeeder;

covers(DataCiteToResourceTransformer::class);

beforeEach(function (): void {
    test()->seed(ResourceTypeSeeder::class);
    test()->seed(TitleTypeSeeder::class);
    test()->seed(DescriptionTypeSeeder::class);
    test()->seed(ContributorTypeSeeder::class);
    test()->seed(IdentifierTypeSeeder::class);
    test()->seed(LanguageSeeder::class);
    test()->seed(PublisherSeeder::class);
    test()->seed(RelationTypeSeeder::class);
});

it('imports all related works from issue 1077 when JSON omits their identifiers', function (): void {
    $citationService = Mockery::mock(RelatedIdentifierCitationLabelService::class);
    $citationService->shouldReceive('resolveBestEffort')
        ->times(3)
        ->andReturnNull();
    $this->app->instance(RelatedIdentifierCitationLabelService::class, $citationService);

    $originalXml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<resource xmlns="http://datacite.org/schema/kernel-4">
  <relatedIdentifiers>
    <relatedIdentifier relatedIdentifierType="DOI" relationType="IsSupplementedBy" resourceTypeGeneral="Dataset">10.5880/CRC1211DB.86</relatedIdentifier>
    <relatedIdentifier relatedIdentifierType="DOI" relationType="IsSupplementedBy" resourceTypeGeneral="Dataset">10.5880/CRC1211DB.87</relatedIdentifier>
    <relatedIdentifier relatedIdentifierType="DOI" relationType="IsSupplementedBy" resourceTypeGeneral="Dataset">10.5880/CRC1211DB.89</relatedIdentifier>
  </relatedIdentifiers>
</resource>
XML;

    $doiData = [
        'attributes' => [
            'doi' => '10.5880/crc1211db.88',
            'publicationYear' => 2021,
            'titles' => [['title' => 'Issue 1077 regression fixture']],
            'creators' => [[
                'familyName' => 'Regression',
                'givenName' => 'Test',
                'nameType' => 'Personal',
            ]],
            'relatedIdentifiers' => array_fill(0, 3, [
                'relatedIdentifierType' => 'DOI',
                'relationType' => 'IsSupplementedBy',
                'resourceTypeGeneral' => 'Dataset',
            ]),
            'xml' => base64_encode($originalXml),
        ],
    ];

    $resource = (new DataCiteToResourceTransformer)->transform($doiData, User::factory()->create()->id);
    $relatedIdentifiers = $resource->relatedIdentifiers()
        ->with(['identifierType', 'relationType'])
        ->orderBy('position')
        ->get();

    expect($relatedIdentifiers)->toHaveCount(3)
        ->and($relatedIdentifiers->pluck('identifier')->all())->toBe([
            '10.5880/CRC1211DB.86',
            '10.5880/CRC1211DB.87',
            '10.5880/CRC1211DB.89',
        ])
        ->and($relatedIdentifiers->pluck('identifierType.slug')->unique()->values()->all())->toBe(['DOI'])
        ->and($relatedIdentifiers->pluck('relationType.slug')->unique()->values()->all())->toBe(['IsSupplementedBy'])
        ->and($relatedIdentifiers->pluck('resource_type_general')->unique()->values()->all())->toBe(['Dataset']);
});

it('supplements JSON with XML and legacy relations while preserving JSON values', function (): void {
    $citationService = Mockery::mock(RelatedIdentifierCitationLabelService::class);
    $citationService->shouldReceive('resolveBestEffort')->zeroOrMoreTimes()->andReturnNull();
    $this->app->instance(RelatedIdentifierCitationLabelService::class, $citationService);

    $xml = <<<'XML'
<resource xmlns="http://datacite.org/schema/kernel-4">
  <relatedIdentifiers>
    <relatedIdentifier relatedIdentifierType="DOI" relationType="Cites" resourceTypeGeneral="Dataset">10.5880/shared</relatedIdentifier>
    <relatedIdentifier relatedIdentifierType="DOI" relationType="References">10.5880/xml-only</relatedIdentifier>
  </relatedIdentifiers>
</resource>
XML;

    $prepared = (new DataCiteToResourceTransformer)->prepareDoiData([
        'attributes' => [
            'doi' => '10.5880/source-priority',
            'relatedIdentifiers' => [[
                'relatedIdentifier' => 'https://doi.org/10.5880/SHARED',
                'relatedIdentifierType' => 'DOI',
                'relationType' => 'Cites',
                'relationTypeInformation' => 'JSON wins',
            ]],
            'xml' => base64_encode($xml),
        ],
    ], [
        [
            'identifier' => 'doi:10.5880/shared',
            'identifierType' => 'DOI',
            'relationType' => 'Cites',
        ],
        [
            'identifier' => '10.5880/legacy-only',
            'identifierType' => 'DOI',
            'relationType' => 'IsCitedBy',
        ],
    ]);

    expect($prepared['attributes']['relatedIdentifiers'])->toHaveCount(3)
        ->and($prepared['attributes']['relatedIdentifiers'][0])->toMatchArray([
            'relatedIdentifier' => 'https://doi.org/10.5880/SHARED',
            'relationTypeInformation' => 'JSON wins',
            'resourceTypeGeneral' => 'Dataset',
        ])
        ->and(array_column($prepared['attributes']['relatedIdentifiers'], 'relatedIdentifier'))->toBe([
            'https://doi.org/10.5880/SHARED',
            '10.5880/xml-only',
            '10.5880/legacy-only',
        ]);
});
