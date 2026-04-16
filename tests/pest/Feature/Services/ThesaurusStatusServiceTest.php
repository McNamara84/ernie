<?php

declare(strict_types=1);

use App\Models\ThesaurusSetting;
use App\Services\ThesaurusStatusService;
use App\Support\GcmdVocabularyParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake();
    Cache::flush();
});

describe('getLocalStatus', function () {
    test('returns not exists when file is missing', function () {
        $thesaurus = ThesaurusSetting::create([
            'type' => ThesaurusSetting::TYPE_SCIENCE_KEYWORDS,
            'display_name' => 'Science Keywords',
            'is_active' => true,
            'is_elmo_active' => true,
        ]);

        $service = new ThesaurusStatusService;
        $status = $service->getLocalStatus($thesaurus);

        expect($status)->toBe([
            'exists' => false,
            'conceptCount' => 0,
            'lastUpdated' => null,
        ]);
    });

    test('returns not exists when file is empty', function () {
        Storage::put('gcmd-science-keywords.json', '');

        $thesaurus = ThesaurusSetting::create([
            'type' => ThesaurusSetting::TYPE_SCIENCE_KEYWORDS,
            'display_name' => 'Science Keywords',
            'is_active' => true,
            'is_elmo_active' => true,
        ]);

        $service = new ThesaurusStatusService;
        $status = $service->getLocalStatus($thesaurus);

        expect($status)->toBe([
            'exists' => false,
            'conceptCount' => 0,
            'lastUpdated' => null,
        ]);
    });

    test('returns not exists when file contains invalid JSON', function () {
        Storage::put('gcmd-science-keywords.json', 'not valid json {{{');

        $thesaurus = ThesaurusSetting::create([
            'type' => ThesaurusSetting::TYPE_SCIENCE_KEYWORDS,
            'display_name' => 'Science Keywords',
            'is_active' => true,
            'is_elmo_active' => true,
        ]);

        $service = new ThesaurusStatusService;
        $status = $service->getLocalStatus($thesaurus);

        expect($status)->toBe([
            'exists' => false,
            'conceptCount' => 0,
            'lastUpdated' => null,
        ]);
    });

    test('counts flat concepts correctly', function () {
        Storage::put('gcmd-platforms.json', json_encode([
            'lastUpdated' => '2025-01-10T12:00:00Z',
            'data' => [
                ['id' => '1', 'text' => 'Platform A'],
                ['id' => '2', 'text' => 'Platform B'],
                ['id' => '3', 'text' => 'Platform C'],
            ],
        ]));

        $thesaurus = ThesaurusSetting::create([
            'type' => ThesaurusSetting::TYPE_PLATFORMS,
            'display_name' => 'Platforms',
            'is_active' => true,
            'is_elmo_active' => true,
        ]);

        $service = new ThesaurusStatusService;
        $status = $service->getLocalStatus($thesaurus);

        expect($status)->toBe([
            'exists' => true,
            'conceptCount' => 3,
            'lastUpdated' => '2025-01-10T12:00:00Z',
        ]);
    });

    test('counts nested hierarchies recursively', function () {
        // Structure: 2 top-level + 3 children under first + 1 grandchild = 6 total
        Storage::put('gcmd-science-keywords.json', json_encode([
            'lastUpdated' => '2025-01-10T12:00:00Z',
            'data' => [
                [
                    'id' => '1',
                    'text' => 'Earth Science',
                    'children' => [
                        [
                            'id' => '1.1',
                            'text' => 'Atmosphere',
                            'children' => [
                                ['id' => '1.1.1', 'text' => 'Clouds'],
                            ],
                        ],
                        ['id' => '1.2', 'text' => 'Biosphere'],
                        ['id' => '1.3', 'text' => 'Cryosphere'],
                    ],
                ],
                ['id' => '2', 'text' => 'Sun-Earth Interactions'],
            ],
        ]));

        $thesaurus = ThesaurusSetting::create([
            'type' => ThesaurusSetting::TYPE_SCIENCE_KEYWORDS,
            'display_name' => 'Science Keywords',
            'is_active' => true,
            'is_elmo_active' => true,
        ]);

        $service = new ThesaurusStatusService;
        $status = $service->getLocalStatus($thesaurus);

        expect($status['exists'])->toBeTrue();
        expect($status['conceptCount'])->toBe(6);
        expect($status['lastUpdated'])->toBe('2025-01-10T12:00:00Z');
    });

    test('handles missing data key gracefully', function () {
        Storage::put('gcmd-instruments.json', json_encode([
            'lastUpdated' => '2025-01-10T12:00:00Z',
            // No 'data' key
        ]));

        $thesaurus = ThesaurusSetting::create([
            'type' => ThesaurusSetting::TYPE_INSTRUMENTS,
            'display_name' => 'Instruments',
            'is_active' => true,
            'is_elmo_active' => true,
        ]);

        $service = new ThesaurusStatusService;
        $status = $service->getLocalStatus($thesaurus);

        expect($status)->toBe([
            'exists' => true,
            'conceptCount' => 0,
            'lastUpdated' => '2025-01-10T12:00:00Z',
        ]);
    });

    test('handles missing lastUpdated key gracefully', function () {
        Storage::put('gcmd-platforms.json', json_encode([
            'data' => [
                ['id' => '1', 'text' => 'Platform A'],
            ],
        ]));

        $thesaurus = ThesaurusSetting::create([
            'type' => ThesaurusSetting::TYPE_PLATFORMS,
            'display_name' => 'Platforms',
            'is_active' => true,
            'is_elmo_active' => true,
        ]);

        $service = new ThesaurusStatusService;
        $status = $service->getLocalStatus($thesaurus);

        expect($status)->toBe([
            'exists' => true,
            'conceptCount' => 1,
            'lastUpdated' => null,
        ]);
    });

    test('returns local status for EuroSciVoc vocabulary', function () {
        Storage::put('euroscivoc.json', json_encode([
            'lastUpdated' => '2026-04-16T10:00:00+00:00',
            'data' => [
                [
                    'id' => 'http://example.org/1',
                    'text' => 'natural sciences',
                    'children' => [
                        ['id' => 'http://example.org/2', 'text' => 'physics', 'children' => []],
                    ],
                ],
            ],
        ]));

        $thesaurus = ThesaurusSetting::updateOrCreate(
            ['type' => ThesaurusSetting::TYPE_EUROSCIVOC],
            [
                'display_name' => 'European Science Vocabulary (EuroSciVoc)',
                'is_active' => true,
                'is_elmo_active' => true,
            ]
        );

        $service = new ThesaurusStatusService;
        $status = $service->getLocalStatus($thesaurus);

        expect($status)->toBe([
            'exists' => true,
            'conceptCount' => 2,
            'lastUpdated' => '2026-04-16T10:00:00+00:00',
        ]);
    });
});

