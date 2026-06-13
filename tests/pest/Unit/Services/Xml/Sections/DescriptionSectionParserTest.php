<?php

declare(strict_types=1);

use App\Services\Xml\Sections\DescriptionSectionParser;
use Saloon\XmlWrangler\XmlReader;

function parseDescriptions(string $xml, ?string $xmlContents = null): array
{
    return (new DescriptionSectionParser)->parse(XmlReader::fromString($xml), $xmlContents);
}

test('parses mixed description content from raw XML without losing surrounding text', function () {
    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<resource xmlns="http://datacite.org/schema/kernel-4">
  <descriptions>
    <description descriptionType="Abstract" xml:lang="en">
      First paragraph.<br/><br/>
      Second paragraph with <em>nested emphasis</em> and <custom>custom text</custom>.
    </description>
  </descriptions>
</resource>
XML;

    expect(parseDescriptions($xml, $xml))->toBe([
        [
            'type' => 'Abstract',
            'description' => "First paragraph.\n\nSecond paragraph with nested emphasis and custom text.",
            'language' => 'en',
        ],
    ]);
});

test('does not duplicate adjacent text nodes when parsing raw XML descriptions', function () {
    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<resource xmlns="http://datacite.org/schema/kernel-4">
  <descriptions>
    <description descriptionType="Abstract">Before <![CDATA[cdata content]]> after.</description>
  </descriptions>
</resource>
XML;

    expect(parseDescriptions($xml, $xml))->toBe([
        [
            'type' => 'Abstract',
            'description' => 'Before cdata content after.',
            'language' => null,
        ],
    ]);
});

test('filters empty descriptions when parsing from raw XML', function () {
    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<resource xmlns="http://datacite.org/schema/kernel-4">
  <descriptions>
    <description descriptionType="Methods"> <br/> <custom>   </custom> </description>
    <description>Useful fallback text.</description>
  </descriptions>
</resource>
XML;

    expect(parseDescriptions($xml, $xml))->toBe([
        [
            'type' => 'Other',
            'description' => 'Useful fallback text.',
            'language' => null,
        ],
    ]);
});

test('falls back to XmlReader parsing when raw XML is unavailable', function () {
    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<resource xmlns="http://datacite.org/schema/kernel-4">
  <descriptions>
    <description descriptionType="Methods" xml:lang="de">  Method text.  </description>
    <description>Other text.</description>
    <description descriptionType="TechnicalInfo">   </description>
  </descriptions>
</resource>
XML;

    expect(parseDescriptions($xml))->toBe([
        [
            'type' => 'Methods',
            'description' => 'Method text.',
            'language' => 'de',
        ],
        [
            'type' => 'Other',
            'description' => 'Other text.',
            'language' => null,
        ],
    ]);
});

test('falls back to XmlReader parsing when raw XML cannot be parsed', function () {
    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<resource xmlns="http://datacite.org/schema/kernel-4">
  <descriptions>
    <description descriptionType="Abstract">Fallback abstract.</description>
  </descriptions>
</resource>
XML;

    expect(parseDescriptions($xml, '<resource><descriptions>'))->toBe([
        [
            'type' => 'Abstract',
            'description' => 'Fallback abstract.',
            'language' => null,
        ],
    ]);
});

test('normalizes mixed XmlReader fallback content without creating empty descriptions', function () {
    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<resource xmlns="http://datacite.org/schema/kernel-4">
  <descriptions>
    <description descriptionType="Abstract"><br/></description>
    <description descriptionType="Methods"><br/><br/></description>
    <description><custom>Nested fallback text</custom></description>
  </descriptions>
</resource>
XML;

    expect(parseDescriptions($xml))->toBe([
        [
            'type' => 'Other',
            'description' => 'Nested fallback text',
            'language' => null,
        ],
    ]);
});
