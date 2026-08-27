<?php

declare(strict_types=1);

use App\Enums\Igsn\IgsnClassificationType;
use App\Models\IgsnClassification;
use App\Models\IgsnMetadata;
use App\Models\Resource;
use App\Services\IgsnDifXmlParser;
use App\Services\LandingPageResourceTransformer;

uses()->group('igsn-import');

it('persists and exposes every legacy classification from issues 1200 and 1202', function (): void {
    $cases = [
        [
            'material' => 'Rock',
            'raw' => 'Igneous&gt;Felsic;rock;rock:core stone;rock:crump',
            'values' => ['Igneous>Felsic', 'rock', 'rock:core stone', 'rock:crump'],
            'type' => IgsnClassificationType::ROCK,
        ],
        [
            'material' => 'Biology',
            'raw' => 'vegetation;vegetation:leaf litter;vegetation:other;vegetation:other plant litter;vegetation:whole plant;vegetation:blossom;vegetation:lichen;vegetation:root',
            'values' => [
                'vegetation',
                'vegetation:leaf litter',
                'vegetation:other',
                'vegetation:other plant litter',
                'vegetation:whole plant',
                'vegetation:blossom',
                'vegetation:lichen',
                'vegetation:root',
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

it('still rejects an unknown controlled classification without rolling back valid DIF metadata', function (): void {
    /** @var Resource $resource */
    $resource = Resource::factory()->create();
    $metadata = IgsnMetadata::create(['resource_id' => $resource->id]);
    $xml = <<<'XML'
    <resource><sample>
      <material>Rock</material>
      <classification>Igneous&gt;Felsic;unknown future rock class</classification>
      <sample_purpose>Legacy migration regression test</sample_purpose>
    </sample></resource>
    XML;

    expect((new IgsnDifXmlParser)->enrichFromDifXml($xml, $resource, $metadata))->toBeTrue()
        ->and($metadata->fresh()->sample_purpose)->toBe('Legacy migration regression test')
        ->and(IgsnClassification::query()->whereBelongsTo($resource)->pluck('value')->all())
        ->toBe(['Igneous>Felsic']);
});
