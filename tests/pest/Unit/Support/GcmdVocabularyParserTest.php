<?php

declare(strict_types=1);

use App\Support\GcmdVocabularyParser;

covers(GcmdVocabularyParser::class);

beforeEach(function (): void {
    $this->parser = new GcmdVocabularyParser;
});

// =========================================================================
// extractTotalHits
// =========================================================================

describe('extractTotalHits', function (): void {
    it('extracts total hits from valid RDF', function (): void {
        $rdf = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"
         xmlns:gcmd="https://gcmd.earthdata.nasa.gov/kms#">
    <gcmd:gcmd><gcmd:hits>42</gcmd:hits></gcmd:gcmd>
</rdf:RDF>
XML;

        expect($this->parser->extractTotalHits($rdf))->toBe(42);
    });

    it('returns 0 when hits element is missing', function (): void {
        $rdf = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"
         xmlns:gcmd="https://gcmd.earthdata.nasa.gov/kms#">
    <gcmd:gcmd></gcmd:gcmd>
</rdf:RDF>
XML;

        expect($this->parser->extractTotalHits($rdf))->toBe(0);
    });
});

// =========================================================================
// extractConcepts
// =========================================================================

describe('extractConcepts', function (): void {
    it('extracts concepts with prefLabel and broaderId', function (): void {
        $rdf = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"
         xmlns:skos="http://www.w3.org/2004/02/skos/core#">
    <skos:Concept rdf:about="https://gcmd.earthdata.nasa.gov/kms/concept/abc-123">
        <skos:prefLabel xml:lang="en">Earth Science</skos:prefLabel>
        <skos:definition>Study of Earth</skos:definition>
    </skos:Concept>
    <skos:Concept rdf:about="https://gcmd.earthdata.nasa.gov/kms/concept/def-456">
        <skos:prefLabel xml:lang="en">Earthquakes</skos:prefLabel>
        <skos:definition>Seismic events</skos:definition>
        <skos:broader rdf:resource="https://gcmd.earthdata.nasa.gov/kms/concept/abc-123"/>
    </skos:Concept>
</rdf:RDF>
XML;

        $result = $this->parser->extractConcepts($rdf);

        expect($result)->toHaveCount(2)
            ->and($result[0]['id'])->toBe('https://gcmd.earthdata.nasa.gov/kms/concept/abc-123')
            ->and($result[0]['text'])->toBe('Earth Science')
            ->and($result[0]['language'])->toBe('en')
            ->and($result[0]['description'])->toBe('Study of Earth')
            ->and($result[0]['broaderId'])->toBeNull()
            ->and($result[1]['broaderId'])->toBe('https://gcmd.earthdata.nasa.gov/kms/concept/abc-123');
    });

    it('converts UUID-only IDs to full URLs', function (): void {
        $rdf = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"
         xmlns:skos="http://www.w3.org/2004/02/skos/core#">
    <skos:Concept rdf:about="abc-123-uuid">
        <skos:prefLabel xml:lang="en">Test</skos:prefLabel>
    </skos:Concept>
</rdf:RDF>
XML;

        $result = $this->parser->extractConcepts($rdf);

        expect($result[0]['id'])->toBe('https://gcmd.earthdata.nasa.gov/kms/concept/abc-123-uuid');
    });

    it('converts UUID-only broaderIds to full URLs', function (): void {
        $rdf = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"
         xmlns:skos="http://www.w3.org/2004/02/skos/core#">
    <skos:Concept rdf:about="https://gcmd.earthdata.nasa.gov/kms/concept/child-1">
        <skos:prefLabel xml:lang="en">Child</skos:prefLabel>
        <skos:broader rdf:resource="parent-uuid"/>
    </skos:Concept>
</rdf:RDF>
XML;

        $result = $this->parser->extractConcepts($rdf);

        expect($result[0]['broaderId'])->toBe('https://gcmd.earthdata.nasa.gov/kms/concept/parent-uuid');
    });

    it('returns empty array when no concepts found', function (): void {
        $rdf = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"
         xmlns:skos="http://www.w3.org/2004/02/skos/core#">
</rdf:RDF>
XML;

        expect($this->parser->extractConcepts($rdf))->toBeEmpty();
    });
});

// =========================================================================
// buildHierarchy
// =========================================================================

describe('buildHierarchy', function (): void {
    it('builds hierarchical structure from flat concepts', function (): void {
        $concepts = [
            [
                'id' => 'https://gcmd.earthdata.nasa.gov/kms/concept/root-1',
                'text' => 'Root A',
                'language' => 'en',
                'description' => 'Root concept',
                'broaderId' => null,
            ],
            [
                'id' => 'https://gcmd.earthdata.nasa.gov/kms/concept/child-1',
                'text' => 'Child A1',
                'language' => 'en',
                'description' => 'Child concept',
                'broaderId' => 'https://gcmd.earthdata.nasa.gov/kms/concept/root-1',
            ],
        ];

        $result = $this->parser->buildHierarchy($concepts, 'Test Scheme', 'https://example.com/scheme');

        expect($result)->toHaveKeys(['lastUpdated', 'data'])
            ->and($result['data'])->toHaveCount(1)
            ->and($result['data'][0]['text'])->toBe('Root A')
            ->and($result['data'][0]['scheme'])->toBe('Test Scheme')
            ->and($result['data'][0]['schemeURI'])->toBe('https://example.com/scheme')
            ->and($result['data'][0]['children'])->toHaveCount(1)
            ->and($result['data'][0]['children'][0]['text'])->toBe('Child A1');
    });

    it('skips concepts without valid IDs', function (): void {
        $concepts = [
            [
                'id' => '',
                'text' => 'Bogus',
                'language' => 'en',
                'description' => '',
                'broaderId' => null,
            ],
            [
                'id' => null,
                'text' => 'Also Bogus',
                'language' => 'en',
                'description' => '',
                'broaderId' => null,
            ],
        ];

        $result = $this->parser->buildHierarchy($concepts, 'Test', 'https://example.com');

        expect($result['data'])->toBeEmpty();
    });

    it('handles multi-level nesting', function (): void {
        $concepts = [
            ['id' => 'root', 'text' => 'Root', 'language' => 'en', 'description' => '', 'broaderId' => null],
            ['id' => 'child', 'text' => 'Child', 'language' => 'en', 'description' => '', 'broaderId' => 'root'],
            ['id' => 'grandchild', 'text' => 'Grandchild', 'language' => 'en', 'description' => '', 'broaderId' => 'child'],
        ];

        $result = $this->parser->buildHierarchy($concepts, 'Test', 'https://example.com');

        expect($result['data'])->toHaveCount(1)
            ->and($result['data'][0]['children'])->toHaveCount(1)
            ->and($result['data'][0]['children'][0]['children'])->toHaveCount(1)
            ->and($result['data'][0]['children'][0]['children'][0]['text'])->toBe('Grandchild');
    });
});
