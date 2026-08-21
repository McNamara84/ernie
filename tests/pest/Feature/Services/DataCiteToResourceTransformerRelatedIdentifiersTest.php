<?php

declare(strict_types=1);

use App\Enums\CitationLabelResolutionMode;
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
    $citationService->shouldReceive('resolveBestEffortBatch')
        ->once()
        ->with(Mockery::type('array'), Mockery::type('float'))
        ->andReturnUsing(fn (array $relations): array => $relations);
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
    $citationService->shouldReceive('resolveBestEffortBatch')
        ->once()
        ->andReturnUsing(fn (array $relations): array => $relations);
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

it('persists all 17 citation labels from issue 1086 in stable order in exhaustive mode', function (): void {
    $dois = [
        '10.1186/s40645-023-00560-4',
        '10.1093/petrology/egac086',
        '10.1093/petrology/egae048',
        '10.1029/2023gc011409',
        '10.3389/feart.2020.554598',
        '10.1029/2021jb022331',
        '10.1093/petrology/egae057',
        '10.1016/j.chemgeo.2009.02.013',
        '10.1016/j.lithos.2018.01.012',
        '10.1016/j.tecto.2021.229001',
        '10.1016/j.epsl.2012.11.012',
        '10.2204/iodp.proc.304305.202.2009',
        '10.1016/j.gca.2014.06.012',
        '10.5880/fidgeo.2026.043',
        '10.1007/s00410-007-0210-z',
        '10.1093/petrology/egaa082',
        '10.1093/petrology/egab034',
    ];
    $citationService = Mockery::mock(RelatedIdentifierCitationLabelService::class);
    $citationService->shouldReceive('resolveExhaustive')
        ->once()
        ->with(Mockery::on(fn (array $relations): bool => array_column($relations, 'relatedIdentifier') === $dois))
        ->andReturnUsing(function (array $relations): array {
            foreach ($relations as $index => $relation) {
                $relations[$index]['citationLabel'] = 'Citation '.($index + 1).' for '.$relation['relatedIdentifier'];
            }

            return $relations;
        });
    $citationService->shouldNotReceive('resolveBestEffortBatch');
    $this->app->instance(RelatedIdentifierCitationLabelService::class, $citationService);

    $doiData = [
        'attributes' => [
            'doi' => '10.5880/fidgeo.2026.068',
            'publicationYear' => 2026,
            'titles' => [['title' => 'Issue 1086 regression fixture']],
            'creators' => [[
                'familyName' => 'Scarani',
                'givenName' => 'Sarah',
                'nameType' => 'Personal',
            ]],
            'relatedIdentifiers' => array_map(static fn (string $doi): array => [
                'relatedIdentifier' => $doi,
                'relatedIdentifierType' => 'DOI',
                'relationType' => 'Cites',
            ], $dois),
        ],
    ];

    $transformer = new DataCiteToResourceTransformer;
    $prepared = $transformer->prepareDoiData(
        $doiData,
        citationLabelResolutionMode: CitationLabelResolutionMode::EXHAUSTIVE,
    );

    expect($prepared['__citation_labels_prepared'])->toBe('exhaustive')
        ->and(array_column($prepared['attributes']['relatedIdentifiers'], 'citationLabel'))
        ->each->toBeString()->not->toBeEmpty();

    $resource = $transformer->transform($prepared, User::factory()->create()->id);
    $relations = $resource->relatedIdentifiers()->orderBy('position')->get();

    expect($relations)->toHaveCount(17)
        ->and($relations->pluck('identifier')->all())->toBe($dois)
        ->and($relations->pluck('citation_label')->all())->toBe(array_map(
            static fn (string $doi, int $index): string => 'Citation '.($index + 1).' for '.$doi,
            $dois,
            array_keys($dois),
        ));
});

