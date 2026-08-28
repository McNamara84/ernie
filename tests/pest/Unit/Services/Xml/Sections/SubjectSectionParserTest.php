<?php

declare(strict_types=1);

use App\Services\Xml\Sections\SubjectSectionParser;
use App\Support\GemetVocabularyParser;
use Saloon\XmlWrangler\XmlReader;

covers(SubjectSectionParser::class);

it('parses each XML subject once through the shared import normalizer', function (): void {
    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<resource xmlns="http://datacite.org/schema/kernel-4">
  <subjects>
    <subject>Seismology</subject>
    <subject subjectScheme="GEMET - GEneral Multilingual Environmental Thesaurus"
             schemeURI="http://www.eionet.europa.eu/gemet/concept/"
             valueURI="http://www.eionet.europa.eu/gemet/concept/8493"
             xml:lang="de">HUMAN HEALTH &gt; pollution &gt; water pollution</subject>
    <subject subjectScheme="EPOS MSL vocabulary"
             valueURI="https://epos-msl.uu.nl/voc/rock">Material &gt; Rock</subject>
  </subjects>
</resource>
XML;

    $result = app(SubjectSectionParser::class)->parse(XmlReader::fromString($xml));

    expect($result->freeKeywords)->toBe(['Seismology'])
        ->and($result->controlledKeywords)->toHaveCount(2)
        ->and($result->controlledKeywords[0])->toMatchArray([
            'text' => 'water pollution',
            'path' => 'HUMAN HEALTH > pollution > water pollution',
            'language' => 'de',
            'scheme' => GemetVocabularyParser::SCHEME_TITLE,
        ])
        ->and($result->controlledKeywords[1])->toMatchArray([
            'text' => 'Rock',
            'scheme' => 'EPOS MSL vocabulary',
        ]);
});
