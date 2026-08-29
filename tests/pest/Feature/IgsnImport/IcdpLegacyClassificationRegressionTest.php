<?php

declare(strict_types=1);

use App\Enums\Igsn\IgsnClassificationType;
use App\Models\IgsnClassification;
use App\Models\IgsnMetadata;
use App\Models\Resource;
use App\Services\IgsnDifXmlParser;
use App\Services\LandingPageResourceTransformer;

uses()->group('igsn-import');

it('persists and exposes every ICDP legacy classification from issue 1210', function (): void {
    $values = [
        'MYL',
        'PROTOMYL',
        'QUAT',
        'SCH',
        'UND',
        'VOL',
        'cataclastic rocks',
        'fault related rocks',
        'igneous rocks',
        'metamorphic rocks',
        'mylonitic rocks',
        'protomylonites',
        'quaternary deposits, metamorphic rocks',
        'sample',
        'sedimentary rocks',
        'undefined',
        'volcanic rocks',
    ];
    /** @var Resource $resource */
    $resource = Resource::factory()->create();
    $metadata = IgsnMetadata::create(['resource_id' => $resource->id]);
    $records = implode('', array_map(
        static fn (string $value): string => sprintf(
            '<record><sample><material>Rock</material><classification>%s</classification></sample></record>',
            htmlspecialchars($value, ENT_XML1),
        ),
        $values,
    ));
    $xml = "<resource><supplementalMetadata>{$records}</supplementalMetadata></resource>";

    expect((new IgsnDifXmlParser)->enrichFromDifXml($xml, $resource, $metadata))->toBeTrue();

    $classifications = IgsnClassification::query()
        ->whereBelongsTo($resource)
        ->orderBy('position')
        ->get();
    expect($classifications->pluck('value')->all())->toBe($values)
        ->and($classifications->pluck('classification_type')->unique()->values()->all())
        ->toBe([IgsnClassificationType::ROCK]);

    $transformer = new LandingPageResourceTransformer;
    $landingResource = Resource::with($transformer->requiredRelations())->findOrFail($resource->id);
    $landingData = $transformer->transform($landingResource);

    expect(array_column($landingData['igsn_classifications'], 'value'))->toBe($values)
        ->and(array_unique(array_column($landingData['igsn_classifications'], 'classification_type')))
        ->toBe([IgsnClassificationType::ROCK->value]);
});
