<?php

declare(strict_types=1);

use App\Support\ChronostratVocabularyParser;

covers(ChronostratVocabularyParser::class);

beforeEach(function () {
    $this->parser = new ChronostratVocabularyParser;
});

describe('extractConcepts', function () {
    it('extracts interval concepts with English labels', function () {
        $items = [
            [
                '_about' => 'http://resource.geosciml.org/classifier/ics/ischart/Jurassic',
                'prefLabel' => ['_value' => 'Jurassic', '_lang' => 'en'],
                'broader' => 'http://resource.geosciml.org/classifier/ics/ischart/Mesozoic',
            ],
        ];

        $concepts = $this->parser->extractConcepts($items);

        expect($concepts)->toHaveCount(1)
            ->and($concepts[0]['text'])->toBe('Jurassic')
            ->and($concepts[0]['id'])->toBe('http://resource.geosciml.org/classifier/ics/ischart/Jurassic')
            ->and($concepts[0]['broaderId'])->toBe('http://resource.geosciml.org/classifier/ics/ischart/Mesozoic');
    });

    it('filters out "Base of" boundary concepts', function () {
        $items = [
            [
                '_about' => 'http://resource.geosciml.org/classifier/ics/ischart/BaseBajocian',
                'prefLabel' => ['_value' => 'Base of Bajocian', '_lang' => 'en'],
                'broader' => [],
            ],
        ];

        $concepts = $this->parser->extractConcepts($items);

        expect($concepts)->toBeEmpty();
    });

    it('filters out "GSSP" boundary concepts', function () {
        $items = [
            [
                '_about' => 'http://resource.geosciml.org/classifier/ics/ischart/GSSPBajocian',
                'prefLabel' => ['_value' => 'GSSP for Base of Bajocian', '_lang' => 'en'],
                'broader' => [],
            ],
        ];

        $concepts = $this->parser->extractConcepts($items);

        expect($concepts)->toBeEmpty();
    });

    it('filters out "Stratotype Point" boundary concepts', function () {
        $items = [
            [
                '_about' => 'http://resource.geosciml.org/classifier/ics/ischart/StratotypeAeronian',
                'prefLabel' => ['_value' => 'Stratotype Point Base of Aeronian', '_lang' => 'en'],
                'broader' => [],
            ],
            [
                '_about' => 'http://resource.geosciml.org/classifier/ics/ischart/StratotypeBajocian',
                'prefLabel' => ['_value' => 'Stratotype Point Base of Bajocian', '_lang' => 'en'],
                'broader' => [],
            ],
        ];

        $concepts = $this->parser->extractConcepts($items);

        expect($concepts)->toBeEmpty();
    });

    it('keeps valid interval concepts while filtering all boundary types', function () {
        $items = [
            [
                '_about' => 'http://resource.geosciml.org/classifier/ics/ischart/Phanerozoic',
                'prefLabel' => ['_value' => 'Phanerozoic', '_lang' => 'en'],
                'broader' => [],
            ],
            [
                '_about' => 'http://resource.geosciml.org/classifier/ics/ischart/BasePhanerozoic',
                'prefLabel' => ['_value' => 'Base of Phanerozoic', '_lang' => 'en'],
                'broader' => [],
            ],
            [
                '_about' => 'http://resource.geosciml.org/classifier/ics/ischart/GSSPPhanerozoic',
                'prefLabel' => ['_value' => 'GSSP for Phanerozoic', '_lang' => 'en'],
                'broader' => [],
            ],
            [
                '_about' => 'http://resource.geosciml.org/classifier/ics/ischart/StratotypePhanerozoic',
                'prefLabel' => ['_value' => 'Stratotype Point Base of Phanerozoic', '_lang' => 'en'],
                'broader' => [],
            ],
        ];

        $concepts = $this->parser->extractConcepts($items);

        expect($concepts)->toHaveCount(1)
            ->and($concepts[0]['text'])->toBe('Phanerozoic');
    });

    it('skips items without English labels', function () {
        $items = [
            [
                '_about' => 'http://resource.geosciml.org/classifier/ics/ischart/Ordovicien',
                'prefLabel' => ['_value' => 'Ordovicien', '_lang' => 'fr'],
                'broader' => [],
            ],
        ];

        $concepts = $this->parser->extractConcepts($items);

        expect($concepts)->toBeEmpty();
    });

    it('handles prefLabel as array of language objects', function () {
        $items = [
            [
                '_about' => 'http://resource.geosciml.org/classifier/ics/ischart/Cambrian',
                'prefLabel' => [
                    ['_value' => 'Cambrien', '_lang' => 'fr'],
                    ['_value' => 'Cambrian', '_lang' => 'en'],
                ],
                'broader' => [],
            ],
        ];

        $concepts = $this->parser->extractConcepts($items);

        expect($concepts)->toHaveCount(1)
            ->and($concepts[0]['text'])->toBe('Cambrian');
    });
});

describe('buildHierarchy', function () {
    it('builds tree from flat concepts with broader relationships', function () {
        $concepts = [
            ['id' => 'urn:phanerozoic', 'text' => 'Phanerozoic', 'language' => 'en', 'broaderId' => null],
            ['id' => 'urn:mesozoic', 'text' => 'Mesozoic', 'language' => 'en', 'broaderId' => 'urn:phanerozoic'],
            ['id' => 'urn:jurassic', 'text' => 'Jurassic', 'language' => 'en', 'broaderId' => 'urn:mesozoic'],
        ];

        $result = $this->parser->buildHierarchy($concepts);

        expect($result)->toHaveKeys(['lastUpdated', 'data'])
            ->and($result['data'])->toHaveCount(1)
            ->and($result['data'][0]['text'])->toBe('Phanerozoic')
            ->and($result['data'][0]['children'])->toHaveCount(1)
            ->and($result['data'][0]['children'][0]['text'])->toBe('Mesozoic')
            ->and($result['data'][0]['children'][0]['children'])->toHaveCount(1)
            ->and($result['data'][0]['children'][0]['children'][0]['text'])->toBe('Jurassic');
    });

    it('includes correct scheme metadata', function () {
        $concepts = [
            ['id' => 'urn:test', 'text' => 'Test', 'language' => 'en', 'broaderId' => null],
        ];

        $result = $this->parser->buildHierarchy($concepts);

        expect($result['data'][0]['scheme'])->toBe('International Chronostratigraphic Chart')
            ->and($result['data'][0]['schemeURI'])->toBe('http://resource.geosciml.org/vocabulary/timescale/gts2020');
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
});
