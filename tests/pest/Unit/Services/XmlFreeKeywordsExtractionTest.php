<?php

use App\Support\XmlKeywordExtractor;
use Saloon\XmlWrangler\XmlReader;

describe('XmlKeywordExtractor - Free Keywords Extraction', function () {
    it('extracts free keywords from subjects without schema attributes', function () {
        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<resource xmlns="http://datacite.org/schema/kernel-4">
    <subjects>
        <subject>climate change</subject>
        <subject>temperature</subject>
        <subject>precipitation</subject>
    </subjects>
</resource>
XML;

        $reader = XmlReader::fromString($xml);
        $extractor = new XmlKeywordExtractor();
        
        $result = $extractor->extractFreeKeywords($reader);
        
        expect($result)->toBe([
            'climate change',
            'temperature',
            'precipitation',
        ]);
    });

    it('excludes subjects with subjectScheme attribute', function () {
        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<resource xmlns="http://datacite.org/schema/kernel-4">
    <subjects>
        <subject>free keyword</subject>
        <subject subjectScheme="GCMD">Controlled Keyword</subject>
        <subject>another free keyword</subject>
    </subjects>
</resource>
XML;

        $reader = XmlReader::fromString($xml);
        $extractor = new XmlKeywordExtractor();
        
        $result = $extractor->extractFreeKeywords($reader);
        
        expect($result)->toBe([
            'free keyword',
            'another free keyword',
        ]);
    });

    it('excludes subjects with schemeURI attribute', function () {
        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<resource xmlns="http://datacite.org/schema/kernel-4">
    <subjects>
        <subject>free keyword</subject>
        <subject schemeURI="https://gcmd.earthdata.nasa.gov/">GCMD Keyword</subject>
    </subjects>
</resource>
XML;

        $reader = XmlReader::fromString($xml);
        $extractor = new XmlKeywordExtractor();
        
        $result = $extractor->extractFreeKeywords($reader);
        
        expect($result)->toBe(['free keyword']);
    });

    it('excludes subjects with valueURI attribute', function () {
        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<resource xmlns="http://datacite.org/schema/kernel-4">
    <subjects>
        <subject>free keyword</subject>
        <subject valueURI="https://example.org/vocab/123">Linked Keyword</subject>
    </subjects>
</resource>
XML;

        $reader = XmlReader::fromString($xml);
        $extractor = new XmlKeywordExtractor();
        
        $result = $extractor->extractFreeKeywords($reader);
        
        expect($result)->toBe(['free keyword']);
    });

    it('trims whitespace from keywords', function () {
        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<resource xmlns="http://datacite.org/schema/kernel-4">
    <subjects>
        <subject>  keyword with spaces  </subject>
        <subject>
            keyword with newlines
        </subject>
    </subjects>
</resource>
XML;

        $reader = XmlReader::fromString($xml);
        $extractor = new XmlKeywordExtractor();
        
        $result = $extractor->extractFreeKeywords($reader);
        
        expect($result)->toHaveCount(2);
        expect($result[0])->toBe('keyword with spaces');
        expect($result[1])->toBe('keyword with newlines');
    });

    it('skips empty subject elements', function () {
        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<resource xmlns="http://datacite.org/schema/kernel-4">
    <subjects>
        <subject>valid keyword</subject>
        <subject></subject>
        <subject>   </subject>
        <subject>another valid keyword</subject>
    </subjects>
</resource>
XML;

        $reader = XmlReader::fromString($xml);
        $extractor = new XmlKeywordExtractor();
        
        $result = $extractor->extractFreeKeywords($reader);
        
        expect($result)->toBe([
            'valid keyword',
            'another valid keyword',
        ]);
    });

    it('preserves mixed case keywords', function () {
        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<resource xmlns="http://datacite.org/schema/kernel-4">
    <subjects>
        <subject>InSAR</subject>
        <subject>GNSS</subject>
        <subject>CO2 storage</subject>
        <subject>pH Level</subject>
    </subjects>
</resource>
XML;

        $reader = XmlReader::fromString($xml);
        $extractor = new XmlKeywordExtractor();
        
        $result = $extractor->extractFreeKeywords($reader);
        
        expect($result)->toBe([
            'InSAR',
            'GNSS',
            'CO2 storage',
            'pH Level',
        ]);
    });

    it('returns empty array when no subjects exist', function () {
        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<resource xmlns="http://datacite.org/schema/kernel-4">
    <subjects>
    </subjects>
</resource>
XML;

        $reader = XmlReader::fromString($xml);
        $extractor = new XmlKeywordExtractor();
        
        $result = $extractor->extractFreeKeywords($reader);
        
        expect($result)->toBe([]);
    });

    it('returns empty array when all subjects have schema attributes', function () {
        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<resource xmlns="http://datacite.org/schema/kernel-4">
    <subjects>
        <subject subjectScheme="GCMD">Keyword 1</subject>
        <subject schemeURI="https://gcmd.earthdata.nasa.gov/">Keyword 2</subject>
        <subject valueURI="https://example.org/vocab/123">Keyword 3</subject>
    </subjects>
</resource>
XML;

        $reader = XmlReader::fromString($xml);
        $extractor = new XmlKeywordExtractor();
        
        $result = $extractor->extractFreeKeywords($reader);
        
        expect($result)->toBe([]);
    });

    it('handles complex mixed scenario with free and controlled keywords', function () {
        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<resource xmlns="http://datacite.org/schema/kernel-4">
    <subjects>
        <subject>climate change</subject>
        <subject subjectScheme="GCMD" schemeURI="https://gcmd.earthdata.nasa.gov/" valueURI="https://gcmd.earthdata.nasa.gov/kms/concept/a7558f90-6c61-4673-8d66-6185c0654cd1">
            EARTH SCIENCE &gt; ATMOSPHERE &gt; ATMOSPHERIC TEMPERATURE &gt; SURFACE TEMPERATURE &gt; AIR TEMPERATURE
        </subject>
        <subject>temperature</subject>
        <subject subjectScheme="GCMD">PRECIPITATION</subject>
        <subject>   </subject>
        <subject>InSAR</subject>
    </subjects>
</resource>
XML;

        $reader = XmlReader::fromString($xml);
        $extractor = new XmlKeywordExtractor();
        
        $result = $extractor->extractFreeKeywords($reader);
        
        expect($result)->toBe([
            'climate change',
            'temperature',
            'InSAR',
        ]);
    });
});

