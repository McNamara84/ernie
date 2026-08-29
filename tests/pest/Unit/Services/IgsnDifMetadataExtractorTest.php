<?php

use App\Enums\Igsn\IgsnClassificationType;
use App\Services\Igsn\IgsnDifMetadataExtractor;

covers(IgsnDifMetadataExtractor::class);

it('extracts the approved GFLMU0020 legacy metadata without persistence', function (): void {
    $xml = file_get_contents(base_path('tests/fixtures/igsn/gflmu0020.xml'));

    $metadata = (new IgsnDifMetadataExtractor)->extract($xml);

    expect($metadata)->not->toBeNull()
        ->and($metadata['scalars']['user_code'])->toBe('Resalt')
        ->and($metadata['scalars']['sample_type'])->toBe('Core')
        ->and($metadata['name'])->toBe('ODG_1B_1')
        ->and($metadata['parent_igsn'])->toBe('GFLMU0002')
        ->and($metadata['sample_access'])->toBe('Private')
        ->and($metadata['material_descriptions'])->toBe(['Granodiorite'])
        ->and($metadata['comments'])->toBe([])
        ->and($metadata['location']['pairs'])->toBe([
            ['latitude' => '49.6288', 'longitude' => '8.68799'],
            ['latitude' => '49.6344', 'longitude' => '8.69644'],
        ])
        ->and($metadata['sizes'])->toBe([
            ['numeric_value' => '50', 'unit' => 'mm', 'type' => 'diameter'],
            ['numeric_value' => '100', 'unit' => 'mm', 'type' => 'length'],
        ]);
});

it('prefers the corpus campaign spelling and normalizes empty placeholders', function (): void {
    $xml = <<<'XML'
    <resource>
      <sample>
        <cruise_field_prgrm>Preferred campaign</cruise_field_prgrm>
        <cruise_field_program>Compatibility campaign</cruise_field_program>
        <material>N/A</material>
        <sample_other_names>A; A; B</sample_other_names>
      </sample>
    </resource>
    XML;

    $metadata = (new IgsnDifMetadataExtractor)->extract($xml);

    expect($metadata['scalars']['cruise_field_program'])->toBe('Preferred campaign')
        ->and($metadata['scalars']['material'])->toBeNull()
        ->and($metadata['other_names'])->toBe(['A', 'B']);
});

it('returns null for malformed XML or XML without a sample', function (): void {
    $extractor = new IgsnDifMetadataExtractor;

    expect($extractor->extract('not xml'))->toBeNull()
        ->and($extractor->extract('<resource />'))->toBeNull();
});

it('separates deduplicated material descriptions from explicit sample comments', function (): void {
    $metadata = (new IgsnDifMetadataExtractor)->extract(<<<'XML'
    <resource>
      <description>Smell: None, sediment type: sandy</description>
      <sample>
        <material>Sediment</material>
        <descriptions>
          <description>Smell: None, sediment type: sandy</description>
        </descriptions>
        <sample_comment>Stored frozen after collection</sample_comment>
      </sample>
    </resource>
    XML);

    expect($metadata['description_groups'])->toBe([['entries' => [[
        'value' => 'Smell: None, sediment type: sandy',
        'scheme' => null,
    ]]]])
        ->and($metadata['material_descriptions'])->toBe(['Smell: None, sediment type: sandy'])
        ->and($metadata['comments'])->toBe(['Stored frozen after collection']);
});

it('preserves description schemes groups order and semicolons from DIF XML', function (): void {
    $metadata = (new IgsnDifMetadataExtractor)->extract(<<<'XML'
    <resource>
      <description>Flattened summary that must not be rendered</description>
      <sample>
        <material>Rock</material>
        <descriptions>
          <description>Core Oriented? 0; RQD Abundance: 0;</description>
          <description descriptionScheme="Rock Type">Musc-bio schist</description>
        </descriptions>
        <descriptions>
          <description>white</description>
          <description descriptionScheme="Rock Type">Quartzite</description>
        </descriptions>
        <locality_description>Near the northern drill site</locality_description>
      </sample>
    </resource>
    XML);

    expect($metadata['description_groups'])->toBe([
        ['entries' => [
            ['value' => 'Core Oriented? 0; RQD Abundance: 0;', 'scheme' => null],
            ['value' => 'Musc-bio schist', 'scheme' => 'Rock Type'],
        ]],
        ['entries' => [
            ['value' => 'white', 'scheme' => null],
            ['value' => 'Quartzite', 'scheme' => 'Rock Type'],
        ]],
    ])->and($metadata['material_descriptions'])->toBe([
        'Core Oriented? 0; RQD Abundance: 0;',
        'Musc-bio schist',
        'white',
        'Quartzite',
    ])->and($metadata['location']['locality_description'])->toBe('Near the northern drill site');
});

