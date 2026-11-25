<?php

declare(strict_types=1);

use App\Services\OrcidService;
use App\Services\RorLookupService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Helpers\NativeSleep;

covers(OrcidService::class);

beforeEach(function () {
    NativeSleep::fake();
    // Provider behavior is tested here; reservation timing has dedicated tests.
    config(['services.orcid.rate_limit_delay_ms' => 0]);
    $this->service = new OrcidService(new RorLookupService);
    Cache::flush();
});

afterEach(function () {
    NativeSleep::restore();
});

it('reuses successful verification briefly without suppressing later invalidation', function () {
    Http::fakeSequence()->push([], 200)->push([], 404);
    expect($this->service->validateOrcid('0000-0002-1825-0097')['exists'])->toBeTrue()
        ->and($this->service->validateOrcid('https://orcid.org/0000-0002-1825-0097')['exists'])->toBeTrue();
    Http::assertSentCount(1);
    $this->travel(301)->seconds();
    expect($this->service->validateOrcid('0000-0002-1825-0097')['errorType'])->toBe('not_found');
    Http::assertSentCount(2);
});

describe('Format validation', function () {
    it('validates correct ORCID formats', function () {
        expect($this->service->validateOrcidFormat('0000-0001-2345-6789'))->toBeTrue();
        expect($this->service->validateOrcidFormat('0000-0002-1825-0097'))->toBeTrue();
        expect($this->service->validateOrcidFormat('0000-0001-5000-000X'))->toBeTrue(); // With X check digit
    });

    it('rejects invalid ORCID formats', function () {
        expect($this->service->validateOrcidFormat('0000-0001-2345-678'))->toBeFalse(); // Too short
        expect($this->service->validateOrcidFormat('0000-0001-2345-67890'))->toBeFalse(); // Too long
        expect($this->service->validateOrcidFormat('000-0001-2345-6789'))->toBeFalse(); // Wrong format
        expect($this->service->validateOrcidFormat('invalid-orcid'))->toBeFalse();
        expect($this->service->validateOrcidFormat(''))->toBeFalse();
    });
});

describe('ORCID validation', function () {
    it('returns invalid for wrong ORCID format', function () {
        $result = $this->service->validateOrcid('invalid-format');

        expect($result['valid'])->toBeFalse();
        expect($result['exists'])->toBeNull();
        expect($result['message'])->toContain('Invalid ORCID format');
    });

    it('validates existing ORCID', function () {
        Http::fake([
            'pub.orcid.org/v3.0/0000-0001-2345-6789/person' => Http::response([
                'name' => [
                    'given-names' => ['value' => 'John'],
                    'family-name' => ['value' => 'Doe'],
                ],
            ], 200),
        ]);

        $result = $this->service->validateOrcid('0000-0001-2345-6789');

        expect($result['valid'])->toBeTrue();
        expect($result['exists'])->toBeTrue();
        expect($result['message'])->toBe('Valid ORCID ID');
    });

    it('detects non-existing ORCID', function () {
        Http::fake([
            'pub.orcid.org/v3.0/0000-0001-2345-6789/person' => Http::response(null, 404),
        ]);

        $result = $this->service->validateOrcid('0000-0001-2345-6789');

        expect($result['valid'])->toBeTrue();
        expect($result['exists'])->toBeFalse();
        expect($result['message'])->toBe('ORCID ID not found');
    });
});

