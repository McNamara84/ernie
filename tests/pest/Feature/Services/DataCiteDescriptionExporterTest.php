<?php

use App\Models\Description;
use App\Models\DescriptionType;
use App\Models\Resource;
use App\Services\DataCiteJsonExporter;
use App\Services\DataCiteXmlExporter;

test('exports line breaks for every datacite description type', function () {
    $resource = Resource::factory()->create();
    $typeNames = [
        'Abstract',
        'Methods',
        'SeriesInformation',
        'TableOfContents',
        'TechnicalInfo',
        'Other',
    ];

    foreach ($typeNames as $position => $typeName) {
        $type = DescriptionType::firstOrCreate(
            ['slug' => $typeName],
            ['name' => $typeName, 'is_active' => true]
        );

        Description::create([
            'resource_id' => $resource->id,
            'value' => $typeName.' first'.PHP_EOL.$typeName.' second',
            'description_type_id' => $type->id,
            'position' => $position,
        ]);
    }

    $json = (new DataCiteJsonExporter)->export($resource->fresh());
    $jsonDescriptions = collect($json['data']['attributes']['descriptions'])
        ->keyBy('descriptionType');

    expect($jsonDescriptions)->toHaveCount(6);

    foreach ($typeNames as $typeName) {
        expect($jsonDescriptions[$typeName]['description'])
            ->toBe($typeName.' first<br>'.$typeName.' second');
    }

    $xml = (new DataCiteXmlExporter)->export($resource->fresh());
    $dom = new DOMDocument;
    $dom->loadXML($xml);
    $xmlDescriptions = $dom->getElementsByTagName('description');

    expect($xmlDescriptions->length)->toBe(6);

    foreach ($xmlDescriptions as $xmlDescription) {
        $typeName = $xmlDescription->getAttribute('descriptionType');

        expect($xmlDescription->getElementsByTagName('br')->length)->toBe(1)
            ->and($xmlDescription->textContent)->toBe($typeName.' first'.$typeName.' second');
    }
});
