<?php

declare(strict_types=1);

use App\Enums\Igsn\IgsnClassificationType;
use App\Models\IgsnClassification;
use App\Models\IgsnMetadata;
use App\Models\Resource;
use App\Services\IgsnDifXmlParser;
use App\Services\LandingPageResourceTransformer;

uses()->group('igsn-import', 'mysql-sensitive');

it('persists and exposes all Medusa legacy classifications from issue 1191', function (): void {
    $cases = [
        [
            'material' => 'Rock',
            'raw' => 'rock:bedrock igneous;rock:bedrock metamorphic;rock:skeleton',
            'values' => ['rock:bedrock igneous', 'rock:bedrock metamorphic', 'rock:skeleton'],
            'type' => IgsnClassificationType::ROCK,
        ],
        [
            'material' => 'Biology',
            'raw' => 'vegetation:bark;vegetation:branch;vegetation:leaves/needles;vegetation:litter bag;vegetation:stem;vegetation:twig;vegetation:wood',
            'values' => [
                'vegetation:bark',
                'vegetation:branch',
                'vegetation:leaves/needles',
                'vegetation:litter bag',
                'vegetation:stem',
                'vegetation:twig',
                'vegetation:wood',
            ],
            'type' => IgsnClassificationType::BIOLOGY,
        ],
    ];

    foreach ($cases as $case) {
        /** @var Resource $resource */
        $resource = Resource::factory()->create();
        $metadata = IgsnMetadata::create(['resource_id' => $resource->id]);
        $xml = <<<XML
        <resource><sample>
          <material>{$case['material']}</material>
          <classification>{$case['raw']}</classification>
        </sample></resource>
        XML;

        expect((new IgsnDifXmlParser)->enrichFromDifXml($xml, $resource, $metadata))->toBeTrue();

        $classifications = IgsnClassification::query()
            ->whereBelongsTo($resource)
            ->orderBy('position')
            ->get();
        expect($classifications->pluck('value')->all())->toBe($case['values'])
            ->and($classifications->pluck('classification_type')->unique()->values()->all())
            ->toBe([$case['type']]);

        $transformer = new LandingPageResourceTransformer;
        $landingResource = Resource::with($transformer->requiredRelations())->findOrFail($resource->id);
        $landingData = $transformer->transform($landingResource);

        expect(array_column($landingData['igsn_classifications'], 'value'))->toBe($case['values'])
            ->and(array_unique(array_column($landingData['igsn_classifications'], 'classification_type')))
            ->toBe([$case['type']->value]);
    }
});

it('fully enriches issue 1192 sediment records with a long Medusa user code', function (): void {
    /** @var Resource $resource */
    $resource = Resource::factory()->create();
    $metadata = IgsnMetadata::create(['resource_id' => $resource->id]);
    $userCode = 'COLD project / Climate Sensitivity of Glacial Landscape Dynamics / ERC-funded';
    $xml = <<<XML
    <resource><sample>
      <sample_type>Sediment</sample_type>
      <material>Sediment</material>
      <classification>sediment:other</classification>
      <user_code>{$userCode}</user_code>
      <sample_purpose>Climate sensitivity research</sample_purpose>
      <collection_method>Grab sampler</collection_method>
    </sample></resource>
    XML;

    expect((new IgsnDifXmlParser)->enrichFromDifXml($xml, $resource, $metadata))->toBeTrue();

    $persistedMetadata = $metadata->fresh();
    $classification = IgsnClassification::query()->whereBelongsTo($resource)->sole();
    expect($persistedMetadata->user_code)->toBe($userCode)
        ->and($persistedMetadata->sample_type)->toBe('Sediment')
        ->and($persistedMetadata->material)->toBe('Sediment')
        ->and($persistedMetadata->sample_purpose)->toBe('Climate sensitivity research')
        ->and($persistedMetadata->collection_method)->toBe('Grab sampler')
        ->and($classification->value)->toBe('sediment:other')
        ->and($classification->classification_type)->toBeNull();

    $transformer = new LandingPageResourceTransformer;
    $landingResource = Resource::with($transformer->requiredRelations())->findOrFail($resource->id);
    $landingData = $transformer->transform($landingResource);

    expect($landingData['igsn_metadata'])->toMatchArray([
        'user_code' => $userCode,
        'sample_type' => 'Sediment',
        'material' => 'Sediment',
        'sample_purpose' => 'Climate sensitivity research',
        'collection_method' => 'Grab sampler',
    ])->and($landingData['igsn_classifications'])->toBe([[
        'id' => $classification->id,
        'value' => 'sediment:other',
        'classification_type' => null,
    ]]);
});