describe('Fetching records', function () {
    it('fetches ORCID record successfully', function () {
        $mockFullRecord = [
            'person' => [
                'name' => [
                    'given-names' => ['value' => 'Albert'],
                    'family-name' => ['value' => 'Einstein'],
                    'credit-name' => ['value' => 'Prof. Albert Einstein'],
                ],
                'emails' => [
                    'email' => [
                        ['email' => 'albert@example.com'],
                    ],
                ],
            ],
            'activities-summary' => [
                'employments' => [
                    'affiliation-group' => [
                        [
                            'summaries' => [
                                [
                                    'employment-summary' => [
                                        'organization' => ['name' => 'Princeton University'],
                                        'role-title' => 'Professor',
                                        'department-name' => 'Physics',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'educations' => [
                    'affiliation-group' => [],
                ],
            ],
        ];

        Http::fake([
            'pub.orcid.org/v3.0/0000-0001-2345-6789' => Http::response($mockFullRecord, 200),
        ]);

        $result = $this->service->fetchOrcidRecord('0000-0001-2345-6789');

        expect($result['success'])->toBeTrue();
        expect($result['error'])->toBeNull();

        $data = $result['data'];
        expect($data['orcid'])->toBe('0000-0001-2345-6789');
        expect($data['firstName'])->toBe('Albert');
        expect($data['lastName'])->toBe('Einstein');
        expect($data['creditName'])->toBe('Prof. Albert Einstein');
        expect($data['emails'])->toContain('albert@example.com');
        expect($data['affiliations'])->toHaveCount(1);
        expect($data['affiliations'][0]['name'])->toBe('Princeton University');
    });

    it('returns error for invalid ORCID format on fetch', function () {
        $result = $this->service->fetchOrcidRecord('invalid-format');

        expect($result['success'])->toBeFalse();
        expect($result['data'])->toBeNull();
        expect($result['error'])->toBe('Invalid ORCID format');
    });

    it('returns error for non-existing ORCID on fetch', function () {
        Http::fake([
            'pub.orcid.org/v3.0/0000-0001-2345-6789' => Http::response(null, 404),
        ]);

        $result = $this->service->fetchOrcidRecord('0000-0001-2345-6789');

        expect($result['success'])->toBeFalse();
        expect($result['data'])->toBeNull();
        expect($result['error'])->toBe('ORCID not found');
    });
});

describe('Caching', function () {
    it('caches ORCID records', function () {
        $mockFullRecord = [
            'person' => [
                'name' => [
                    'given-names' => ['value' => 'John'],
                    'family-name' => ['value' => 'Doe'],
                ],
                'emails' => ['email' => []],
            ],
            'activities-summary' => [
                'employments' => ['affiliation-group' => []],
                'educations' => ['affiliation-group' => []],
            ],
        ];

        Http::fake([
            'pub.orcid.org/v3.0/0000-0001-2345-6789' => Http::response($mockFullRecord, 200),
        ]);

        // First call
        $result1 = $this->service->fetchOrcidRecord('0000-0001-2345-6789');
        expect($result1['success'])->toBeTrue();

        // Second call should be from cache
        Http::assertSentCount(1); // Only one API call

        $result2 = $this->service->fetchOrcidRecord('0000-0001-2345-6789');
        expect($result2['success'])->toBeTrue();
        expect($result2['data'])->toBe($result1['data']);

        // Still only one API call (second was cached)
        Http::assertSentCount(1);
    });

    it('caches negative result for 404', function () {
        Http::fake([
            'pub.orcid.org/v3.0/0000-0002-1825-0097/person' => Http::response(null, 404),
        ]);

        // First call
        $firstResult = $this->service->validateOrcid('0000-0002-1825-0097');
        expect($firstResult['errorType'])->toBe('not_found');

        // Second call should use cache
        $result = $this->service->validateOrcid('0000-0002-1825-0097');
        expect($result['exists'])->toBeFalse();
        expect($result['errorType'])->toBe('not_found');

        // Verify only one HTTP call was made
        Http::assertSentCount(1);
    });
});

describe('Search', function () {
    it('searches ORCID successfully', function () {
        $mockSearchResults = [
            'num-found' => 2,
            'result' => [
                [
                    'orcid-identifier' => ['path' => '0000-0001-2345-6789'],
                ],
                [
                    'orcid-identifier' => ['path' => '0000-0001-9876-5432'],
                ],
            ],
        ];

        Http::fake([
            'pub.orcid.org/v3.0/search*' => Http::response($mockSearchResults, 200),
            'pub.orcid.org/v3.0/0000-0001-2345-6789' => Http::response([
                'person' => [
                    'name' => [
                        'given-names' => ['value' => 'Albert'],
                        'family-name' => ['value' => 'Einstein'],
                        'credit-name' => ['value' => 'Prof. Albert Einstein'],
                    ],
                    'emails' => ['email' => []],
                ],
                'activities-summary' => [
                    'employments' => [
                        'affiliation-group' => [
                            [
                                'summaries' => [
                                    [
                                        'employment-summary' => [
                                            'organization' => ['name' => 'Princeton University'],
                                            'role-title' => 'Professor',
                                            'department-name' => 'Physics',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'educations' => ['affiliation-group' => []],
                ],
            ], 200),
            'pub.orcid.org/v3.0/0000-0001-9876-5432' => Http::response([
                'person' => [
                    'name' => [
                        'given-names' => ['value' => 'Albert'],
                        'family-name' => ['value' => 'Einstein Jr'],
                    ],
                    'emails' => ['email' => []],
                ],
                'activities-summary' => [
                    'employments' => ['affiliation-group' => []],
                    'educations' => ['affiliation-group' => []],
                ],
            ], 200),
        ]);

        $result = $this->service->searchOrcid('Albert Einstein', 10);

        expect($result['success'])->toBeTrue();
        expect($result['error'])->toBeNull();
        expect($result['data']['total'])->toBe(2);
        expect($result['data']['results'])->toHaveCount(2);

        $firstResult = $result['data']['results'][0];
        expect($firstResult['orcid'])->toBe('0000-0001-2345-6789');
        expect($firstResult['firstName'])->toBe('Albert');
        expect($firstResult['lastName'])->toBe('Einstein');
    });

    it('returns error for empty search query', function () {
        $result = $this->service->searchOrcid('', 10);

        expect($result['success'])->toBeFalse();
        expect($result['data'])->toBeNull();
        expect($result['error'])->toBe('Search query is required');
    });

    it('limits search results to maximum', function () {
        Http::fake([
            'pub.orcid.org/v3.0/search*' => Http::response([
                'num-found' => 0,
                'result' => [],
            ], 200),
        ]);

        $this->service->searchOrcid('Test Query', 300); // Request more than max

        Http::assertSent(function ($request) {
            $url = $request->url();
            parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $params);

            return str_contains($url, 'pub.orcid.org/v3.0/search')
                && isset($params['rows'])
                && (int) $params['rows'] === 200;
        });
    });
});

describe('Expanded person search', function () {
    it('sends a quoted expression in a single expanded search request', function (string $input, string $expected) {
        Http::fake(['*/expanded-search/*' => Http::response(['num-found' => 0, 'expanded-result' => null])]);

        expect($this->service->searchPeople($input))->toBe(['success' => true, 'data' => ['results' => [], 'total' => 0]]);
        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) use ($expected): bool {
            parse_str(parse_url($request->url(), PHP_URL_QUERY), $params);

            return parse_url($request->url(), PHP_URL_PATH) === '/v3.0/expanded-search/'
                && $request->hasHeader('Accept', 'application/vnd.orcid+json')
                && $params === ['q' => $expected, 'rows' => '10'];
        });
    })->with([
        'short surname' => ['Li', '(given-names:"Li" OR family-name:"Li" OR other-names:"Li")'],
        'surname with a trailing comma' => ['Li,', '(given-names:"Li" OR family-name:"Li" OR other-names:"Li")'],
        'given name first' => ['Holger   Ehrmann', 'family-name:"Ehrmann" AND given-names:"Holger"'],
        'family name first' => ['  Ehrmann , Holger ', 'family-name:"Ehrmann" AND given-names:"Holger"'],
        'institution' => ['Ehrmann, Holger, GFZ', 'family-name:"Ehrmann" AND given-names:"Holger" AND affiliation-org-name:"GFZ"'],
        'trailing comma' => ['Ehrmann, Holger,', 'family-name:"Ehrmann" AND given-names:"Holger"'],
        'compound names' => ["van der Meer, Ana-María Renée, Institut d'Études, Paris", "family-name:\"van der Meer\" AND given-names:\"Ana-María Renée\" AND affiliation-org-name:\"Institut d'Études, Paris\""],
        'literal punctuation' => ['Taylor, Alex, A\B "West"', 'family-name:"Taylor" AND given-names:"Alex" AND affiliation-org-name:"A\\\\B \"West\""'],
    ]);

    it('rejects incomplete input before contacting ORCID', function (string $input) {
        Http::fake();
        expect($this->service->searchPeople($input))->toMatchArray(['success' => false, 'status' => 422]);
        Http::assertNothingSent();
    })->with([' ', 'A,', ',Holger', 'Ehrmann,,GFZ', str_repeat('a', 201)]);

    it('returns twenty public summaries and the full count without requesting profiles', function () {
        $rows = [];
        for ($index = 0; $index < 20; $index++) {
            $rows[] = ['orcid-id' => sprintf('0000-0000-0000-%04d', $index)];
        }
        $rows[0] += ['given-names' => 'Holger', 'family-names' => 'Ehrmann', 'credit-name' => 'H. Ehrmann', 'institution-name' => ['GFZ', 'Example University']];
        $rows[1]['institution-name'] = null;
        $rows[2]['orcid-id'] = 'https://orcid.org/0000-0002-9079-593x';
        Http::fake(['*/expanded-search/*' => Http::response(['num-found' => 84, 'expanded-result' => $rows])]);

        $response = $this->service->searchPeople('Holger Ehrmann', 20);
        expect($response['data']['total'])->toBe(84)
            ->and($response['data']['results'])->toHaveCount(20)
            ->and($response['data']['results'][0])->toBe([
                'orcid' => $rows[0]['orcid-id'], 'firstName' => 'Holger', 'lastName' => 'Ehrmann', 'creditName' => 'H. Ehrmann', 'institutions' => ['GFZ', 'Example University'],
            ])
            ->and($response['data']['results'][1])->toBe([
                'orcid' => $rows[1]['orcid-id'], 'firstName' => '', 'lastName' => '', 'creditName' => null, 'institutions' => [],
            ])
            ->and($response['data']['results'][2]['orcid'])->toBe('0000-0002-9079-593X');
        Http::assertSent(fn ($request) => str_contains($request->url(), 'rows=20'));
        Http::assertSentCount(1);
    });

    it('distinguishes service failures from empty results', function (mixed $body, int $status, int $expectedStatus) {
        Http::fake(['*/expanded-search/*' => Http::response($body, $status)]);
        expect($this->service->searchPeople('Li'))->toMatchArray(['success' => false, 'status' => $expectedStatus]);
    })->with([
        'provider rate limit' => [[], 429, 429],
        'provider outage' => [[], 500, 503],
        'invalid JSON' => ['Not JSON', 200, 503],
        'missing rows' => [['num-found' => 2], 200, 503],
        'inconsistent total' => [['num-found' => 0, 'expanded-result' => [['orcid-id' => '0000-0002-1825-0097']]], 200, 503],
        'row missing identity' => [['num-found' => 1, 'expanded-result' => [['given-names' => 'Alex']]], 200, 503],
        'unusable institutions' => [['num-found' => 1, 'expanded-result' => [['orcid-id' => '0000-0002-1825-0097', 'institution-name' => 'GFZ']]], 200, 503],
        'invalid institution entry' => [['num-found' => 1, 'expanded-result' => [['orcid-id' => '0000-0002-1825-0097', 'institution-name' => [42]]]], 200, 503],
    ]);

    it('returns a retryable failure on a connection error', function () {
        Http::fake(['*/expanded-search/*' => Http::failedConnection()]);
        expect($this->service->searchPeople('Li'))->toMatchArray(['success' => false, 'status' => 503]);
    });
});

describe('Checksum validation', function () {
    it('validates ORCID checksum correctly', function () {
        // Valid checksums (verified against ORCID spec)
        expect($this->service->validateOrcidChecksum('0000-0002-1825-0097'))->toBeTrue();
        expect($this->service->validateOrcidChecksum('0000-0001-5109-3700'))->toBeTrue();
        expect($this->service->validateOrcidChecksum('0000-0002-9079-593X'))->toBeTrue(); // With X checksum
        expect($this->service->validateOrcidChecksum('0000-0002-0275-1903'))->toBeTrue(); // Issue #403 ORCID

        // Invalid checksums
        expect($this->service->validateOrcidChecksum('0000-0002-1825-0098'))->toBeFalse(); // Wrong check digit
        expect($this->service->validateOrcidChecksum('0000-0000-0000-0000'))->toBeFalse(); // Invalid ORCID
        expect($this->service->validateOrcidChecksum('1234-5678-9012-3456'))->toBeFalse(); // Random invalid
    });
});

describe('Error types', function () {
    it('returns checksum error type for invalid checksum', function () {
        $result = $this->service->validateOrcid('0000-0002-1825-0098'); // Wrong checksum

        expect($result['valid'])->toBeFalse();
        expect($result['exists'])->toBeNull();
        expect($result['errorType'])->toBe('checksum');
        expect($result['message'])->toContain('checksum');
    });

    it('returns format error type for invalid format', function () {
        $result = $this->service->validateOrcid('invalid-format');

        expect($result['valid'])->toBeFalse();
        expect($result['errorType'])->toBe('format');
    });

    it('returns not_found error type for 404', function () {
        Http::fake([
            'pub.orcid.org/v3.0/0000-0002-1825-0097/person' => Http::response(null, 404),
        ]);

        $result = $this->service->validateOrcid('0000-0002-1825-0097');

        expect($result['valid'])->toBeTrue();
        expect($result['exists'])->toBeFalse();
        expect($result['errorType'])->toBe('not_found');
    });

    it('returns api_error type for server errors', function () {
        Http::fake([
            'pub.orcid.org/v3.0/0000-0002-1825-0097/person' => Http::sequence()
                ->push(null, 500)
                ->push(null, 500)
                ->push(null, 500),
        ]);

        $result = $this->service->validateOrcid('0000-0002-1825-0097');

        expect($result['valid'])->toBeTrue();
        expect($result['exists'])->toBeNull();
        expect($result['errorType'])->toBe('api_error');
    });

    it('returns null error type for successful validation', function () {
        Http::fake([
            'pub.orcid.org/v3.0/0000-0002-1825-0097/person' => Http::response(['name' => []], 200),
        ]);

        $result = $this->service->validateOrcid('0000-0002-1825-0097');

        expect($result['valid'])->toBeTrue();
        expect($result['exists'])->toBeTrue();
        expect($result['errorType'])->toBeNull();
    });

    it('returns timeout error type after connection failures', function () {
        Http::fake(fn () => throw new ConnectionException('Connection timed out'));

        $result = $this->service->validateOrcid('0000-0002-1825-0097');

        expect($result['valid'])->toBeTrue();
        expect($result['exists'])->toBeNull();
        expect($result['errorType'])->toBe('timeout');
        expect(strtolower($result['message']))->toContain('could not verify');
    });
});

describe('Retries', function () {
    it('retries on server error before returning api_error', function () {
        Http::fake([
            'pub.orcid.org/v3.0/0000-0002-1825-0097/person' => Http::sequence()
                ->push(null, 500)
                ->push(null, 500)
                ->push(null, 500),
        ]);

        $result = $this->service->validateOrcid('0000-0002-1825-0097');

        expect($result['valid'])->toBeTrue();
        expect($result['exists'])->toBeNull();
        expect($result['errorType'])->toBe('api_error');

        // Verify all 3 retry attempts were made
        Http::assertSentCount(3);
        expect(NativeSleep::seconds())->toBe([2, 4]);
    });

    it('retries on rate limit 429 error', function () {
        Http::fake([
            'pub.orcid.org/v3.0/0000-0002-1825-0097/person' => Http::sequence()
                ->push(null, 429)
                ->push(null, 429)
                ->push(['name' => []], 200),
        ]);

        $result = $this->service->validateOrcid('0000-0002-1825-0097');

        expect($result['valid'])->toBeTrue();
        expect($result['exists'])->toBeTrue();
        expect($result['errorType'])->toBeNull();

        // Verify all 3 attempts were made (2 retries + final success)
        Http::assertSentCount(3);
        expect(NativeSleep::seconds())->toBe([2, 4]);
    });

    it('honors maxAttempts=1 for preflight to avoid retry/backoff budget', function () {
        Http::fake([
            'pub.orcid.org/v3.0/0000-0002-1825-0097/person' => Http::sequence()
                ->push(null, 500)
                ->push(null, 500)
                ->push(['name' => []], 200),
        ]);

        $result = $this->service->validateOrcid(
            '0000-0002-1825-0097',
            maxAttempts: OrcidService::PREFLIGHT_MAX_ATTEMPTS,
            timeoutSeconds: OrcidService::PREFLIGHT_VALIDATION_TIMEOUT,
        );

        // No retry → first 500 immediately surfaces as api_error.
        expect($result['errorType'])->toBe('api_error');
        Http::assertSentCount(1);
        expect(NativeSleep::seconds())->toBeEmpty();
    });

    it('honors maxAttempts=1 for preflight on connection failures', function () {
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;
            throw new ConnectionException('timed out');
        });

        $result = $this->service->validateOrcid(
            '0000-0002-1825-0097',
            maxAttempts: OrcidService::PREFLIGHT_MAX_ATTEMPTS,
            timeoutSeconds: OrcidService::PREFLIGHT_VALIDATION_TIMEOUT,
        );

        expect($result['errorType'])->toBe('timeout');
        expect($attempts)->toBe(1);
        expect(NativeSleep::seconds())->toBeEmpty();
    });
});

describe('ROR ID extraction from affiliations', function () {
    it('extracts ROR ID from employment disambiguated-organization', function () {
        $mockFullRecord = [
            'person' => [
                'name' => [
                    'given-names' => ['value' => 'Jane'],
                    'family-name' => ['value' => 'Doe'],
                ],
                'emails' => ['email' => []],
            ],
            'activities-summary' => [
                'employments' => [
                    'affiliation-group' => [
                        [
                            'summaries' => [
                                [
                                    'employment-summary' => [
                                        'organization' => [
                                            'name' => 'GFZ German Research Centre for Geosciences',
                                            'disambiguated-organization' => [
                                                'disambiguated-organization-identifier' => 'https://ror.org/04z8jg394',
                                                'disambiguation-source' => 'ROR',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'educations' => ['affiliation-group' => []],
            ],
        ];

        Http::fake([
            'pub.orcid.org/v3.0/0000-0002-1825-0097' => Http::response($mockFullRecord, 200),
        ]);

        $result = $this->service->fetchOrcidRecord('0000-0002-1825-0097');

        expect($result['success'])->toBeTrue();
        expect($result['data']['affiliations'])->toHaveCount(1);
        expect($result['data']['affiliations'][0]['rorId'])->toBe('https://ror.org/04z8jg394');
    });

    it('extracts ROR ID from education disambiguated-organization', function () {
        $mockFullRecord = [
            'person' => [
                'name' => [
                    'given-names' => ['value' => 'Jane'],
                    'family-name' => ['value' => 'Doe'],
                ],
                'emails' => ['email' => []],
            ],
            'activities-summary' => [
                'employments' => ['affiliation-group' => []],
                'educations' => [
                    'affiliation-group' => [
                        [
                            'summaries' => [
                                [
                                    'education-summary' => [
                                        'organization' => [
                                            'name' => 'University of Potsdam',
                                            'disambiguated-organization' => [
                                                'disambiguated-organization-identifier' => '03bnmw459',
                                                'disambiguation-source' => 'ROR',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            'pub.orcid.org/v3.0/0000-0002-1825-0097' => Http::response($mockFullRecord, 200),
        ]);

        $result = $this->service->fetchOrcidRecord('0000-0002-1825-0097');

        expect($result['success'])->toBeTrue();
        expect($result['data']['affiliations'])->toHaveCount(1);
        expect($result['data']['affiliations'][0]['rorId'])->toBe('https://ror.org/03bnmw459');
    });

    it('returns null rorId when disambiguation source is not ROR', function () {
        $mockFullRecord = [
            'person' => [
                'name' => [
                    'given-names' => ['value' => 'Jane'],
                    'family-name' => ['value' => 'Doe'],
                ],
                'emails' => ['email' => []],
            ],
            'activities-summary' => [
                'employments' => [
                    'affiliation-group' => [
                        [
                            'summaries' => [
                                [
                                    'employment-summary' => [
                                        'organization' => [
                                            'name' => 'Some University',
                                            'disambiguated-organization' => [
                                                'disambiguated-organization-identifier' => 'grid.12345',
                                                'disambiguation-source' => 'GRID',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'educations' => ['affiliation-group' => []],
            ],
        ];

        Http::fake([
            'pub.orcid.org/v3.0/0000-0002-1825-0097' => Http::response($mockFullRecord, 200),
        ]);

        $result = $this->service->fetchOrcidRecord('0000-0002-1825-0097');

        expect($result['success'])->toBeTrue();
        expect($result['data']['affiliations'])->toHaveCount(1);
        expect($result['data']['affiliations'][0]['rorId'])->toBeNull();
    });

    it('returns null rorId when no disambiguated-organization is present', function () {
        $mockFullRecord = [
            'person' => [
                'name' => [
                    'given-names' => ['value' => 'Jane'],
                    'family-name' => ['value' => 'Doe'],
                ],
                'emails' => ['email' => []],
            ],
            'activities-summary' => [
                'employments' => [
                    'affiliation-group' => [
                        [
                            'summaries' => [
                                [
                                    'employment-summary' => [
                                        'organization' => [
                                            'name' => 'Unknown Lab',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'educations' => ['affiliation-group' => []],
            ],
        ];

        Http::fake([
            'pub.orcid.org/v3.0/0000-0002-1825-0097' => Http::response($mockFullRecord, 200),
        ]);

        $result = $this->service->fetchOrcidRecord('0000-0002-1825-0097');

        expect($result['success'])->toBeTrue();
        expect($result['data']['affiliations'])->toHaveCount(1);
        expect($result['data']['affiliations'][0]['rorId'])->toBeNull();
    });
});
