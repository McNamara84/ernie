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
        ->and($metadata['comments'])->toBe(['Granodiorite'])
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