it('upgrades best-effort prepared data before satisfying exhaustive mode', function (): void {
    $citationService = Mockery::mock(RelatedIdentifierCitationLabelService::class);
    $citationService->shouldReceive('resolveBestEffortBatch')
        ->once()
        ->andReturnUsing(fn (array $relations): array => $relations);
    $citationService->shouldReceive('resolveExhaustive')
        ->once()
        ->andReturnUsing(function (array $relations): array {
            $relations[0]['citationLabel'] = 'Strict citation';

            return $relations;
        });
    $this->app->instance(RelatedIdentifierCitationLabelService::class, $citationService);

    $transformer = new DataCiteToResourceTransformer;
    $bestEffort = $transformer->prepareDoiData([
        'attributes' => [
            'doi' => '10.5880/prepared-upgrade',
            'relatedIdentifiers' => [[
                'relatedIdentifier' => '10.1234/upgrade',
                'relatedIdentifierType' => 'DOI',
                'relationType' => 'Cites',
            ]],
        ],
    ]);
    $exhaustive = $transformer->prepareDoiData(
        $bestEffort,
        citationLabelResolutionMode: CitationLabelResolutionMode::EXHAUSTIVE,
    );
    $alreadyExhaustive = $transformer->prepareDoiData(
        $exhaustive,
        citationLabelResolutionMode: CitationLabelResolutionMode::EXHAUSTIVE,
    );

    expect($bestEffort['__citation_labels_prepared'])->toBe('best-effort')
        ->and($bestEffort['attributes']['relatedIdentifiers'][0])->not->toHaveKey('citationLabel')
        ->and($exhaustive['__citation_labels_prepared'])->toBe('exhaustive')
        ->and($exhaustive['attributes']['relatedIdentifiers'][0]['citationLabel'])->toBe('Strict citation')
        ->and($alreadyExhaustive)->toBe($exhaustive);
});

it('retains a valid unresolved DOI without a citation in exhaustive mode', function (): void {
    $citationService = Mockery::mock(RelatedIdentifierCitationLabelService::class);
    $citationService->shouldReceive('resolveExhaustive')
        ->once()
        ->andReturnUsing(fn (array $relations): array => $relations);
    $this->app->instance(RelatedIdentifierCitationLabelService::class, $citationService);

    $transformer = new DataCiteToResourceTransformer;
    $prepared = $transformer->prepareDoiData([
        'attributes' => [
            'doi' => '10.5880/tolerant-import',
            'publicationYear' => 2026,
            'titles' => [['title' => 'Tolerant citation import']],
            'creators' => [[
                'familyName' => 'Example',
                'givenName' => 'Erin',
                'nameType' => 'Personal',
            ]],
            'relatedIdentifiers' => [[
                'relatedIdentifier' => '10.1234/unavailable',
                'relatedIdentifierType' => 'DOI',
                'relationType' => 'Cites',
            ]],
        ],
    ], citationLabelResolutionMode: CitationLabelResolutionMode::EXHAUSTIVE);

    expect($prepared['attributes']['relatedIdentifiers'])->toBe([[
        'relatedIdentifier' => '10.1234/unavailable',
        'relatedIdentifierType' => 'DOI',
        'relationType' => 'Cites',
    ]]);

    $resource = $transformer->transform($prepared, User::factory()->create()->id);

    expect($resource->relatedIdentifiers()->sole())
        ->identifier->toBe('10.1234/unavailable')
        ->citation_label->toBeNull();
});

it('discards legacy DOI placeholders while retaining valid unresolved DOIs', function (): void {
    $citationService = Mockery::mock(RelatedIdentifierCitationLabelService::class);
    $citationService->shouldReceive('resolveExhaustive')
        ->once()
        ->with([[
            'relatedIdentifier' => '10.60510/ICDP5073EHG0001',
            'relatedIdentifierType' => 'DOI',
            'relationType' => 'HasPart',
        ]])
        ->andReturnUsing(fn (array $relations): array => $relations);
    $this->app->instance(RelatedIdentifierCitationLabelService::class, $citationService);

    $prepared = (new DataCiteToResourceTransformer)->prepareDoiData([
        'attributes' => [
            'doi' => '10.5880/ICDP.5073.001',
            'relatedIdentifiers' => [
                [
                    'relatedIdentifier' => 'DOI of paper when available',
                    'relatedIdentifierType' => 'DOI',
                    'relationType' => 'IsReferencedBy',
                ],
                [
                    'relatedIdentifier' => 'DOI of SD Article when available',
                    'relatedIdentifierType' => 'DOI',
                    'relationType' => 'IsReferencedBy',
                ],
                [
                    'relatedIdentifier' => '10.60510/ICDP5073EHG0001',
                    'relatedIdentifierType' => 'DOI',
                    'relationType' => 'HasPart',
                ],
            ],
        ],
    ], citationLabelResolutionMode: CitationLabelResolutionMode::EXHAUSTIVE);

    expect($prepared['attributes']['relatedIdentifiers'])->toBe([[
        'relatedIdentifier' => '10.60510/ICDP5073EHG0001',
        'relatedIdentifierType' => 'DOI',
        'relationType' => 'HasPart',
    ]]);
});