describe('getRemoteConceptCount', function () {
    test('returns concept count from NASA KMS API', function () {
        $thesaurus = ThesaurusSetting::create([
            'type' => ThesaurusSetting::TYPE_SCIENCE_KEYWORDS,
            'display_name' => 'Science Keywords',
            'is_active' => true,
            'is_elmo_active' => true,
        ]);

        // Mock the NASA KMS API response with proper RDF structure
        $rdfResponse = <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"
                     xmlns:gcmd="https://gcmd.earthdata.nasa.gov/kms#">
                <gcmd:gcmd>
                    <gcmd:hits>2847</gcmd:hits>
                </gcmd:gcmd>
            </rdf:RDF>
            XML;

        Http::fake([
            'cmr.earthdata.nasa.gov/*' => Http::response($rdfResponse, 200, ['Content-Type' => 'application/rdf+xml']),
        ]);

        $service = new ThesaurusStatusService;
        $count = $service->getRemoteConceptCount($thesaurus);

        expect($count)->toBe(2847);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'sciencekeywords')
                && str_contains($request->url(), 'page_size=1');
        });
    });

    test('throws exception on API failure', function () {
        $thesaurus = ThesaurusSetting::create([
            'type' => ThesaurusSetting::TYPE_PLATFORMS,
            'display_name' => 'Platforms',
            'is_active' => true,
            'is_elmo_active' => true,
        ]);

        Http::fake([
            'cmr.earthdata.nasa.gov/*' => Http::response('Service Unavailable', 503),
        ]);

        $service = new ThesaurusStatusService;

        expect(fn () => $service->getRemoteConceptCount($thesaurus))
            ->toThrow(RuntimeException::class, 'Failed to fetch from NASA KMS API: HTTP 503');
    });

    test('throws exception on timeout', function () {
        $thesaurus = ThesaurusSetting::create([
            'type' => ThesaurusSetting::TYPE_INSTRUMENTS,
            'display_name' => 'Instruments',
            'is_active' => true,
            'is_elmo_active' => true,
        ]);

        Http::fake([
            'cmr.earthdata.nasa.gov/*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('Connection timed out'),
        ]);

        $service = new ThesaurusStatusService;

        expect(fn () => $service->getRemoteConceptCount($thesaurus))
            ->toThrow(\Illuminate\Http\Client\ConnectionException::class);
    });

    test('throws exception on invalid ARDC response format', function () {
        $thesaurus = ThesaurusSetting::firstOrCreate(
            ['type' => ThesaurusSetting::TYPE_CHRONOSTRAT],
            [
                'display_name' => 'ICS Chronostratigraphy',
                'is_active' => true,
                'is_elmo_active' => true,
            ]
        );

        Http::fake([
            'vocabs.ardc.edu.au/*' => Http::response('<html>Gateway Timeout</html>', 200, [
                'Content-Type' => 'text/html',
            ]),
        ]);

        $service = new ThesaurusStatusService;

        expect(fn () => $service->getRemoteConceptCount($thesaurus))
            ->toThrow(RuntimeException::class, 'Unexpected ARDC API response format: missing result.items array');
    });

    test('returns concept count for analytical methods via ARDC API', function () {
        $thesaurus = ThesaurusSetting::updateOrCreate(
            ['type' => ThesaurusSetting::TYPE_ANALYTICAL_METHODS],
            [
                'display_name' => 'Analytical Methods for Geochemistry',
                'is_active' => true,
                'is_elmo_active' => true,
                'version' => '1-4',
            ]
        );

        Http::fake([
            'vocabs.ardc.edu.au/*' => Http::response([
                'result' => [
                    'items' => [
                        [
                            '_about' => 'https://w3id.org/geochem/1.0/analyticalmethod/ms',
                            'prefLabel' => ['_value' => 'Mass spectrometry', '_lang' => 'en'],
                            'broader' => [],
                            'notation' => 'MS',
                        ],
                        [
                            '_about' => 'https://w3id.org/geochem/1.0/analyticalmethod/xrf',
                            'prefLabel' => ['_value' => 'XRF', '_lang' => 'en'],
                            'broader' => [],
                            'notation' => 'XRF',
                        ],
                    ],
                ],
            ]),
        ]);

        $service = new ThesaurusStatusService;
        $count = $service->getRemoteConceptCount($thesaurus);

        expect($count)->toBe(2);
    });

    test('uses version from thesaurus setting for analytical methods URL', function () {
        $thesaurus = ThesaurusSetting::updateOrCreate(
            ['type' => ThesaurusSetting::TYPE_ANALYTICAL_METHODS],
            [
                'display_name' => 'Analytical Methods for Geochemistry',
                'is_active' => true,
                'is_elmo_active' => true,
                'version' => '2-0',
            ]
        );

        Http::fake([
            'vocabs.ardc.edu.au/*' => Http::response([
                'result' => [
                    'items' => [
                        [
                            '_about' => 'https://w3id.org/geochem/1.0/analyticalmethod/ms',
                            'prefLabel' => ['_value' => 'Mass spectrometry', '_lang' => 'en'],
                            'broader' => [],
                        ],
                    ],
                ],
            ]),
        ]);

        $service = new ThesaurusStatusService;
        $service->getRemoteConceptCount($thesaurus);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/2-0/concept.json');
        });
    });

    test('fetches EuroSciVoc remote count via SPARQL endpoint', function () {
        $thesaurus = ThesaurusSetting::updateOrCreate(
            ['type' => ThesaurusSetting::TYPE_EUROSCIVOC],
            [
                'display_name' => 'European Science Vocabulary (EuroSciVoc)',
                'is_active' => true,
                'is_elmo_active' => true,
            ]
        );

        Http::fake([
            'publications.europa.eu/*' => Http::response([
                'results' => [
                    'bindings' => [
                        ['count' => ['value' => '1064']],
                    ],
                ],
            ]),
        ]);

        $service = new ThesaurusStatusService;
        $count = $service->getRemoteConceptCount($thesaurus);

        expect($count)->toBe(1064);
    });

    test('throws on EuroSciVoc SPARQL endpoint failure', function () {
        $thesaurus = ThesaurusSetting::updateOrCreate(
            ['type' => ThesaurusSetting::TYPE_EUROSCIVOC],
            [
                'display_name' => 'European Science Vocabulary (EuroSciVoc)',
                'is_active' => true,
                'is_elmo_active' => true,
            ]
        );

        Http::fake([
            'publications.europa.eu/*' => Http::response('Server Error', 500),
        ]);

        $service = new ThesaurusStatusService;

        expect(fn () => $service->getRemoteConceptCount($thesaurus))
            ->toThrow(RuntimeException::class, 'Failed to query EuroSciVoc SPARQL endpoint');
    });

    test('throws on unexpected EuroSciVoc SPARQL response format', function () {
        $thesaurus = ThesaurusSetting::updateOrCreate(
            ['type' => ThesaurusSetting::TYPE_EUROSCIVOC],
            [
                'display_name' => 'European Science Vocabulary (EuroSciVoc)',
                'is_active' => true,
                'is_elmo_active' => true,
            ]
        );

        Http::fake([
            'publications.europa.eu/*' => Http::response(['unexpected' => 'format'], 200),
        ]);

        $service = new ThesaurusStatusService;

        expect(fn () => $service->getRemoteConceptCount($thesaurus))
            ->toThrow(RuntimeException::class, 'Unexpected EuroSciVoc SPARQL response format');
    });
});

