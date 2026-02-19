<?php

declare(strict_types=1);

use App\Support\XmlKeywordExtractor;
use Saloon\XmlWrangler\XmlReader;

covers(XmlKeywordExtractor::class);

beforeEach(function () {
    $this->extractor = new XmlKeywordExtractor;
});

/**
 * Wrap subjects XML in a DataCite resource envelope.
 */
function wrapInDataCite(string $subjectsXml): string
{
    return <<<XML
    <?xml version="1.0" encoding="UTF-8"?>
    <resource xmlns="http://datacite.org/schema/kernel-4"
              xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
        <subjects>
            {$subjectsXml}
        </subjects>
    </resource>
    XML;
}

// =========================================================================
// extractFreeKeywords()
// =========================================================================

describe('extractFreeKeywords', function () {
    it('extracts subjects without scheme attributes', function () {
        $xml = wrapInDataCite(
            '<subject>Seismology</subject><subject>Volcanology</subject>'
        );
        $reader = XmlReader::fromString($xml);

        $result = $this->extractor->extractFreeKeywords($reader);

        expect($result)->toBe(['Seismology', 'Volcanology']);
    });

    it('ignores subjects with subjectScheme attribute', function () {
        $xml = wrapInDataCite(
            '<subject>Free Keyword</subject>'
            .'<subject subjectScheme="GCMD" schemeURI="https://gcmd.nasa.gov">EARTH SCIENCE</subject>'
        );
        $reader = XmlReader::fromString($xml);

        $result = $this->extractor->extractFreeKeywords($reader);

        expect($result)->toBe(['Free Keyword']);
    });

    it('skips empty subjects', function () {
        $xml = wrapInDataCite(
            '<subject>Valid</subject><subject>   </subject><subject></subject>'
        );
        $reader = XmlReader::fromString($xml);

        $result = $this->extractor->extractFreeKeywords($reader);

        expect($result)->toBe(['Valid']);
    });

    it('returns empty array when no free keywords exist', function () {
        $xml = wrapInDataCite(
            '<subject subjectScheme="GCMD">EARTH SCIENCE</subject>'
        );
        $reader = XmlReader::fromString($xml);

        $result = $this->extractor->extractFreeKeywords($reader);

        expect($result)->toBeEmpty();
    });

    it('trims whitespace from keywords', function () {
        $xml = wrapInDataCite(
            '<subject>  Trimmed Keyword  </subject>'
        );
        $reader = XmlReader::fromString($xml);

        $result = $this->extractor->extractFreeKeywords($reader);

        expect($result)->toBe(['Trimmed Keyword']);
    });
});

// =========================================================================
// extractMslKeywords()
// =========================================================================

describe('extractMslKeywords', function () {
    it('extracts MSL vocabulary keywords', function () {
        $xml = wrapInDataCite(
            '<subject subjectScheme="EPOS MSL vocabulary" schemeURI="https://epos-msl.uu.nl/voc" '
            .'valueURI="https://epos-msl.uu.nl/voc/material/1.3/coal">Material > sedimentary rock > coal</subject>'
        );
        $reader = XmlReader::fromString($xml);

        $result = $this->extractor->extractMslKeywords($reader);

        expect($result)->toHaveCount(1)
            ->and($result[0]['id'])->toBe('https://epos-msl.uu.nl/voc/material/1.3/coal')
            ->and($result[0]['text'])->toBe('coal')
            ->and($result[0]['path'])->toBe('Material > sedimentary rock > coal')
            ->and($result[0]['scheme'])->toBe('EPOS MSL vocabulary');
    });

    it('ignores non-MSL subjects', function () {
        $xml = wrapInDataCite(
            '<subject>Free keyword</subject>'
            .'<subject subjectScheme="GCMD" valueURI="https://gcmd.nasa.gov/x">EARTH SCIENCE</subject>'
        );
        $reader = XmlReader::fromString($xml);

        $result = $this->extractor->extractMslKeywords($reader);

        expect($result)->toBeEmpty();
    });

    it('skips MSL entries without valueURI', function () {
        $xml = wrapInDataCite(
            '<subject subjectScheme="EPOS MSL vocabulary" schemeURI="https://epos-msl.uu.nl/voc">'
            .'Material > rock</subject>'
        );
        $reader = XmlReader::fromString($xml);

        $result = $this->extractor->extractMslKeywords($reader);

        expect($result)->toBeEmpty();
    });

    it('uses default language "en" when xml:lang is missing', function () {
        $xml = wrapInDataCite(
            '<subject subjectScheme="EPOS MSL vocabulary" schemeURI="https://epos-msl.uu.nl/voc" '
            .'valueURI="https://example.com/1">Rock</subject>'
        );
        $reader = XmlReader::fromString($xml);

        $result = $this->extractor->extractMslKeywords($reader);

        expect($result[0]['language'])->toBe('en');
    });
});

// =========================================================================
// parseGcmdPath() (static)
// =========================================================================

describe('parseGcmdPath', function () {
    it('removes "Science Keywords > " prefix and splits', function () {
        $result = XmlKeywordExtractor::parseGcmdPath('Science Keywords > EARTH SCIENCE > ATMOSPHERE > CLOUDS');

        expect($result)->toBe(['EARTH SCIENCE', 'ATMOSPHERE', 'CLOUDS']);
    });

    it('removes "Platforms > " prefix', function () {
        $result = XmlKeywordExtractor::parseGcmdPath('Platforms > LAND-BASED > FIELD SITE');

        expect($result)->toBe(['LAND-BASED', 'FIELD SITE']);
    });

    it('removes "Instruments > " prefix', function () {
        $result = XmlKeywordExtractor::parseGcmdPath('Instruments > SEISMOMETERS > BROADBAND');

        expect($result)->toBe(['SEISMOMETERS', 'BROADBAND']);
    });

    it('handles paths without known prefix', function () {
        $result = XmlKeywordExtractor::parseGcmdPath('FOO > BAR > BAZ');

        expect($result)->toBe(['FOO', 'BAR', 'BAZ']);
    });

    it('handles single-segment path', function () {
        $result = XmlKeywordExtractor::parseGcmdPath('EARTH SCIENCE');

        expect($result)->toBe(['EARTH SCIENCE']);
    });

    it('trims whitespace from segments', function () {
        $result = XmlKeywordExtractor::parseGcmdPath('  FOO  >  BAR  ');

        expect($result)->toBe(['FOO', 'BAR']);
    });
});
