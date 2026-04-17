<?php

declare(strict_types=1);

use App\Support\EuroSciVocParser;

covers(EuroSciVocParser::class);

beforeEach(function (): void {
    $this->parser = new EuroSciVocParser;
    $this->conceptSchemeUri = 'http://data.europa.eu/8mn/euroscivoc/test-scheme';
});

// =========================================================================
// extractConcepts
// =========================================================================

describe('extractConcepts', function (): void {
    it('extracts concepts with SKOS-XL labels', function (): void {
        $rdf = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"
         xmlns:skos="http://www.w3.org/2004/02/skos/core#"
         xmlns:skosxl="http://www.w3.org/2008/05/skos-xl#"
         xml:lang="en">
    <skosxl:Label rdf:about="http://example.org/label/1">
        <skosxl:literalForm xml:lang="en">natural sciences</skosxl:literalForm>
        <skosxl:literalForm xml:lang="de">Naturwissenschaften</skosxl:literalForm>
    </skosxl:Label>
    <skosxl:Label rdf:about="http://example.org/label/2">
        <skosxl:literalForm xml:lang="en">physics</skosxl:literalForm>
    </skosxl:Label>
    <skos:Concept rdf:about="http://example.org/concept/1">
        <skos:inScheme rdf:resource="http://data.europa.eu/8mn/euroscivoc/test-scheme"/>
        <skos:topConceptOf rdf:resource="http://data.europa.eu/8mn/euroscivoc/test-scheme"/>
        <skosxl:prefLabel rdf:resource="http://example.org/label/1"/>
    </skos:Concept>
    <skos:Concept rdf:about="http://example.org/concept/2">
        <skos:inScheme rdf:resource="http://data.europa.eu/8mn/euroscivoc/test-scheme"/>
        <skos:broader rdf:resource="http://example.org/concept/1"/>
        <skosxl:prefLabel rdf:resource="http://example.org/label/2"/>
    </skos:Concept>
</rdf:RDF>
XML;

        $concepts = $this->parser->extractConcepts($rdf, $this->conceptSchemeUri);

        expect($concepts)->toHaveCount(2)
            ->and($concepts[0])->toMatchArray([
                'id' => 'http://example.org/concept/1',
                'text' => 'natural sciences',
                'language' => 'en',
                'isTopConcept' => true,
                'broaderId' => null,
            ])
            ->and($concepts[1])->toMatchArray([
                'id' => 'http://example.org/concept/2',
                'text' => 'physics',
                'language' => 'en',
                'isTopConcept' => false,
                'broaderId' => 'http://example.org/concept/1',
            ]);
    });

    it('falls back to plain SKOS prefLabel when no SKOS-XL labels exist', function (): void {
        $rdf = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"
         xmlns:skos="http://www.w3.org/2004/02/skos/core#">
    <skos:Concept rdf:about="http://example.org/concept/1">
        <skos:inScheme rdf:resource="http://data.europa.eu/8mn/euroscivoc/test-scheme"/>
        <skos:topConceptOf rdf:resource="http://data.europa.eu/8mn/euroscivoc/test-scheme"/>
        <skos:prefLabel xml:lang="en">engineering and technology</skos:prefLabel>
        <skos:prefLabel xml:lang="de">Ingenieurwissenschaften und Technologie</skos:prefLabel>
    </skos:Concept>
</rdf:RDF>
XML;

        $concepts = $this->parser->extractConcepts($rdf, $this->conceptSchemeUri);

        expect($concepts)->toHaveCount(1)
            ->and($concepts[0]['text'])->toBe('engineering and technology')
            ->and($concepts[0]['language'])->toBe('en');
    });

    it('skips concepts not belonging to the target scheme', function (): void {
        $rdf = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"
         xmlns:skos="http://www.w3.org/2004/02/skos/core#">
    <skos:Concept rdf:about="http://example.org/concept/1">
        <skos:inScheme rdf:resource="http://data.europa.eu/8mn/euroscivoc/test-scheme"/>
        <skos:prefLabel xml:lang="en">natural sciences</skos:prefLabel>
    </skos:Concept>
    <skos:Concept rdf:about="http://example.org/concept/other">
        <skos:inScheme rdf:resource="http://example.org/other-scheme"/>
        <skos:prefLabel xml:lang="en">should be skipped</skos:prefLabel>
    </skos:Concept>
</rdf:RDF>
XML;

        $concepts = $this->parser->extractConcepts($rdf, $this->conceptSchemeUri);

        expect($concepts)->toHaveCount(1)
            ->and($concepts[0]['text'])->toBe('natural sciences');
    });

    it('skips concepts without English labels', function (): void {
        $rdf = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"
         xmlns:skos="http://www.w3.org/2004/02/skos/core#"
         xmlns:skosxl="http://www.w3.org/2008/05/skos-xl#">
    <skosxl:Label rdf:about="http://example.org/label/de-only">
        <skosxl:literalForm xml:lang="de">nur Deutsch</skosxl:literalForm>
    </skosxl:Label>
    <skos:Concept rdf:about="http://example.org/concept/1">
        <skos:inScheme rdf:resource="http://data.europa.eu/8mn/euroscivoc/test-scheme"/>
        <skosxl:prefLabel rdf:resource="http://example.org/label/de-only"/>
    </skos:Concept>
</rdf:RDF>
XML;

        $concepts = $this->parser->extractConcepts($rdf, $this->conceptSchemeUri);

        expect($concepts)->toHaveCount(0);
    });

    it('skips concepts without rdf:about attribute', function (): void {
        $rdf = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"
         xmlns:skos="http://www.w3.org/2004/02/skos/core#">
    <skos:Concept>
        <skos:inScheme rdf:resource="http://data.europa.eu/8mn/euroscivoc/test-scheme"/>
        <skos:prefLabel xml:lang="en">no id concept</skos:prefLabel>
    </skos:Concept>
</rdf:RDF>
XML;

        $concepts = $this->parser->extractConcepts($rdf, $this->conceptSchemeUri);

        expect($concepts)->toHaveCount(0);
    });

    it('returns empty array for RDF with no concepts', function (): void {
        $rdf = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"
         xmlns:skos="http://www.w3.org/2004/02/skos/core#">
</rdf:RDF>
XML;

        $concepts = $this->parser->extractConcepts($rdf, $this->conceptSchemeUri);

        expect($concepts)->toBeArray()->toBeEmpty();
    });

    it('throws RuntimeException for invalid XML', function (): void {
        $this->parser->extractConcepts('not valid xml', $this->conceptSchemeUri);
    })->throws(RuntimeException::class, 'Failed to parse EuroSciVoc RDF/XML');

    it('detects top concepts via topConceptOf', function (): void {
        $rdf = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"
         xmlns:skos="http://www.w3.org/2004/02/skos/core#">
    <skos:Concept rdf:about="http://example.org/concept/root">
        <skos:topConceptOf rdf:resource="http://data.europa.eu/8mn/euroscivoc/test-scheme"/>
        <skos:prefLabel xml:lang="en">root concept</skos:prefLabel>
    </skos:Concept>
    <skos:Concept rdf:about="http://example.org/concept/child">
        <skos:inScheme rdf:resource="http://data.europa.eu/8mn/euroscivoc/test-scheme"/>
        <skos:broader rdf:resource="http://example.org/concept/root"/>
        <skos:prefLabel xml:lang="en">child concept</skos:prefLabel>
    </skos:Concept>
</rdf:RDF>
XML;

        $concepts = $this->parser->extractConcepts($rdf, $this->conceptSchemeUri);

        $root = collect($concepts)->firstWhere('id', 'http://example.org/concept/root');
        $child = collect($concepts)->firstWhere('id', 'http://example.org/concept/child');

        expect($root['isTopConcept'])->toBeTrue()
            ->and($child['isTopConcept'])->toBeFalse()
            ->and($child['broaderId'])->toBe('http://example.org/concept/root');
    });

    it('prefers SKOS-XL label over plain SKOS when both exist', function (): void {
        $rdf = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"
         xmlns:skos="http://www.w3.org/2004/02/skos/core#"
         xmlns:skosxl="http://www.w3.org/2008/05/skos-xl#">
    <skosxl:Label rdf:about="http://example.org/label/xl">
        <skosxl:literalForm xml:lang="en">SKOS-XL label</skosxl:literalForm>
    </skosxl:Label>
    <skos:Concept rdf:about="http://example.org/concept/1">
        <skos:inScheme rdf:resource="http://data.europa.eu/8mn/euroscivoc/test-scheme"/>
        <skosxl:prefLabel rdf:resource="http://example.org/label/xl"/>
        <skos:prefLabel xml:lang="en">plain SKOS label</skos:prefLabel>
    </skos:Concept>
</rdf:RDF>
XML;

        $concepts = $this->parser->extractConcepts($rdf, $this->conceptSchemeUri);

        expect($concepts)->toHaveCount(1)
            ->and($concepts[0]['text'])->toBe('SKOS-XL label');
    });

    it('falls back to untagged plain SKOS label when no language attribute exists', function (): void {
        $rdf = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"
         xmlns:skos="http://www.w3.org/2004/02/skos/core#">
    <skos:Concept rdf:about="http://example.org/concept/1">
        <skos:inScheme rdf:resource="http://data.europa.eu/8mn/euroscivoc/test-scheme"/>
        <skos:prefLabel>untagged label</skos:prefLabel>
    </skos:Concept>
</rdf:RDF>
XML;

        $concepts = $this->parser->extractConcepts($rdf, $this->conceptSchemeUri);

        expect($concepts)->toHaveCount(1)
            ->and($concepts[0]['text'])->toBe('untagged label');
    });

    it('does not return non-English tagged labels as fallback', function (): void {
        $rdf = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"
         xmlns:skos="http://www.w3.org/2004/02/skos/core#">
    <skos:Concept rdf:about="http://example.org/concept/1">
        <skos:inScheme rdf:resource="http://data.europa.eu/8mn/euroscivoc/test-scheme"/>
        <skos:prefLabel xml:lang="de">nur Deutsch</skos:prefLabel>
        <skos:prefLabel xml:lang="fr">seulement français</skos:prefLabel>
    </skos:Concept>
</rdf:RDF>
XML;

        $concepts = $this->parser->extractConcepts($rdf, $this->conceptSchemeUri);

        expect($concepts)->toHaveCount(0);
    });

    it('skips SKOS-XL labels without rdf:about attribute', function (): void {
        $rdf = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"
         xmlns:skos="http://www.w3.org/2004/02/skos/core#"
         xmlns:skosxl="http://www.w3.org/2008/05/skos-xl#">
    <skosxl:Label>
        <skosxl:literalForm xml:lang="en">orphan label</skosxl:literalForm>
    </skosxl:Label>
    <skos:Concept rdf:about="http://example.org/concept/1">
        <skos:inScheme rdf:resource="http://data.europa.eu/8mn/euroscivoc/test-scheme"/>
        <skos:prefLabel xml:lang="en">direct label</skos:prefLabel>
    </skos:Concept>
</rdf:RDF>
XML;

        $concepts = $this->parser->extractConcepts($rdf, $this->conceptSchemeUri);

        expect($concepts)->toHaveCount(1)
            ->and($concepts[0]['text'])->toBe('direct label');
    });

    it('skips SKOS-XL labels without literalForm element', function (): void {
        $rdf = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"
         xmlns:skos="http://www.w3.org/2004/02/skos/core#"
         xmlns:skosxl="http://www.w3.org/2008/05/skos-xl#">
    <skosxl:Label rdf:about="http://example.org/label/empty">
    </skosxl:Label>
    <skos:Concept rdf:about="http://example.org/concept/1">
        <skos:inScheme rdf:resource="http://data.europa.eu/8mn/euroscivoc/test-scheme"/>
        <skosxl:prefLabel rdf:resource="http://example.org/label/empty"/>
        <skos:prefLabel xml:lang="en">fallback label</skos:prefLabel>
    </skos:Concept>
</rdf:RDF>
XML;

        $concepts = $this->parser->extractConcepts($rdf, $this->conceptSchemeUri);

        expect($concepts)->toHaveCount(1)
            ->and($concepts[0]['text'])->toBe('fallback label');
    });

    it('falls back to plain SKOS when SKOS-XL reference is not in label map', function (): void {
        $rdf = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"
         xmlns:skos="http://www.w3.org/2004/02/skos/core#"
         xmlns:skosxl="http://www.w3.org/2008/05/skos-xl#">
    <skos:Concept rdf:about="http://example.org/concept/1">
        <skos:inScheme rdf:resource="http://data.europa.eu/8mn/euroscivoc/test-scheme"/>
        <skosxl:prefLabel rdf:resource="http://example.org/label/nonexistent"/>
        <skos:prefLabel xml:lang="en">plain fallback</skos:prefLabel>
    </skos:Concept>
</rdf:RDF>
XML;

        $concepts = $this->parser->extractConcepts($rdf, $this->conceptSchemeUri);

        expect($concepts)->toHaveCount(1)
            ->and($concepts[0]['text'])->toBe('plain fallback');
    });

    it('extracts concepts from rdf:Description format with rdf:type', function (): void {
        $rdf = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">
    <rdf:Description rdf:about="http://example.org/label/1">
        <rdf:type rdf:resource="http://www.w3.org/2008/05/skos-xl#Label"/>
        <literalForm xmlns="http://www.w3.org/2008/05/skos-xl#" xml:lang="en">natural sciences</literalForm>
        <literalForm xmlns="http://www.w3.org/2008/05/skos-xl#" xml:lang="de">Naturwissenschaften</literalForm>
    </rdf:Description>
    <rdf:Description rdf:about="http://example.org/label/2">
        <rdf:type rdf:resource="http://www.w3.org/2008/05/skos-xl#Label"/>
        <literalForm xmlns="http://www.w3.org/2008/05/skos-xl#" xml:lang="en">physics</literalForm>
    </rdf:Description>
    <rdf:Description rdf:about="http://example.org/concept/1">
        <rdf:type rdf:resource="http://www.w3.org/2004/02/skos/core#Concept"/>
        <inScheme xmlns="http://www.w3.org/2004/02/skos/core#" rdf:resource="http://data.europa.eu/8mn/euroscivoc/test-scheme"/>
        <topConceptOf xmlns="http://www.w3.org/2004/02/skos/core#" rdf:resource="http://data.europa.eu/8mn/euroscivoc/test-scheme"/>
        <prefLabel xmlns="http://www.w3.org/2008/05/skos-xl#" rdf:resource="http://example.org/label/1"/>
    </rdf:Description>
    <rdf:Description rdf:about="http://example.org/concept/2">
        <rdf:type rdf:resource="http://www.w3.org/2004/02/skos/core#Concept"/>
        <inScheme xmlns="http://www.w3.org/2004/02/skos/core#" rdf:resource="http://data.europa.eu/8mn/euroscivoc/test-scheme"/>
        <broader xmlns="http://www.w3.org/2004/02/skos/core#" rdf:resource="http://example.org/concept/1"/>
        <prefLabel xmlns="http://www.w3.org/2008/05/skos-xl#" rdf:resource="http://example.org/label/2"/>
    </rdf:Description>
</rdf:RDF>
XML;

        $concepts = $this->parser->extractConcepts($rdf, $this->conceptSchemeUri);

        expect($concepts)->toHaveCount(2)
            ->and($concepts[0])->toMatchArray([
                'id' => 'http://example.org/concept/1',
                'text' => 'natural sciences',
                'language' => 'en',
                'isTopConcept' => true,
                'broaderId' => null,
            ])
            ->and($concepts[1])->toMatchArray([
                'id' => 'http://example.org/concept/2',
                'text' => 'physics',
                'language' => 'en',
                'isTopConcept' => false,
                'broaderId' => 'http://example.org/concept/1',
            ]);
    });

    it('extracts concepts from rdf:Description format with plain SKOS labels', function (): void {
        $rdf = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">
    <rdf:Description rdf:about="http://example.org/concept/1">
        <rdf:type rdf:resource="http://www.w3.org/2004/02/skos/core#Concept"/>
        <inScheme xmlns="http://www.w3.org/2004/02/skos/core#" rdf:resource="http://data.europa.eu/8mn/euroscivoc/test-scheme"/>
        <topConceptOf xmlns="http://www.w3.org/2004/02/skos/core#" rdf:resource="http://data.europa.eu/8mn/euroscivoc/test-scheme"/>
        <prefLabel xmlns="http://www.w3.org/2004/02/skos/core#" xml:lang="en">engineering and technology</prefLabel>
        <prefLabel xmlns="http://www.w3.org/2004/02/skos/core#" xml:lang="de">Ingenieurwissenschaften und Technologie</prefLabel>
    </rdf:Description>
</rdf:RDF>
XML;

        $concepts = $this->parser->extractConcepts($rdf, $this->conceptSchemeUri);

        expect($concepts)->toHaveCount(1)
            ->and($concepts[0]['text'])->toBe('engineering and technology')
            ->and($concepts[0]['isTopConcept'])->toBeTrue();
    });

    it('skips rdf:Description concepts not belonging to the target scheme', function (): void {
        $rdf = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">
    <rdf:Description rdf:about="http://example.org/concept/1">
        <rdf:type rdf:resource="http://www.w3.org/2004/02/skos/core#Concept"/>
        <inScheme xmlns="http://www.w3.org/2004/02/skos/core#" rdf:resource="http://data.europa.eu/8mn/euroscivoc/test-scheme"/>
        <prefLabel xmlns="http://www.w3.org/2004/02/skos/core#" xml:lang="en">natural sciences</prefLabel>
    </rdf:Description>
    <rdf:Description rdf:about="http://example.org/concept/other">
        <rdf:type rdf:resource="http://www.w3.org/2004/02/skos/core#Concept"/>
        <inScheme xmlns="http://www.w3.org/2004/02/skos/core#" rdf:resource="http://example.org/other-scheme"/>
        <prefLabel xmlns="http://www.w3.org/2004/02/skos/core#" xml:lang="en">should be skipped</prefLabel>
    </rdf:Description>
</rdf:RDF>
XML;

        $concepts = $this->parser->extractConcepts($rdf, $this->conceptSchemeUri);

        expect($concepts)->toHaveCount(1)
            ->and($concepts[0]['text'])->toBe('natural sciences');
    });

    it('handles mixed abbreviated and rdf:Description formats', function (): void {
        $rdf = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"
         xmlns:skos="http://www.w3.org/2004/02/skos/core#"
         xmlns:skosxl="http://www.w3.org/2008/05/skos-xl#">
    <skosxl:Label rdf:about="http://example.org/label/1">
        <skosxl:literalForm xml:lang="en">abbreviated concept</skosxl:literalForm>
    </skosxl:Label>
    <rdf:Description rdf:about="http://example.org/label/2">
        <rdf:type rdf:resource="http://www.w3.org/2008/05/skos-xl#Label"/>
        <literalForm xmlns="http://www.w3.org/2008/05/skos-xl#" xml:lang="en">description concept</literalForm>
    </rdf:Description>
    <skos:Concept rdf:about="http://example.org/concept/1">
        <skos:inScheme rdf:resource="http://data.europa.eu/8mn/euroscivoc/test-scheme"/>
        <skos:topConceptOf rdf:resource="http://data.europa.eu/8mn/euroscivoc/test-scheme"/>
        <skosxl:prefLabel rdf:resource="http://example.org/label/1"/>
    </skos:Concept>
    <rdf:Description rdf:about="http://example.org/concept/2">
        <rdf:type rdf:resource="http://www.w3.org/2004/02/skos/core#Concept"/>
        <inScheme xmlns="http://www.w3.org/2004/02/skos/core#" rdf:resource="http://data.europa.eu/8mn/euroscivoc/test-scheme"/>
        <broader xmlns="http://www.w3.org/2004/02/skos/core#" rdf:resource="http://example.org/concept/1"/>
        <prefLabel xmlns="http://www.w3.org/2008/05/skos-xl#" rdf:resource="http://example.org/label/2"/>
    </rdf:Description>
</rdf:RDF>
XML;

        $concepts = $this->parser->extractConcepts($rdf, $this->conceptSchemeUri);

        expect($concepts)->toHaveCount(2);

        $texts = array_column($concepts, 'text');
        expect($texts)->toContain('abbreviated concept')
            ->and($texts)->toContain('description concept');
    });

    it('builds full hierarchy from rdf:Description format', function (): void {
        $rdf = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">
    <rdf:Description rdf:about="http://example.org/label/root">
        <rdf:type rdf:resource="http://www.w3.org/2008/05/skos-xl#Label"/>
        <literalForm xmlns="http://www.w3.org/2008/05/skos-xl#" xml:lang="en">natural sciences</literalForm>
    </rdf:Description>
    <rdf:Description rdf:about="http://example.org/label/child1">
        <rdf:type rdf:resource="http://www.w3.org/2008/05/skos-xl#Label"/>
        <literalForm xmlns="http://www.w3.org/2008/05/skos-xl#" xml:lang="en">physics</literalForm>
    </rdf:Description>
    <rdf:Description rdf:about="http://example.org/label/child2">
        <rdf:type rdf:resource="http://www.w3.org/2008/05/skos-xl#Label"/>
        <literalForm xmlns="http://www.w3.org/2008/05/skos-xl#" xml:lang="en">chemistry</literalForm>
    </rdf:Description>
    <rdf:Description rdf:about="http://example.org/concept/root">
        <rdf:type rdf:resource="http://www.w3.org/2004/02/skos/core#Concept"/>
        <topConceptOf xmlns="http://www.w3.org/2004/02/skos/core#" rdf:resource="http://data.europa.eu/8mn/euroscivoc/test-scheme"/>
        <prefLabel xmlns="http://www.w3.org/2008/05/skos-xl#" rdf:resource="http://example.org/label/root"/>
    </rdf:Description>
    <rdf:Description rdf:about="http://example.org/concept/child1">
        <rdf:type rdf:resource="http://www.w3.org/2004/02/skos/core#Concept"/>
        <inScheme xmlns="http://www.w3.org/2004/02/skos/core#" rdf:resource="http://data.europa.eu/8mn/euroscivoc/test-scheme"/>
        <broader xmlns="http://www.w3.org/2004/02/skos/core#" rdf:resource="http://example.org/concept/root"/>
        <prefLabel xmlns="http://www.w3.org/2008/05/skos-xl#" rdf:resource="http://example.org/label/child1"/>
    </rdf:Description>
    <rdf:Description rdf:about="http://example.org/concept/child2">
        <rdf:type rdf:resource="http://www.w3.org/2004/02/skos/core#Concept"/>
        <inScheme xmlns="http://www.w3.org/2004/02/skos/core#" rdf:resource="http://data.europa.eu/8mn/euroscivoc/test-scheme"/>
        <broader xmlns="http://www.w3.org/2004/02/skos/core#" rdf:resource="http://example.org/concept/root"/>
        <prefLabel xmlns="http://www.w3.org/2008/05/skos-xl#" rdf:resource="http://example.org/label/child2"/>
    </rdf:Description>
</rdf:RDF>
XML;

        $concepts = $this->parser->extractConcepts($rdf, $this->conceptSchemeUri);
        $result = $this->parser->buildHierarchy($concepts, 'EuroSciVoc', $this->conceptSchemeUri);

        expect($concepts)->toHaveCount(3)
            ->and($result['data'])->toHaveCount(1)
            ->and($result['data'][0]['text'])->toBe('natural sciences')
            ->and($result['data'][0]['children'])->toHaveCount(2)
            ->and($result['data'][0]['children'][0]['text'])->toBe('chemistry')
            ->and($result['data'][0]['children'][1]['text'])->toBe('physics');
    });
});

