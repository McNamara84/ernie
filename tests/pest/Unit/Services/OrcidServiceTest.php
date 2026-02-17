<?php

declare(strict_types=1);

use App\Services\OrcidService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->service = new OrcidService;
    Cache::flush();
});

describe('ORCID format validation', function () {
    test('validates orcid format correctly', function () {
        expect($this->service->validateOrcidFormat('0000-0001-2345-6789'))->toBeTrue()
            ->and($this->service->validateOrcidFormat('0000-0002-1825-0097'))->toBeTrue()
            ->and($this->service->validateOrcidFormat('0000-0001-5000-000X'))->toBeTrue();

        expect($this->service->validateOrcidFormat('0000-0001-2345-678'))->toBeFalse()
            ->and($this->service->validateOrcidFormat('0000-0001-2345-67890'))->toBeFalse()
            ->and($this->service->validateOrcidFormat('000-0001-2345-6789'))->toBeFalse()
            ->and($this->service->validateOrcidFormat('invalid-orcid'))->toBeFalse()
            ->and($this->service->validateOrcidFormat(''))->toBeFalse();
    });

    test('returns invalid for wrong orcid format', function () {
        $result = $this->service->validateOrcid('invalid-format');

        expect($result['valid'])->toBeFalse()
            ->and($result['exists'])->toBeNull()
            ->and($result['message'])->toContain('Invalid ORCID format');
    });
});

describe('ORCID validation', function () {
    test('validates existing orcid', function () {
        Http::fake([
            'pub.orcid.org/v3.0/0000-0001-2345-6789/person' => Http::response([
                'name' => [
                    'given-names' => ['value' => 'John'],
                    'family-name' => ['value' => 'Doe'],
                ],
            ], 200),
        ]);

        $result = $this->service->validateOrcid('0000-0001-2345-6789');

        expect($result['valid'])->toBeTrue()
            ->and($result['exists'])->toBeTrue()
            ->and($result['message'])->toBe('Valid ORCID ID');
    });

    test('detects non existing orcid', function () {
        Http::fake([
            'pub.orcid.org/v3.0/0000-0001-2345-6789/person' => Http::response(null, 404),
        ]);

        $result = $this->service->validateOrcid('0000-0001-2345-6789');

        expect($result['valid'])->toBeTrue()
            ->and($result['exists'])->toBeFalse()
            ->and($result['message'])->toBe('ORCID ID not found');
    });
});

describe('ORCID record fetching', function () {
    test('fetches orcid record successfully', function () {
        $mockPersonData = [
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
        ];

        Http::fake([
            'pub.orcid.org/v3.0/0000-0001-2345-6789/person' => Http::response($mockPersonData, 200),
        ]);

        $result = $this->service->fetchOrcidRecord('0000-0001-2345-6789');

        expect($result['success'])->toBeTrue()
            ->and($result['error'])->toBeNull();

        $data = $result['data'];
        expect($data['orcid'])->toBe('0000-0001-2345-6789')
            ->and($data['firstName'])->toBe('Albert')
            ->and($data['lastName'])->toBe('Einstein')
            ->and($data['creditName'])->toBe('Prof. Albert Einstein')
            ->and($data['emails'])->toContain('albert@example.com')
            ->and($data['affiliations'])->toHaveCount(1)
            ->and($data['affiliations'][0]['name'])->toBe('Princeton University');
    });

    test('returns error for invalid orcid format on fetch', function () {
        $result = $this->service->fetchOrcidRecord('invalid-format');

        expect($result['success'])->toBeFalse()
            ->and($result['data'])->toBeNull()
            ->and($result['error'])->toBe('Invalid ORCID format');
    });

    test('returns error for non existing orcid on fetch', function () {
        Http::fake([
            'pub.orcid.org/v3.0/0000-0001-2345-6789/person' => Http::response(null, 404),
        ]);

        $result = $this->service->fetchOrcidRecord('0000-0001-2345-6789');

        expect($result['success'])->toBeFalse()
            ->and($result['data'])->toBeNull()
            ->and($result['error'])->toBe('ORCID not found');
    });

    test('caches orcid records', function () {
        $mockPersonData = [
            'name' => [
                'given-names' => ['value' => 'John'],
                'family-name' => ['value' => 'Doe'],
            ],
            'emails' => ['email' => []],
            'employments' => ['affiliation-group' => []],
            'educations' => ['affiliation-group' => []],
        ];

        Http::fake([
            'pub.orcid.org/v3.0/0000-0001-2345-6789/person' => Http::response($mockPersonData, 200),
        ]);

        $result1 = $this->service->fetchOrcidRecord('0000-0001-2345-6789');
        expect($result1['success'])->toBeTrue();

        Http::assertSentCount(1);

        $result2 = $this->service->fetchOrcidRecord('0000-0001-2345-6789');
        expect($result2['success'])->toBeTrue()
            ->and($result2['data'])->toEqual($result1['data']);

        Http::assertSentCount(1);
    });
});

