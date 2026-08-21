<?php

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

    expect($metadata['material_descriptions'])->toBe(['Smell: None, sediment type: sandy'])
        ->and($metadata['comments'])->toBe(['Stored frozen after collection']);
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

    expect($metadata['classifications'])->toBe(['Igneous', 'Igneous>Volcanic'])
        ->and($metadata['rejected_classifications'])->toBe(['legacy rock term']);
});

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
