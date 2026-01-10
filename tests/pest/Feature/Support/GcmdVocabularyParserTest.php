<?php

declare(strict_types=1);

use App\Support\GcmdVocabularyParser;

describe('GcmdVocabularyParser', function (): void {

    describe('extractTotalHits', function (): void {

        it('extracts total hits from valid RDF content', function (): void {
            $parser = new GcmdVocabularyParser;

            $rdfContent = <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"
                     xmlns:gcmd="https://gcmd.earthdata.nasa.gov/kms#">
                <gcmd:gcmd>
                    <gcmd:hits>42</gcmd:hits>
                </gcmd:gcmd>
            </rdf:RDF>
            XML;

            $result = $parser->extractTotalHits($rdfContent);

            expect($result)->toBe(42);
        });

        it('returns 0 when hits element is missing', function (): void {
            $parser = new GcmdVocabularyParser;

            $rdfContent = <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"
                     xmlns:gcmd="https://gcmd.earthdata.nasa.gov/kms#">
                <gcmd:gcmd>
                </gcmd:gcmd>
            </rdf:RDF>
            XML;

            $result = $parser->extractTotalHits($rdfContent);

            expect($result)->toBe(0);
        });

        it('returns 0 for empty gcmd element', function (): void {
            $parser = new GcmdVocabularyParser;

            $rdfContent = <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"
                     xmlns:gcmd="https://gcmd.earthdata.nasa.gov/kms#">
            </rdf:RDF>
            XML;

            $result = $parser->extractTotalHits($rdfContent);

            expect($result)->toBe(0);
        });

    });

    describe('extractConcepts', function (): void {

        it('extracts concepts from valid RDF content', function (): void {
            $parser = new GcmdVocabularyParser;

            $rdfContent = <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"
                     xmlns:skos="http://www.w3.org/2004/02/skos/core#">
                <skos:Concept rdf:about="https://gcmd.earthdata.nasa.gov/kms/concept/abc123">
                    <skos:prefLabel xml:lang="en">Earth Science</skos:prefLabel>
                    <skos:definition>The study of Earth</skos:definition>
                </skos:Concept>
            </rdf:RDF>
            XML;

            $result = $parser->extractConcepts($rdfContent);

            expect($result)->toHaveCount(1);
            expect($result[0]['id'])->toBe('https://gcmd.earthdata.nasa.gov/kms/concept/abc123');
            expect($result[0]['text'])->toBe('Earth Science');
            expect($result[0]['language'])->toBe('en');
            expect($result[0]['description'])->toBe('The study of Earth');
            expect($result[0]['broaderId'])->toBeNull();
        });

        it('extracts concepts with broader relationship', function (): void {
            $parser = new GcmdVocabularyParser;

            $rdfContent = <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"
                     xmlns:skos="http://www.w3.org/2004/02/skos/core#">
                <skos:Concept rdf:about="https://gcmd.earthdata.nasa.gov/kms/concept/child123">
                    <skos:prefLabel xml:lang="en">Atmosphere</skos:prefLabel>
                    <skos:definition>The atmosphere</skos:definition>
                    <skos:broader rdf:resource="https://gcmd.earthdata.nasa.gov/kms/concept/parent456"/>
                </skos:Concept>
            </rdf:RDF>
            XML;

            $result = $parser->extractConcepts($rdfContent);

            expect($result)->toHaveCount(1);
            expect($result[0]['broaderId'])->toBe('https://gcmd.earthdata.nasa.gov/kms/concept/parent456');
        });

        it('converts UUID to full URL when necessary', function (): void {
            $parser = new GcmdVocabularyParser;

            $rdfContent = <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"
                     xmlns:skos="http://www.w3.org/2004/02/skos/core#">
                <skos:Concept rdf:about="abc-123-def-456">
                    <skos:prefLabel xml:lang="en">Test Concept</skos:prefLabel>
                </skos:Concept>
            </rdf:RDF>
            XML;

            $result = $parser->extractConcepts($rdfContent);

            expect($result)->toHaveCount(1);
            expect($result[0]['id'])->toBe('https://gcmd.earthdata.nasa.gov/kms/concept/abc-123-def-456');
        });

        it('returns empty array for RDF without concepts', function (): void {
            $parser = new GcmdVocabularyParser;

            $rdfContent = <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"
                     xmlns:skos="http://www.w3.org/2004/02/skos/core#">
            </rdf:RDF>
            XML;

            $result = $parser->extractConcepts($rdfContent);

            expect($result)->toBeArray()->toBeEmpty();
        });

        it('extracts multiple concepts', function (): void {
            $parser = new GcmdVocabularyParser;

            $rdfContent = <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"
                     xmlns:skos="http://www.w3.org/2004/02/skos/core#">
                <skos:Concept rdf:about="https://gcmd.earthdata.nasa.gov/kms/concept/1">
                    <skos:prefLabel xml:lang="en">Concept One</skos:prefLabel>
                </skos:Concept>
                <skos:Concept rdf:about="https://gcmd.earthdata.nasa.gov/kms/concept/2">
                    <skos:prefLabel xml:lang="en">Concept Two</skos:prefLabel>
                </skos:Concept>
                <skos:Concept rdf:about="https://gcmd.earthdata.nasa.gov/kms/concept/3">
                    <skos:prefLabel xml:lang="de">Konzept Drei</skos:prefLabel>
                </skos:Concept>
            </rdf:RDF>
            XML;

            $result = $parser->extractConcepts($rdfContent);

            expect($result)->toHaveCount(3);
            expect($result[2]['language'])->toBe('de');
        });

    });

    describe('buildHierarchy', function (): void {

        it('builds hierarchy with root concepts only', function (): void {
            $parser = new GcmdVocabularyParser;

            $concepts = [
                [
                    'id' => 'https://gcmd.earthdata.nasa.gov/kms/concept/1',
                    'text' => 'Earth Science',
                    'language' => 'en',
                    'description' => 'Root concept',
                    'broaderId' => null,
                ],
            ];

            $result = $parser->buildHierarchy($concepts, 'GCMD Keywords', 'https://gcmd.earthdata.nasa.gov/kms');

            expect($result)->toHaveKey('lastUpdated');
            expect($result)->toHaveKey('data');
            expect($result['data'])->toHaveCount(1);
            expect($result['data'][0]['text'])->toBe('Earth Science');
            expect($result['data'][0]['children'])->toBeEmpty();
        });

        it('builds hierarchy with parent-child relationships', function (): void {
            $parser = new GcmdVocabularyParser;

            $concepts = [
                [
                    'id' => 'https://gcmd.earthdata.nasa.gov/kms/concept/parent',
                    'text' => 'Earth Science',
                    'language' => 'en',
                    'description' => 'Parent',
                    'broaderId' => null,
                ],
                [
                    'id' => 'https://gcmd.earthdata.nasa.gov/kms/concept/child',
                    'text' => 'Atmosphere',
                    'language' => 'en',
                    'description' => 'Child',
                    'broaderId' => 'https://gcmd.earthdata.nasa.gov/kms/concept/parent',
                ],
            ];

            $result = $parser->buildHierarchy($concepts, 'GCMD', 'https://gcmd.earthdata.nasa.gov/kms');

            expect($result['data'])->toHaveCount(1);
            expect($result['data'][0]['text'])->toBe('Earth Science');
            expect($result['data'][0]['children'])->toHaveCount(1);
            expect($result['data'][0]['children'][0]['text'])->toBe('Atmosphere');
        });

        it('builds multi-level hierarchy', function (): void {
            $parser = new GcmdVocabularyParser;

            $concepts = [
                [
                    'id' => 'https://gcmd.earthdata.nasa.gov/kms/concept/root',
                    'text' => 'Science',
                    'language' => 'en',
                    'description' => 'Root',
                    'broaderId' => null,
                ],
                [
                    'id' => 'https://gcmd.earthdata.nasa.gov/kms/concept/level1',
                    'text' => 'Earth Science',
                    'language' => 'en',
                    'description' => 'Level 1',
                    'broaderId' => 'https://gcmd.earthdata.nasa.gov/kms/concept/root',
                ],
                [
                    'id' => 'https://gcmd.earthdata.nasa.gov/kms/concept/level2',
                    'text' => 'Atmosphere',
                    'language' => 'en',
                    'description' => 'Level 2',
                    'broaderId' => 'https://gcmd.earthdata.nasa.gov/kms/concept/level1',
                ],
            ];

            $result = $parser->buildHierarchy($concepts, 'GCMD', 'https://gcmd.earthdata.nasa.gov/kms');

            expect($result['data'])->toHaveCount(1);
            expect($result['data'][0]['children'])->toHaveCount(1);
            expect($result['data'][0]['children'][0]['children'])->toHaveCount(1);
            expect($result['data'][0]['children'][0]['children'][0]['text'])->toBe('Atmosphere');
        });

        it('handles multiple root concepts', function (): void {
            $parser = new GcmdVocabularyParser;

            $concepts = [
                [
                    'id' => 'https://gcmd.earthdata.nasa.gov/kms/concept/root1',
                    'text' => 'Earth Science',
                    'language' => 'en',
                    'description' => null,
                    'broaderId' => null,
                ],
                [
                    'id' => 'https://gcmd.earthdata.nasa.gov/kms/concept/root2',
                    'text' => 'Space Science',
                    'language' => 'en',
                    'description' => null,
                    'broaderId' => null,
                ],
            ];

            $result = $parser->buildHierarchy($concepts, 'GCMD', 'https://gcmd.earthdata.nasa.gov/kms');

            expect($result['data'])->toHaveCount(2);
        });

        it('skips concepts with null or empty IDs', function (): void {
            $parser = new GcmdVocabularyParser;

            $concepts = [
                [
                    'id' => null,
                    'text' => 'Invalid',
                    'language' => 'en',
                    'description' => null,
                    'broaderId' => null,
                ],
                [
                    'id' => '',
                    'text' => 'Also Invalid',
                    'language' => 'en',
                    'description' => null,
                    'broaderId' => null,
                ],
                [
                    'id' => 'https://gcmd.earthdata.nasa.gov/kms/concept/valid',
                    'text' => 'Valid',
                    'language' => 'en',
                    'description' => null,
                    'broaderId' => null,
                ],
            ];

            $result = $parser->buildHierarchy($concepts, 'GCMD', 'https://gcmd.earthdata.nasa.gov/kms');

            expect($result['data'])->toHaveCount(1);
            expect($result['data'][0]['text'])->toBe('Valid');
        });

        it('includes scheme information in hierarchy nodes', function (): void {
            $parser = new GcmdVocabularyParser;

            $concepts = [
                [
                    'id' => 'https://gcmd.earthdata.nasa.gov/kms/concept/1',
                    'text' => 'Test Concept',
                    'language' => 'en',
                    'description' => 'A description',
                    'broaderId' => null,
                ],
            ];

            $result = $parser->buildHierarchy(
                $concepts,
                'NASA/GCMD Earth Science Keywords',
                'https://gcmd.earthdata.nasa.gov/kms/concepts/concept_scheme/sciencekeywords'
            );

            expect($result['data'][0]['scheme'])->toBe('NASA/GCMD Earth Science Keywords');
            expect($result['data'][0]['schemeURI'])->toBe('https://gcmd.earthdata.nasa.gov/kms/concepts/concept_scheme/sciencekeywords');
        });

    });

});
