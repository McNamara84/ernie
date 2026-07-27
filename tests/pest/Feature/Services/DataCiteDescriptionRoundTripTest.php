<?php

use App\Models\Description;
use App\Models\DescriptionType;
use App\Models\Resource;
use App\Services\DataCiteJsonExporter;
use App\Services\DataCiteXmlExporter;
use App\Services\Xml\Sections\DescriptionSectionParser;
use Saloon\XmlWrangler\XmlReader;

test('preserves datacite breaks through import storage and both exports', function () {
    $sourceXml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<resource xmlns="http://datacite.org/schema/kernel-4">
  <descriptions>
    <description descriptionType="Abstract">First line.<br/><br/>Second line.</description>
  </descriptions>
</resource>
XML;

    $parsed = (new DescriptionSectionParser)->parse(
        XmlReader::fromString($sourceXml),
        $sourceXml
    );

    expect($parsed)->toHaveCount(1)
        ->and($parsed[0]['description'])->toBe("First line.\n\nSecond line.");

    $resource = Resource::factory()->create();
    $type = DescriptionType::firstOrCreate(
        ['slug' => $parsed[0]['type']],
        ['name' => $parsed[0]['type'], 'is_active' => true]
    );

    $description = Description::create([
        'resource_id' => $resource->id,
        'value' => $parsed[0]['description'],
        'description_type_id' => $type->id,
    ]);

    expect($description->fresh()->value)->toBe("First line.\n\nSecond line.");

    $json = (new DataCiteJsonExporter)->export($resource->fresh());
    expect($json['data']['attributes']['descriptions'][0]['description'])
        ->toBe('First line.<br><br>Second line.');

    $xml = (new DataCiteXmlExporter)->export($resource->fresh());
    $dom = new DOMDocument;
    $dom->loadXML($xml);

    expect($dom->getElementsByTagName('br')->length)->toBe(2)
        ->and($xml)->toContain('First line.<br/><br/>Second line.</description>')
        ->and($xml)->not->toContain('&lt;br&gt;');
});