it('uses direct sample and root descriptions only as deduplicated fallback sources', function (): void {
    $metadata = (new IgsnDifMetadataExtractor)->extract(<<<'XML'
    <resource>
      <description>Same &amp; decoded</description>
      <description>Root only</description>
      <sample>
        <description>Same &amp; decoded</description>
        <description descriptionScheme="Kind">Sample only</description>
        <description descriptionScheme="Kind">Root only</description>
      </sample>
    </resource>
    XML);

    expect($metadata['description_groups'])->toBe([['entries' => [
        ['value' => 'Same & decoded', 'scheme' => null],
        ['value' => 'Sample only', 'scheme' => 'Kind'],
        ['value' => 'Root only', 'scheme' => 'Kind'],
    ]]]);
});

it('keeps equivalent entries when they belong to different source groups', function (): void {
    $fields = (new IgsnDifMetadataExtractor)->extractDescriptionFields(<<<'XML'
    <resource><sample>
      <material>unsupported but irrelevant to the targeted extraction</material>
      <descriptions><description>same</description></descriptions>
      <descriptions><description>same</description></descriptions>
    </sample></resource>
    XML);

    expect($fields['description_groups'])->toBe([
        ['entries' => [['value' => 'same', 'scheme' => null]]],
        ['entries' => [['value' => 'same', 'scheme' => null]]],
    ]);
});

it('canonicalizes the legacy not-applicable material spelling', function (): void {
    $metadata = (new IgsnDifMetadataExtractor)->extract(
        '<resource><sample><material>Not applicable</material></sample></resource>',
    );

    expect($metadata['scalars']['material'])->toBe('NotApplicable');
});

it('keeps valid legacy classifications and reports unsupported ones separately', function (): void {
    $metadata = (new IgsnDifMetadataExtractor)->extract(<<<'XML'
    <resource><sample>
      <material>Rock</material>
      <classification>igneous; legacy rock term; Igneous&gt;Volcanic</classification>
    </sample></resource>
    XML);

    expect($metadata['classifications'])->toBe([
        ['value' => 'Igneous', 'classification_type' => IgsnClassificationType::ROCK],
        ['value' => 'Igneous>Volcanic', 'classification_type' => IgsnClassificationType::ROCK],
    ])->and($metadata['rejected_classifications'])->toBe([[
        'value' => 'legacy rock term',
        'material' => 'Rock',
        'sample_index' => 0,
    ]]);
});

it('accepts every Medusa legacy classification from issue 1191', function (
    string $material,
    string $rawClassifications,
    array $expected,
): void {
    $metadata = (new IgsnDifMetadataExtractor)->extract(<<<XML
    <resource><sample>
      <material>{$material}</material>
      <classification>{$rawClassifications}</classification>
    </sample></resource>
    XML);

    expect(array_column($metadata['classifications'], 'value'))->toBe($expected)
        ->and(array_map(
            static fn (array $item): ?string => $item['classification_type']?->value,
            $metadata['classifications'],
        ))->toBe(array_fill(0, count($expected), strtolower($material)))
        ->and($metadata['rejected_classifications'])->toBe([]);
})->with([
    'rock legacy values' => [
        'Rock',
        'rock:bedrock igneous;rock:bedrock metamorphic;rock:skeleton',
        ['rock:bedrock igneous', 'rock:bedrock metamorphic', 'rock:skeleton'],
    ],
    'biology legacy values' => [
        'Biology',
        'vegetation:bark;vegetation:branch;vegetation:leaves/needles;vegetation:litter bag;vegetation:stem;vegetation:twig;vegetation:wood',
        [
            'vegetation:bark',
            'vegetation:branch',
            'vegetation:leaves/needles',
            'vegetation:litter bag',
            'vegetation:stem',
            'vegetation:twig',
            'vegetation:wood',
        ],
    ],
]);

it('accepts every Sonne273 and Earth Shape legacy classification from issues 1200 and 1202', function (
    string $material,
    string $rawClassifications,
    array $expected,
): void {
    $metadata = (new IgsnDifMetadataExtractor)->extract(<<<XML
    <resource><sample>
      <material>{$material}</material>
      <classification>{$rawClassifications}</classification>
    </sample></resource>
    XML);

    expect(array_column($metadata['classifications'], 'value'))->toBe($expected)
        ->and(array_map(
            static fn (array $item): ?string => $item['classification_type']?->value,
            $metadata['classifications'],
        ))->toBe(array_fill(0, count($expected), strtolower($material)))
        ->and($metadata['rejected_classifications'])->toBe([]);
})->with([
    'rock legacy values' => [
        'Rock',
        'Igneous&gt;Felsic;rock;rock:core stone;rock:crump',
        ['Igneous>Felsic', 'rock', 'rock:core stone', 'rock:crump'],
    ],
    'biology legacy values' => [
        'Biology',
        'vegetation;vegetation:leaf litter;vegetation:other;vegetation:other plant litter;vegetation:whole plant;vegetation:blossom;vegetation:lichen;vegetation:root',
        [
            'vegetation',
            'vegetation:leaf litter',
            'vegetation:other',
            'vegetation:other plant litter',
            'vegetation:whole plant',
            'vegetation:blossom',
            'vegetation:lichen',
            'vegetation:root',
        ],
    ],
]);

