<?php

declare(strict_types=1);

use App\Support\GemetVocabularyParser;

covers(GemetVocabularyParser::class);

beforeEach(function () {
    $this->parser = new GemetVocabularyParser;
});

describe('buildHierarchy', function () {
    it('builds three-level hierarchy from SuperGroups, Groups, and Concepts', function () {
        $superGroups = [
            ['uri' => 'http://gemet/supergroup/1', 'label' => 'HUMAN HEALTH', 'definition' => 'Human health topics'],
        ];

        $groups = [
            ['uri' => 'http://gemet/group/10', 'label' => 'pollution', 'definition' => 'Pollution concepts'],
        ];

        $groupToSuperGroupMap = [
            'http://gemet/group/10' => 'http://gemet/supergroup/1',
        ];

        $conceptsByGroup = [
            'http://gemet/group/10' => [
                ['uri' => 'http://gemet/concept/100', 'label' => 'water pollution', 'definition' => 'Pollution of water bodies'],
                ['uri' => 'http://gemet/concept/101', 'label' => 'air pollution', 'definition' => 'Pollution of air'],
            ],
        ];

        $result = $this->parser->buildHierarchy($superGroups, $groups, $groupToSuperGroupMap, $conceptsByGroup);

        expect($result)->toHaveKey('lastUpdated')
            ->and($result)->toHaveKey('data')
            ->and($result['data'])->toHaveCount(1)
            ->and($result['data'][0]['text'])->toBe('HUMAN HEALTH')
            ->and($result['data'][0]['children'])->toHaveCount(1)
            ->and($result['data'][0]['children'][0]['text'])->toBe('pollution')
            ->and($result['data'][0]['children'][0]['children'])->toHaveCount(2);
    });

    it('sorts concepts alphabetically', function () {
        $superGroups = [
            ['uri' => 'http://gemet/supergroup/1', 'label' => 'Test', 'definition' => ''],
        ];
        $groups = [
            ['uri' => 'http://gemet/group/10', 'label' => 'Group', 'definition' => ''],
        ];
        $groupToSuperGroupMap = ['http://gemet/group/10' => 'http://gemet/supergroup/1'];
        $conceptsByGroup = [
            'http://gemet/group/10' => [
                ['uri' => 'http://gemet/concept/1', 'label' => 'Zebra fish', 'definition' => ''],
                ['uri' => 'http://gemet/concept/2', 'label' => 'Acid rain', 'definition' => ''],
            ],
        ];

        $result = $this->parser->buildHierarchy($superGroups, $groups, $groupToSuperGroupMap, $conceptsByGroup);

        $concepts = $result['data'][0]['children'][0]['children'];
        expect($concepts[0]['text'])->toBe('Acid rain')
            ->and($concepts[1]['text'])->toBe('Zebra fish');
    });

    it('includes correct scheme metadata on every node', function () {
        $superGroups = [
            ['uri' => 'http://gemet/supergroup/1', 'label' => 'Test', 'definition' => 'Desc'],
        ];

        $result = $this->parser->buildHierarchy($superGroups, [], [], []);

        expect($result['data'][0]['scheme'])->toBe('GEMET - GEneral Multilingual Environmental Thesaurus')
            ->and($result['data'][0]['schemeURI'])->toBe('http://www.eionet.europa.eu/gemet/concept/')
            ->and($result['data'][0]['language'])->toBe('en');
    });

    it('returns empty data when no supergroups provided', function () {
        $result = $this->parser->buildHierarchy([], [], [], []);

        expect($result['data'])->toBeEmpty();
    });
});

describe('countConcepts', function () {
    it('counts flat concepts', function () {
        $data = [
            ['children' => []],
            ['children' => []],
            ['children' => []],
        ];

        expect($this->parser->countConcepts($data))->toBe(3);
    });

    it('counts nested concepts recursively', function () {
        $data = [
            [
                'children' => [
                    ['children' => [
                        ['children' => []],
                        ['children' => []],
                    ]],
                ],
            ],
        ];

        // 1 root + 1 child + 2 grandchildren = 4
        expect($this->parser->countConcepts($data))->toBe(4);
    });

    it('returns zero for empty array', function () {
        expect($this->parser->countConcepts([]))->toBe(0);
    });
});
