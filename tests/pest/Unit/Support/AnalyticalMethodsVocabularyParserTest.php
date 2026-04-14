<?php

declare(strict_types=1);

use App\Support\AnalyticalMethodsVocabularyParser;

covers(AnalyticalMethodsVocabularyParser::class);

beforeEach(function () {
    $this->parser = new AnalyticalMethodsVocabularyParser;
});

describe('extractConcepts', function () {
    it('extracts concepts with English labels and notation', function () {
        $items = [
            [
                '_about' => 'https://w3id.org/geochem/1.0/analyticalmethod/massspectrometry',
                'prefLabel' => ['_value' => 'Mass spectrometry', '_lang' => 'en'],
                'broader' => ['https://w3id.org/geochem/1.0/analyticalmethod/particlespectrometry'],
                'notation' => 'MS',
                'definition' => 'Study of matter through the formation of gas-phase ions.',
            ],
        ];

        $concepts = $this->parser->extractConcepts($items);

        expect($concepts)->toHaveCount(1)
            ->and($concepts[0]['text'])->toBe('Mass spectrometry')
            ->and($concepts[0]['id'])->toBe('https://w3id.org/geochem/1.0/analyticalmethod/massspectrometry')
            ->and($concepts[0]['notation'])->toBe('MS')
            ->and($concepts[0]['definition'])->toBe('Study of matter through the formation of gas-phase ions.')
            ->and($concepts[0]['broaderId'])->toBe('https://w3id.org/geochem/1.0/analyticalmethod/particlespectrometry');
    });

    it('handles missing notation gracefully', function () {
        $items = [
            [
                '_about' => 'https://w3id.org/geochem/1.0/analyticalmethod/test',
                'prefLabel' => ['_value' => 'Test Method', '_lang' => 'en'],
                'broader' => [],
            ],
        ];

        $concepts = $this->parser->extractConcepts($items);

        expect($concepts)->toHaveCount(1)
            ->and($concepts[0]['notation'])->toBe('')
            ->and($concepts[0]['definition'])->toBe('');
    });

    it('handles missing definition gracefully', function () {
        $items = [
            [
                '_about' => 'https://w3id.org/geochem/1.0/analyticalmethod/test',
                'prefLabel' => ['_value' => 'Test', '_lang' => 'en'],
                'broader' => [],
                'notation' => 'T',
            ],
        ];

        $concepts = $this->parser->extractConcepts($items);

        expect($concepts)->toHaveCount(1)
            ->and($concepts[0]['definition'])->toBe('');
    });

    it('skips items without English labels', function () {
        $items = [
            [
                '_about' => 'https://w3id.org/geochem/1.0/analyticalmethod/test',
                'prefLabel' => ['_value' => 'Méthode', '_lang' => 'fr'],
                'broader' => [],
            ],
        ];

        $concepts = $this->parser->extractConcepts($items);

        expect($concepts)->toBeEmpty();
    });

    it('skips items with empty URI', function () {
        $items = [
            [
                '_about' => '',
                'prefLabel' => ['_value' => 'Test', '_lang' => 'en'],
                'broader' => [],
            ],
        ];

        $concepts = $this->parser->extractConcepts($items);

        expect($concepts)->toBeEmpty();
    });

    it('skips items without URI', function () {
        $items = [
            [
                'prefLabel' => ['_value' => 'Test', '_lang' => 'en'],
                'broader' => [],
            ],
        ];

        $concepts = $this->parser->extractConcepts($items);

        expect($concepts)->toBeEmpty();
    });

    it('handles prefLabel as array of language objects', function () {
        $items = [
            [
                '_about' => 'https://w3id.org/geochem/1.0/analyticalmethod/test',
                'prefLabel' => [
                    ['_value' => 'Méthode', '_lang' => 'fr'],
                    ['_value' => 'Method', '_lang' => 'en'],
                ],
                'broader' => [],
                'notation' => 'M',
            ],
        ];

        $concepts = $this->parser->extractConcepts($items);

        expect($concepts)->toHaveCount(1)
            ->and($concepts[0]['text'])->toBe('Method');
    });

    it('extracts broader URI from array of strings', function () {
        $items = [
            [
                '_about' => 'https://w3id.org/geochem/1.0/analyticalmethod/icpms',
                'prefLabel' => ['_value' => 'ICP-MS', '_lang' => 'en'],
                'broader' => [
                    'https://w3id.org/geochem/1.0/analyticalmethod/massspectrometry',
                    'https://w3id.org/geochem/1.0/analyticalmethod/icpspectroscopy',
                ],
                'notation' => 'ICP-MS',
            ],
        ];

        $concepts = $this->parser->extractConcepts($items);

        // Takes first broader as canonical parent
        expect($concepts[0]['broaderId'])->toBe('https://w3id.org/geochem/1.0/analyticalmethod/massspectrometry');
    });

    it('extracts broader URI from array of objects', function () {
        $items = [
            [
                '_about' => 'https://w3id.org/geochem/1.0/analyticalmethod/icpms',
                'prefLabel' => ['_value' => 'ICP-MS', '_lang' => 'en'],
                'broader' => [
                    [
                        '_about' => 'https://w3id.org/geochem/1.0/analyticalmethod/massspectrometry',
                        'prefLabel' => ['_value' => 'Mass spectrometry', '_lang' => 'en'],
                    ],
                ],
                'notation' => 'ICP-MS',
            ],
        ];

        $concepts = $this->parser->extractConcepts($items);

        expect($concepts[0]['broaderId'])->toBe('https://w3id.org/geochem/1.0/analyticalmethod/massspectrometry');
    });

    it('extracts broader URI from single object with _about key', function () {
        $items = [
            [
                '_about' => 'https://w3id.org/geochem/1.0/analyticalmethod/child',
                'prefLabel' => ['_value' => 'Child', '_lang' => 'en'],
                'broader' => ['_about' => 'https://w3id.org/geochem/1.0/analyticalmethod/parent'],
            ],
        ];

        $concepts = $this->parser->extractConcepts($items);

        expect($concepts[0]['broaderId'])->toBe('https://w3id.org/geochem/1.0/analyticalmethod/parent');
    });

    it('handles broader as simple string', function () {
        $items = [
            [
                '_about' => 'https://w3id.org/geochem/1.0/analyticalmethod/child',
                'prefLabel' => ['_value' => 'Child', '_lang' => 'en'],
                'broader' => 'https://w3id.org/geochem/1.0/analyticalmethod/parent',
            ],
        ];

        $concepts = $this->parser->extractConcepts($items);

        expect($concepts[0]['broaderId'])->toBe('https://w3id.org/geochem/1.0/analyticalmethod/parent');
    });

    it('returns null broaderId when broader is empty array', function () {
        $items = [
            [
                '_about' => 'https://w3id.org/geochem/1.0/analyticalmethod/root',
                'prefLabel' => ['_value' => 'Root', '_lang' => 'en'],
                'broader' => [],
            ],
        ];

        $concepts = $this->parser->extractConcepts($items);

        expect($concepts[0]['broaderId'])->toBeNull();
    });
});