it('preserves repeated coordinate components so ordered polygon pairs stay aligned', function (): void {
    $metadata = (new IgsnDifMetadataExtractor)->extract(<<<'XML'
    <resource><sample>
      <latitude>1</latitude><longitude>2</longitude>
      <latitude>1</latitude><longitude>3</longitude>
      <latitude>4</latitude><longitude>5</longitude>
    </sample></resource>
    XML);

    expect($metadata['location']['pairs'])->toBe([
        ['latitude' => '1', 'longitude' => '2'],
        ['latitude' => '1', 'longitude' => '3'],
        ['latitude' => '4', 'longitude' => '5'],
    ]);
});

it('extracts ordered unique classifications from every sample with per-sample types', function (): void {
    $xml = <<<'XML'
    <resource>
      <supplementalMetadata>
        <record><sample>
          <material>Rock</material>
          <classification>fault related rocks; MYL; future class</classification>
        </sample></record>
        <record><sample>
          <material>Rock</material>
          <classification>FAULT RELATED ROCKS; metamorphic rocks; FUTURE CLASS</classification>
        </sample></record>
        <record><sample>
          <material>Biology</material>
          <classification>vegetation:bark</classification>
        </sample></record>
      </supplementalMetadata>
    </resource>
    XML;

    $extractor = new IgsnDifMetadataExtractor;
    $expected = [
        ['value' => 'fault related rocks', 'classification_type' => IgsnClassificationType::ROCK],
        ['value' => 'MYL', 'classification_type' => IgsnClassificationType::ROCK],
        ['value' => 'metamorphic rocks', 'classification_type' => IgsnClassificationType::ROCK],
        ['value' => 'vegetation:bark', 'classification_type' => IgsnClassificationType::BIOLOGY],
    ];
    $rejected = [[
        'value' => 'future class',
        'material' => 'Rock',
        'sample_index' => 0,
    ]];

    expect($extractor->extractClassificationFields($xml))->toBe([
        'items' => $expected,
        'rejected' => $rejected,
    ])->and($extractor->extract($xml)['classifications'])->toBe($expected)
        ->and($extractor->extract($xml)['rejected_classifications'])->toBe($rejected);
});

it('returns null from targeted classification extraction for malformed XML or missing samples', function (): void {
    $extractor = new IgsnDifMetadataExtractor;

    expect($extractor->extractClassificationFields('not xml'))->toBeNull()
        ->and($extractor->extractClassificationFields('<resource />'))->toBeNull();
});

it('reports classifications from a later unsupported material without losing valid samples', function (): void {
    $fields = (new IgsnDifMetadataExtractor)->extractClassificationFields(<<<'XML'
    <resource>
      <sample><material>Rock</material><classification>fault related rocks</classification></sample>
      <sample><material>Unsupported material</material><classification>unmapped value</classification></sample>
      <sample><material>Also unsupported but empty</material></sample>
    </resource>
    XML);

    expect($fields)->toBe([
        'items' => [[
            'value' => 'fault related rocks',
            'classification_type' => IgsnClassificationType::ROCK,
        ]],
        'rejected' => [[
            'value' => 'unmapped value',
            'material' => 'Unsupported material',
            'sample_index' => 1,
        ]],
    ]);
});

it('preserves repeated size values until they are paired with their labels', function (): void {
    $metadata = (new IgsnDifMetadataExtractor)->extract(<<<'XML'
    <resource><sample>
      <size>50;50;50</size>
      <size_unit>diameter [mm];length [mm];length [mm]</size_unit>
    </sample></resource>
    XML);

    expect($metadata['sizes'])->toBe([
        ['numeric_value' => '50', 'unit' => 'mm', 'type' => 'diameter'],
        ['numeric_value' => '50', 'unit' => 'mm', 'type' => 'length'],
    ]);
});

it('extracts image metadata only from the first sample block', function (): void {
    $extractor = new IgsnDifMetadataExtractor;
    $xml = <<<'XML'
    <resource>
      <sample><sample_image>first.jpg</sample_image><sample_image_path>https://example.test/first/</sample_image_path></sample>
      <sample><sample_image>second.jpg</sample_image><sample_image_path>https://example.test/second/</sample_image_path></sample>
    </resource>
    XML;

    expect($extractor->extractImageFields($xml))->toBe([
        'file_name' => 'first.jpg',
        'base_url' => 'https://example.test/first/',
    ])->and($extractor->extract($xml)['sample_image'])->toBe([
        'file_name' => 'first.jpg',
        'base_url' => 'https://example.test/first/',
    ]);
});

it('returns empty image fields for a sample without image metadata', function (): void {
    expect((new IgsnDifMetadataExtractor)->extractImageFields('<resource><sample /></resource>'))->toBe([
        'file_name' => null,
        'base_url' => null,
    ]);
});