describe('ORCID search', function () {
    test('searches orcid successfully', function () {
        $mockSearchResults = [
            'num-found' => 2,
            'result' => [
                [
                    'orcid-identifier' => ['path' => '0000-0001-2345-6789'],
                    'given-names' => 'Albert',
                    'family-names' => 'Einstein',
                    'credit-name' => 'Prof. Albert Einstein',
                    'institution-name' => ['Princeton University'],
                ],
                [
                    'orcid-identifier' => ['path' => '0000-0001-9876-5432'],
                    'given-names' => 'Albert',
                    'family-names' => 'Einstein',
                    'credit-name' => null,
                    'institution-name' => [],
                ],
            ],
        ];

        Http::fake([
            'pub.orcid.org/v3.0/search*' => Http::response($mockSearchResults, 200),
        ]);

        $result = $this->service->searchOrcid('Albert Einstein', 10);

        expect($result['success'])->toBeTrue()
            ->and($result['error'])->toBeNull()
            ->and($result['data']['total'])->toBe(2)
            ->and($result['data']['results'])->toHaveCount(2);

        $firstResult = $result['data']['results'][0];
        expect($firstResult['orcid'])->toBe('0000-0001-2345-6789')
            ->and($firstResult['firstName'])->toBe('Albert')
            ->and($firstResult['lastName'])->toBe('Einstein');
    });

    test('returns error for empty search query', function () {
        $result = $this->service->searchOrcid('', 10);

        expect($result['success'])->toBeFalse()
            ->and($result['data'])->toBeNull()
            ->and($result['error'])->toBe('Search query is required');
    });

    test('limits search results to maximum', function () {
        Http::fake([
            'pub.orcid.org/v3.0/search*' => Http::response([
                'num-found' => 0,
                'result' => [],
            ], 200),
        ]);

        $this->service->searchOrcid('Test Query', 300);

        Http::assertSent(function ($request) {
            $url = $request->url();
            parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $params);

            return str_contains($url, 'pub.orcid.org/v3.0/search')
                && isset($params['rows'])
                && (int) $params['rows'] === 200;
        });
    });
});

describe('ORCID checksum validation', function () {
    test('validates orcid checksum correctly', function () {
        expect($this->service->validateOrcidChecksum('0000-0002-1825-0097'))->toBeTrue()
            ->and($this->service->validateOrcidChecksum('0000-0001-5109-3700'))->toBeTrue()
            ->and($this->service->validateOrcidChecksum('0000-0002-9079-593X'))->toBeTrue()
            ->and($this->service->validateOrcidChecksum('0000-0002-0275-1903'))->toBeTrue();

        expect($this->service->validateOrcidChecksum('0000-0002-1825-0098'))->toBeFalse()
            ->and($this->service->validateOrcidChecksum('0000-0000-0000-0000'))->toBeFalse()
            ->and($this->service->validateOrcidChecksum('1234-5678-9012-3456'))->toBeFalse();
    });

    test('returns checksum error type for invalid checksum', function () {
        $result = $this->service->validateOrcid('0000-0002-1825-0098');

        expect($result['valid'])->toBeFalse()
            ->and($result['exists'])->toBeNull()
            ->and($result['errorType'])->toBe('checksum')
            ->and($result['message'])->toContain('checksum');
    });

    test('returns format error type for invalid format', function () {
        $result = $this->service->validateOrcid('invalid-format');

        expect($result['valid'])->toBeFalse()
            ->and($result['errorType'])->toBe('format');
    });
});

