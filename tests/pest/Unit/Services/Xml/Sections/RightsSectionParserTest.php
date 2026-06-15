<?php

declare(strict_types=1);

use App\Services\Xml\Sections\RightsSectionParser;
use Saloon\XmlWrangler\XmlReader;

covers(RightsSectionParser::class);

it('extracts legacy license identifiers from rights nodes', function (): void {
    $xml = <<<'XML'
<resource xmlns="http://datacite.org/schema/kernel-4" xmlns:xml="http://www.w3.org/XML/1998/namespace">
    <rightsList>
        <rights rightsIdentifier="CC-BY-4.0" rightsIdentifierScheme="SPDX" schemeURI="https://spdx.org/licenses/">CC BY 4.0</rights>
        <rights rightsURI="https://example.test/custom-license">Custom license</rights>
        <rights rightsIdentifier="  ">Whitespace identifier</rights>
    </rightsList>
</resource>
XML;

    $identifiers = (new RightsSectionParser)->parse(XmlReader::fromString($xml));

    expect($identifiers)->toBe(['CC-BY-4.0']);
});

it('keeps raw DataCite rights statement details and skips empty nodes', function (): void {
    $xml = <<<'XML'
<resource xmlns="http://datacite.org/schema/kernel-4" xmlns:xml="http://www.w3.org/XML/1998/namespace">
    <rightsList>
        <rights rightsURI=" http://creativecommons.org/licenses/by/4.0 " rightsIdentifier=" CC-BY-4.0 " rightsIdentifierScheme=" SPDX " schemeURI=" https://spdx.org/licenses/ " xml:lang=" en "> CC BY 4.0 </rights>
        <rights>   </rights>
    </rightsList>
</resource>
XML;

    $rawRights = (new RightsSectionParser)->parseRawRights(XmlReader::fromString($xml));

    expect($rawRights)->toBe([
        [
            'rights' => 'CC BY 4.0',
            'rightsUri' => 'http://creativecommons.org/licenses/by/4.0',
            'rightsIdentifier' => 'CC-BY-4.0',
            'rightsIdentifierScheme' => 'SPDX',
            'schemeUri' => 'https://spdx.org/licenses/',
            'lang' => 'en',
            'source' => 'xml-upload',
        ],
    ]);
});