describe('buildHierarchy', function () {
    it('builds tree from flat concepts with broader relationships', function () {
        $concepts = [
            ['id' => 'urn:spectroscopy', 'text' => 'Spectroscopy', 'notation' => 'SPEC', 'language' => 'en', 'broaderId' => null, 'definition' => ''],
            ['id' => 'urn:ms', 'text' => 'Mass spectrometry', 'notation' => 'MS', 'language' => 'en', 'broaderId' => 'urn:spectroscopy', 'definition' => ''],
            ['id' => 'urn:icpms', 'text' => 'ICP-MS', 'notation' => 'ICP-MS', 'language' => 'en', 'broaderId' => 'urn:ms', 'definition' => ''],
        ];

        $result = $this->parser->buildHierarchy($concepts);

        expect($result)->toHaveKeys(['lastUpdated', 'data'])
            ->and($result['data'])->toHaveCount(1)
            ->and($result['data'][0]['text'])->toBe('Spectroscopy')
            ->and($result['data'][0]['notation'])->toBe('SPEC')
            ->and($result['data'][0]['children'])->toHaveCount(1)
            ->and($result['data'][0]['children'][0]['text'])->toBe('Mass spectrometry')
            ->and($result['data'][0]['children'][0]['children'])->toHaveCount(1)
            ->and($result['data'][0]['children'][0]['children'][0]['text'])->toBe('ICP-MS');
    });

    it('includes correct scheme metadata', function () {
        $concepts = [
            ['id' => 'urn:test', 'text' => 'Test', 'notation' => 'T', 'language' => 'en', 'broaderId' => null, 'definition' => 'A test'],
        ];

        $result = $this->parser->buildHierarchy($concepts);

        expect($result['data'][0]['scheme'])->toBe('Analytical Methods for Geochemistry and Cosmochemistry')
            ->and($result['data'][0]['schemeURI'])->toBe('https://w3id.org/geochem/1.0/analyticalmethod/method')
            ->and($result['data'][0]['description'])->toBe('A test');
    });

    it('treats concepts with external broaderId as root nodes', function () {
        $concepts = [
            ['id' => 'urn:a', 'text' => 'A', 'notation' => '', 'language' => 'en', 'broaderId' => 'urn:external-not-in-dataset', 'definition' => ''],
            ['id' => 'urn:b', 'text' => 'B', 'notation' => '', 'language' => 'en', 'broaderId' => null, 'definition' => ''],
        ];

        $result = $this->parser->buildHierarchy($concepts);

        // Both should be root nodes
        expect($result['data'])->toHaveCount(2);
    });

    it('handles multiple children under single parent', function () {
        $concepts = [
            ['id' => 'urn:parent', 'text' => 'Parent', 'notation' => '', 'language' => 'en', 'broaderId' => null, 'definition' => ''],
            ['id' => 'urn:child1', 'text' => 'Child 1', 'notation' => '', 'language' => 'en', 'broaderId' => 'urn:parent', 'definition' => ''],
            ['id' => 'urn:child2', 'text' => 'Child 2', 'notation' => '', 'language' => 'en', 'broaderId' => 'urn:parent', 'definition' => ''],
        ];

        $result = $this->parser->buildHierarchy($concepts);

        expect($result['data'])->toHaveCount(1)
            ->and($result['data'][0]['children'])->toHaveCount(2);
    });
});

describe('countConcepts', function () {
    it('counts all nodes recursively', function () {
        $data = [
            [
                'text' => 'Root',
                'children' => [
                    ['text' => 'Child 1', 'children' => []],
                    ['text' => 'Child 2', 'children' => [
                        ['text' => 'Grandchild', 'children' => []],
                    ]],
                ],
            ],
        ];

        expect($this->parser->countConcepts($data))->toBe(4);
    });

    it('returns zero for empty data', function () {
        expect($this->parser->countConcepts([]))->toBe(0);
    });

    it('counts deeply nested structures', function () {
        $data = [
            [
                'text' => 'L0',
                'children' => [
                    [
                        'text' => 'L1',
                        'children' => [
                            [
                                'text' => 'L2',
                                'children' => [
                                    ['text' => 'L3', 'children' => []],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        expect($this->parser->countConcepts($data))->toBe(4);
    });
});