// =========================================================================
// buildHierarchy
// =========================================================================

describe('buildHierarchy', function (): void {
    it('builds hierarchical tree from flat concepts', function (): void {
        $concepts = [
            ['id' => 'http://example.org/root', 'text' => 'natural sciences', 'language' => 'en', 'broaderId' => null, 'isTopConcept' => true],
            ['id' => 'http://example.org/child1', 'text' => 'physics', 'language' => 'en', 'broaderId' => 'http://example.org/root', 'isTopConcept' => false],
            ['id' => 'http://example.org/child2', 'text' => 'chemistry', 'language' => 'en', 'broaderId' => 'http://example.org/root', 'isTopConcept' => false],
        ];

        $result = $this->parser->buildHierarchy($concepts, 'European Science Vocabulary (EuroSciVoc)', 'http://example.org/scheme');

        expect($result)->toHaveKeys(['lastUpdated', 'data'])
            ->and($result['data'])->toHaveCount(1)
            ->and($result['data'][0]['text'])->toBe('natural sciences')
            ->and($result['data'][0]['scheme'])->toBe('European Science Vocabulary (EuroSciVoc)')
            ->and($result['data'][0]['schemeURI'])->toBe('http://example.org/scheme')
            ->and($result['data'][0]['children'])->toHaveCount(2)
            ->and($result['data'][0]['children'][0]['text'])->toBe('chemistry')
            ->and($result['data'][0]['children'][1]['text'])->toBe('physics');
    });

    it('sorts root concepts alphabetically', function (): void {
        $concepts = [
            ['id' => 'http://example.org/c', 'text' => 'social sciences', 'language' => 'en', 'broaderId' => null, 'isTopConcept' => true],
            ['id' => 'http://example.org/a', 'text' => 'humanities', 'language' => 'en', 'broaderId' => null, 'isTopConcept' => true],
            ['id' => 'http://example.org/b', 'text' => 'natural sciences', 'language' => 'en', 'broaderId' => null, 'isTopConcept' => true],
        ];

        $result = $this->parser->buildHierarchy($concepts, 'EuroSciVoc', 'http://example.org/scheme');

        expect($result['data'][0]['text'])->toBe('humanities')
            ->and($result['data'][1]['text'])->toBe('natural sciences')
            ->and($result['data'][2]['text'])->toBe('social sciences');
    });

    it('sorts children alphabetically', function (): void {
        $concepts = [
            ['id' => 'http://example.org/root', 'text' => 'root', 'language' => 'en', 'broaderId' => null, 'isTopConcept' => true],
            ['id' => 'http://example.org/z', 'text' => 'zoology', 'language' => 'en', 'broaderId' => 'http://example.org/root', 'isTopConcept' => false],
            ['id' => 'http://example.org/a', 'text' => 'astronomy', 'language' => 'en', 'broaderId' => 'http://example.org/root', 'isTopConcept' => false],
        ];

        $result = $this->parser->buildHierarchy($concepts, 'EuroSciVoc', 'http://example.org/scheme');

        expect($result['data'][0]['children'][0]['text'])->toBe('astronomy')
            ->and($result['data'][0]['children'][1]['text'])->toBe('zoology');
    });

    it('derives top concepts when none are explicitly marked', function (): void {
        $concepts = [
            ['id' => 'http://example.org/root', 'text' => 'root', 'language' => 'en', 'broaderId' => null, 'isTopConcept' => false],
            ['id' => 'http://example.org/child', 'text' => 'child', 'language' => 'en', 'broaderId' => 'http://example.org/root', 'isTopConcept' => false],
        ];

        $result = $this->parser->buildHierarchy($concepts, 'EuroSciVoc', 'http://example.org/scheme');

        expect($result['data'])->toHaveCount(1)
            ->and($result['data'][0]['text'])->toBe('root')
            ->and($result['data'][0]['children'])->toHaveCount(1)
            ->and($result['data'][0]['children'][0]['text'])->toBe('child');
    });

    it('builds multi-level hierarchy', function (): void {
        $concepts = [
            ['id' => 'http://example.org/l1', 'text' => 'natural sciences', 'language' => 'en', 'broaderId' => null, 'isTopConcept' => true],
            ['id' => 'http://example.org/l2', 'text' => 'physical sciences', 'language' => 'en', 'broaderId' => 'http://example.org/l1', 'isTopConcept' => false],
            ['id' => 'http://example.org/l3', 'text' => 'astronomy', 'language' => 'en', 'broaderId' => 'http://example.org/l2', 'isTopConcept' => false],
        ];

        $result = $this->parser->buildHierarchy($concepts, 'EuroSciVoc', 'http://example.org/scheme');

        expect($result['data'])->toHaveCount(1)
            ->and($result['data'][0]['children'])->toHaveCount(1)
            ->and($result['data'][0]['children'][0]['text'])->toBe('physical sciences')
            ->and($result['data'][0]['children'][0]['children'])->toHaveCount(1)
            ->and($result['data'][0]['children'][0]['children'][0]['text'])->toBe('astronomy')
            ->and($result['data'][0]['children'][0]['children'][0]['children'])->toBeEmpty();
    });

    it('includes description field as empty string', function (): void {
        $concepts = [
            ['id' => 'http://example.org/c1', 'text' => 'test', 'language' => 'en', 'broaderId' => null, 'isTopConcept' => true],
        ];

        $result = $this->parser->buildHierarchy($concepts, 'EuroSciVoc', 'http://example.org/scheme');

        expect($result['data'][0]['description'])->toBe('');
    });

    it('includes lastUpdated timestamp', function (): void {
        $concepts = [
            ['id' => 'http://example.org/c1', 'text' => 'test', 'language' => 'en', 'broaderId' => null, 'isTopConcept' => true],
        ];

        $result = $this->parser->buildHierarchy($concepts, 'EuroSciVoc', 'http://example.org/scheme');

        expect($result['lastUpdated'])->toBeString()->not->toBeEmpty();
    });

    it('returns empty data for empty concepts array', function (): void {
        $result = $this->parser->buildHierarchy([], 'EuroSciVoc', 'http://example.org/scheme');

        expect($result['data'])->toBeArray()->toBeEmpty()
            ->and($result['lastUpdated'])->toBeString();
    });

    it('promotes orphan children whose parent is outside the scheme to roots', function (): void {
        // Child references a broaderId that is not in the concepts array.
        // The orphan rescue logic promotes it to a root concept.
        $concepts = [
            ['id' => 'http://example.org/root', 'text' => 'root', 'language' => 'en', 'broaderId' => null, 'isTopConcept' => true],
            ['id' => 'http://example.org/child', 'text' => 'child', 'language' => 'en', 'broaderId' => 'http://example.org/missing-parent', 'isTopConcept' => false],
        ];

        $result = $this->parser->buildHierarchy($concepts, 'EuroSciVoc', 'http://example.org/scheme');

        expect($result['data'])->toHaveCount(2);
        $texts = array_column($result['data'], 'text');
        expect($texts)->toContain('root')
            ->and($texts)->toContain('child');
    });
});

// =========================================================================
// countConcepts
// =========================================================================

describe('countConcepts', function (): void {
    it('counts flat list of concepts', function (): void {
        $data = [
            ['text' => 'a', 'children' => []],
            ['text' => 'b', 'children' => []],
            ['text' => 'c', 'children' => []],
        ];

        expect($this->parser->countConcepts($data))->toBe(3);
    });

    it('counts nested concepts recursively', function (): void {
        $data = [
            [
                'text' => 'natural sciences',
                'children' => [
                    [
                        'text' => 'physics',
                        'children' => [
                            ['text' => 'astronomy', 'children' => []],
                        ],
                    ],
                    ['text' => 'chemistry', 'children' => []],
                ],
            ],
        ];

        expect($this->parser->countConcepts($data))->toBe(4);
    });

    it('returns 0 for empty array', function (): void {
        expect($this->parser->countConcepts([]))->toBe(0);
    });
});