describe('XmlKeywordExtractor - GCMD Path Parsing', function () {
    it('parses path with Science Keywords prefix', function () {
        $input = 'Science Keywords > EARTH SCIENCE > ATMOSPHERE > CLOUDS';
        $result = \App\Support\XmlKeywordExtractor::parseGcmdPath($input);
        
        expect($result)->toBe(['EARTH SCIENCE', 'ATMOSPHERE', 'CLOUDS']);
    });

    it('parses path with Platforms prefix', function () {
        $input = 'Platforms > EARTH OBSERVATION SATELLITES > ENVISAT';
        $result = \App\Support\XmlKeywordExtractor::parseGcmdPath($input);
        
        expect($result)->toBe(['EARTH OBSERVATION SATELLITES', 'ENVISAT']);
    });

    it('parses path with Instruments prefix', function () {
        $input = 'Instruments > ACTIVE REMOTE SENSING > ALTIMETERS > LIDAR';
        $result = \App\Support\XmlKeywordExtractor::parseGcmdPath($input);
        
        expect($result)->toBe(['ACTIVE REMOTE SENSING', 'ALTIMETERS', 'LIDAR']);
    });

    it('parses path without GCMD prefix', function () {
        $input = 'EARTH SCIENCE > ATMOSPHERE > TEMPERATURE';
        $result = \App\Support\XmlKeywordExtractor::parseGcmdPath($input);
        
        expect($result)->toBe(['EARTH SCIENCE', 'ATMOSPHERE', 'TEMPERATURE']);
    });

    it('handles single-level path', function () {
        $input = 'EARTH SCIENCE';
        $result = \App\Support\XmlKeywordExtractor::parseGcmdPath($input);
        
        expect($result)->toBe(['EARTH SCIENCE']);
    });

    it('trims whitespace from path segments', function () {
        $input = 'Science Keywords >  EARTH SCIENCE  >  ATMOSPHERE  >  CLOUDS  ';
        $result = \App\Support\XmlKeywordExtractor::parseGcmdPath($input);
        
        expect($result)->toBe(['EARTH SCIENCE', 'ATMOSPHERE', 'CLOUDS']);
    });

    it('is case-insensitive for prefix matching', function () {
        $input = 'science keywords > EARTH SCIENCE > ATMOSPHERE';
        $result = \App\Support\XmlKeywordExtractor::parseGcmdPath($input);
        
        expect($result)->toBe(['EARTH SCIENCE', 'ATMOSPHERE']);
    });
});