describe('ORCID error types', function () {
    test('returns not_found error type for 404', function () {
        Http::fake([
            'pub.orcid.org/v3.0/0000-0002-1825-0097/person' => Http::response(null, 404),
        ]);

        $result = $this->service->validateOrcid('0000-0002-1825-0097');

        expect($result['valid'])->toBeTrue()
            ->and($result['exists'])->toBeFalse()
            ->and($result['errorType'])->toBe('not_found');
    });

    test('caches negative result for 404', function () {
        Http::fake([
            'pub.orcid.org/v3.0/0000-0002-1825-0097/person' => Http::response(null, 404),
        ]);

        $firstResult = $this->service->validateOrcid('0000-0002-1825-0097');
        expect($firstResult['errorType'])->toBe('not_found');

        $result = $this->service->validateOrcid('0000-0002-1825-0097');
        expect($result['exists'])->toBeFalse()
            ->and($result['errorType'])->toBe('not_found');

        Http::assertSentCount(1);
    });

    test('returns api_error type for server errors', function () {
        Http::fake([
            'pub.orcid.org/v3.0/0000-0002-1825-0097/person' => Http::sequence()
                ->push(null, 500)
                ->push(null, 500)
                ->push(null, 500),
        ]);

        $result = $this->service->validateOrcid('0000-0002-1825-0097');

        expect($result['valid'])->toBeTrue()
            ->and($result['exists'])->toBeNull()
            ->and($result['errorType'])->toBe('api_error');
    });

    test('returns null error type for successful validation', function () {
        Http::fake([
            'pub.orcid.org/v3.0/0000-0002-1825-0097/person' => Http::response(['name' => []], 200),
        ]);

        $result = $this->service->validateOrcid('0000-0002-1825-0097');

        expect($result['valid'])->toBeTrue()
            ->and($result['exists'])->toBeTrue()
            ->and($result['errorType'])->toBeNull();
    });

    test('returns timeout error type after connection failures', function () {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('Connection timed out'));

        $result = $this->service->validateOrcid('0000-0002-1825-0097');

        expect($result['valid'])->toBeTrue()
            ->and($result['exists'])->toBeNull()
            ->and($result['errorType'])->toBe('timeout')
            ->and(strtolower($result['message']))->toContain('could not verify');
    });
});

describe('ORCID retry behavior', function () {
    test('retries on server error before returning api_error', function () {
        Http::fake([
            'pub.orcid.org/v3.0/0000-0002-1825-0097/person' => Http::sequence()
                ->push(null, 500)
                ->push(null, 500)
                ->push(null, 500),
        ]);

        $result = $this->service->validateOrcid('0000-0002-1825-0097');

        expect($result['valid'])->toBeTrue()
            ->and($result['exists'])->toBeNull()
            ->and($result['errorType'])->toBe('api_error');

        Http::assertSentCount(3);
    });

    test('retries on rate limit 429 error', function () {
        Http::fake([
            'pub.orcid.org/v3.0/0000-0002-1825-0097/person' => Http::sequence()
                ->push(null, 429)
                ->push(null, 429)
                ->push(['name' => []], 200),
        ]);

        $result = $this->service->validateOrcid('0000-0002-1825-0097');

        expect($result['valid'])->toBeTrue()
            ->and($result['exists'])->toBeTrue()
            ->and($result['errorType'])->toBeNull();

        Http::assertSentCount(3);
    });
});