describe('compareWithRemote', function () {
    test('identifies update available when remote has more concepts', function () {
        Storage::put('gcmd-science-keywords.json', json_encode([
            'lastUpdated' => '2025-01-01T00:00:00Z',
            'data' => [
                ['id' => '1', 'text' => 'Concept 1'],
                ['id' => '2', 'text' => 'Concept 2'],
            ],
        ]));

        $thesaurus = ThesaurusSetting::create([
            'type' => ThesaurusSetting::TYPE_SCIENCE_KEYWORDS,
            'display_name' => 'Science Keywords',
            'is_active' => true,
            'is_elmo_active' => true,
        ]);

        $rdfResponse = <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"
                     xmlns:gcmd="https://gcmd.earthdata.nasa.gov/kms#">
                <gcmd:gcmd>
                    <gcmd:hits>5</gcmd:hits>
                </gcmd:gcmd>
            </rdf:RDF>
            XML;

        Http::fake([
            'cmr.earthdata.nasa.gov/*' => Http::response($rdfResponse, 200),
        ]);

        $service = new ThesaurusStatusService;
        $result = $service->compareWithRemote($thesaurus);

        expect($result)->toBe([
            'localCount' => 2,
            'remoteCount' => 5,
            'updateAvailable' => true,
            'lastUpdated' => '2025-01-01T00:00:00Z',
        ]);
    });

    test('no update available when counts are equal', function () {
        Storage::put('gcmd-platforms.json', json_encode([
            'lastUpdated' => '2025-01-10T12:00:00Z',
            'data' => [
                ['id' => '1', 'text' => 'Platform 1'],
                ['id' => '2', 'text' => 'Platform 2'],
                ['id' => '3', 'text' => 'Platform 3'],
            ],
        ]));

        $thesaurus = ThesaurusSetting::create([
            'type' => ThesaurusSetting::TYPE_PLATFORMS,
            'display_name' => 'Platforms',
            'is_active' => true,
            'is_elmo_active' => true,
        ]);

        $rdfResponse = <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"
                     xmlns:gcmd="https://gcmd.earthdata.nasa.gov/kms#">
                <gcmd:gcmd>
                    <gcmd:hits>3</gcmd:hits>
                </gcmd:gcmd>
            </rdf:RDF>
            XML;

        Http::fake([
            'cmr.earthdata.nasa.gov/*' => Http::response($rdfResponse, 200),
        ]);

        $service = new ThesaurusStatusService;
        $result = $service->compareWithRemote($thesaurus);

        expect($result['updateAvailable'])->toBeFalse();
        expect($result['localCount'])->toBe(3);
        expect($result['remoteCount'])->toBe(3);
    });

    test('no update available when remote has fewer concepts', function () {
        // Edge case: local has more concepts than remote
        // This could happen if NASA removes concepts (rare) or API returns stale data
        Storage::put('gcmd-instruments.json', json_encode([
            'lastUpdated' => '2025-01-10T12:00:00Z',
            'data' => [
                ['id' => '1', 'text' => 'Instrument 1'],
                ['id' => '2', 'text' => 'Instrument 2'],
                ['id' => '3', 'text' => 'Instrument 3'],
                ['id' => '4', 'text' => 'Instrument 4'],
                ['id' => '5', 'text' => 'Instrument 5'],
            ],
        ]));

        $thesaurus = ThesaurusSetting::create([
            'type' => ThesaurusSetting::TYPE_INSTRUMENTS,
            'display_name' => 'Instruments',
            'is_active' => true,
            'is_elmo_active' => true,
        ]);

        $rdfResponse = <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"
                     xmlns:gcmd="https://gcmd.earthdata.nasa.gov/kms#">
                <gcmd:gcmd>
                    <gcmd:hits>3</gcmd:hits>
                </gcmd:gcmd>
            </rdf:RDF>
            XML;

        Http::fake([
            'cmr.earthdata.nasa.gov/*' => Http::response($rdfResponse, 200),
        ]);

        $service = new ThesaurusStatusService;
        $result = $service->compareWithRemote($thesaurus);

        // Should NOT trigger update when remote < local
        expect($result['updateAvailable'])->toBeFalse();
        expect($result['localCount'])->toBe(5);
        expect($result['remoteCount'])->toBe(3);
    });

    test('update available for fresh install with no local file', function () {
        // No local file exists
        $thesaurus = ThesaurusSetting::create([
            'type' => ThesaurusSetting::TYPE_SCIENCE_KEYWORDS,
            'display_name' => 'Science Keywords',
            'is_active' => true,
            'is_elmo_active' => true,
        ]);

        $rdfResponse = <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"
                     xmlns:gcmd="https://gcmd.earthdata.nasa.gov/kms#">
                <gcmd:gcmd>
                    <gcmd:hits>2847</gcmd:hits>
                </gcmd:gcmd>
            </rdf:RDF>
            XML;

        Http::fake([
            'cmr.earthdata.nasa.gov/*' => Http::response($rdfResponse, 200),
        ]);

        $service = new ThesaurusStatusService;
        $result = $service->compareWithRemote($thesaurus);

        expect($result['updateAvailable'])->toBeTrue();
        expect($result['localCount'])->toBe(0);
        expect($result['remoteCount'])->toBe(2847);
        expect($result['lastUpdated'])->toBeNull();
    });
});

describe('GEMET thesaurus', function () {
    test('getLocalStatus returns correct count for GEMET hierarchy', function () {
        $gemetData = [
            'lastUpdated' => '2026-04-16T00:00:00+00:00',
            'data' => [
                [
                    'text' => 'SuperGroup1',
                    'children' => [
                        [
                            'text' => 'Group1',
                            'children' => [
                                ['text' => 'Concept1', 'children' => []],
                                ['text' => 'Concept2', 'children' => []],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        Storage::put('gemet-thesaurus.json', json_encode($gemetData));

        $thesaurus = ThesaurusSetting::updateOrCreate(
            ['type' => ThesaurusSetting::TYPE_GEMET],
            [
                'display_name' => 'GEMET',
                'is_active' => true,
                'is_elmo_active' => false,
            ],
        );

        $service = new ThesaurusStatusService;
        $status = $service->getLocalStatus($thesaurus);

        // 1 supergroup + 1 group + 2 concepts = 4
        expect($status['exists'])->toBeTrue()
            ->and($status['conceptCount'])->toBe(4)
            ->and($status['lastUpdated'])->toBe('2026-04-16T00:00:00+00:00');
    });

    test('getRemoteConceptCount returns count from GEMET API', function () {
        $thesaurus = ThesaurusSetting::updateOrCreate(
            ['type' => ThesaurusSetting::TYPE_GEMET],
            [
                'display_name' => 'GEMET',
                'is_active' => true,
                'is_elmo_active' => false,
            ],
        );

        Http::fake(function (\Illuminate\Http\Client\Request $request) {
            $url = $request->url();
            $params = $request->data();
            $thesaurusUri = $params['thesaurus_uri'] ?? '';

            // SuperGroups
            if (str_contains($url, 'getTopmostConcepts') && str_contains($thesaurusUri, 'supergroup')) {
                return Http::response([
                    ['uri' => 'http://gemet/supergroup/1', 'preferredLabel' => ['string' => 'SG1', 'language' => 'en'], 'definition' => ['string' => '', 'language' => 'en']],
                    ['uri' => 'http://gemet/supergroup/2', 'preferredLabel' => ['string' => 'SG2', 'language' => 'en'], 'definition' => ['string' => '', 'language' => 'en']],
                ], 200);
            }

            // Groups
            if (str_contains($url, 'getTopmostConcepts') && str_contains($thesaurusUri, 'group')) {
                return Http::response([
                    ['uri' => 'http://gemet/group/10', 'preferredLabel' => ['string' => 'G1', 'language' => 'en'], 'definition' => ['string' => '', 'language' => 'en']],
                    ['uri' => 'http://gemet/group/20', 'preferredLabel' => ['string' => 'G2', 'language' => 'en'], 'definition' => ['string' => '', 'language' => 'en']],
                    ['uri' => 'http://gemet/group/30', 'preferredLabel' => ['string' => 'G3', 'language' => 'en'], 'definition' => ['string' => '', 'language' => 'en']],
                ], 200);
            }

            // Concept counts per group (via pool)
            if (str_contains($url, 'getRelatedConcepts')) {
                return Http::response([
                    ['uri' => 'http://gemet/concept/1', 'preferredLabel' => ['string' => 'C1', 'language' => 'en'], 'definition' => ['string' => '', 'language' => 'en']],
                    ['uri' => 'http://gemet/concept/2', 'preferredLabel' => ['string' => 'C2', 'language' => 'en'], 'definition' => ['string' => '', 'language' => 'en']],
                ], 200);
            }

            return Http::response([], 404);
        });

        $service = new ThesaurusStatusService;
        $count = $service->getRemoteConceptCount($thesaurus);

        // 2 supergroups + 3 groups + (3 groups × 2 concepts each) = 11
        expect($count)->toBe(11);
    });

    test('compareWithRemote detects GEMET update available', function () {
        $gemetData = [
            'lastUpdated' => '2026-01-01T00:00:00+00:00',
            'data' => [
                ['text' => 'SG1', 'children' => []],
            ],
        ];

        Storage::put('gemet-thesaurus.json', json_encode($gemetData));

        $thesaurus = ThesaurusSetting::updateOrCreate(
            ['type' => ThesaurusSetting::TYPE_GEMET],
            [
                'display_name' => 'GEMET',
                'is_active' => true,
                'is_elmo_active' => false,
            ],
        );

        Http::fake(function (\Illuminate\Http\Client\Request $request) {
            $url = $request->url();
            $params = $request->data();
            $thesaurusUri = $params['thesaurus_uri'] ?? '';

            if (str_contains($url, 'getTopmostConcepts') && str_contains($thesaurusUri, 'supergroup')) {
                return Http::response([
                    ['uri' => 'http://gemet/supergroup/1', 'preferredLabel' => ['string' => 'SG1', 'language' => 'en'], 'definition' => ['string' => '', 'language' => 'en']],
                ], 200);
            }

            if (str_contains($url, 'getTopmostConcepts') && str_contains($thesaurusUri, 'group')) {
                return Http::response([
                    ['uri' => 'http://gemet/group/10', 'preferredLabel' => ['string' => 'G1', 'language' => 'en'], 'definition' => ['string' => '', 'language' => 'en']],
                ], 200);
            }

            if (str_contains($url, 'getRelatedConcepts')) {
                return Http::response([
                    ['uri' => 'http://gemet/concept/1', 'preferredLabel' => ['string' => 'C1', 'language' => 'en'], 'definition' => ['string' => '', 'language' => 'en']],
                    ['uri' => 'http://gemet/concept/2', 'preferredLabel' => ['string' => 'C2', 'language' => 'en'], 'definition' => ['string' => '', 'language' => 'en']],
                    ['uri' => 'http://gemet/concept/3', 'preferredLabel' => ['string' => 'C3', 'language' => 'en'], 'definition' => ['string' => '', 'language' => 'en']],
                ], 200);
            }

            return Http::response([], 404);
        });

        $service = new ThesaurusStatusService;
        $result = $service->compareWithRemote($thesaurus);

        // Local: 1 node. Remote: 1 supergroup + 1 group + 3 concepts = 5
        expect($result['updateAvailable'])->toBeTrue()
            ->and($result['localCount'])->toBe(1)
            ->and($result['remoteCount'])->toBe(5);
    });
});
