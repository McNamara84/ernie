<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\OrcidService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OrcidServiceTest extends TestCase
{
    private OrcidService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new OrcidService;
        Cache::flush();
    }

    /** @test */
    public function it_validates_orcid_format_correctly(): void
    {
        // Valid ORCID formats
        $this->assertTrue($this->service->validateOrcidFormat('0000-0001-2345-6789'));
        $this->assertTrue($this->service->validateOrcidFormat('0000-0002-1825-0097'));
        $this->assertTrue($this->service->validateOrcidFormat('0000-0001-5000-000X')); // With X check digit

        // Invalid formats
        $this->assertFalse($this->service->validateOrcidFormat('0000-0001-2345-678')); // Too short
        $this->assertFalse($this->service->validateOrcidFormat('0000-0001-2345-67890')); // Too long
        $this->assertFalse($this->service->validateOrcidFormat('000-0001-2345-6789')); // Wrong format
        $this->assertFalse($this->service->validateOrcidFormat('invalid-orcid'));
        $this->assertFalse($this->service->validateOrcidFormat(''));
    }

    /** @test */
    public function it_returns_invalid_for_wrong_orcid_format(): void
    {
        $result = $this->service->validateOrcid('invalid-format');

        $this->assertFalse($result['valid']);
        $this->assertNull($result['exists']);
        $this->assertStringContainsString('Invalid ORCID format', $result['message']);
    }

    /** @test */
    public function it_validates_existing_orcid(): void
    {
        Http::fake([
            'pub.orcid.org/v3.0/0000-0001-2345-6789/person' => Http::response([
                'name' => [
                    'given-names' => ['value' => 'John'],
                    'family-name' => ['value' => 'Doe'],
                ],
            ], 200),
        ]);

        $result = $this->service->validateOrcid('0000-0001-2345-6789');

        $this->assertTrue($result['valid']);
        $this->assertTrue($result['exists']);
        $this->assertEquals('Valid ORCID ID', $result['message']);
    }

    /** @test */
    public function it_detects_non_existing_orcid(): void
    {
        Http::fake([
            'pub.orcid.org/v3.0/0000-0001-2345-6789/person' => Http::response(null, 404),
        ]);

        $result = $this->service->validateOrcid('0000-0001-2345-6789');

        $this->assertTrue($result['valid']);
        $this->assertFalse($result['exists']);
        $this->assertEquals('ORCID ID not found', $result['message']);
    }

    /** @test */
    public function it_fetches_orcid_record_successfully(): void
    {
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

        $this->assertTrue($result['success']);
        $this->assertNull($result['error']);

        $data = $result['data'];
        $this->assertEquals('0000-0001-2345-6789', $data['orcid']);
        $this->assertEquals('Albert', $data['firstName']);
        $this->assertEquals('Einstein', $data['lastName']);
        $this->assertEquals('Prof. Albert Einstein', $data['creditName']);
        $this->assertContains('albert@example.com', $data['emails']);
        $this->assertCount(1, $data['affiliations']);
        $this->assertEquals('Princeton University', $data['affiliations'][0]['name']);
    }

    /** @test */
    public function it_returns_error_for_invalid_orcid_format_on_fetch(): void
    {
        $result = $this->service->fetchOrcidRecord('invalid-format');

        $this->assertFalse($result['success']);
        $this->assertNull($result['data']);
        $this->assertEquals('Invalid ORCID format', $result['error']);
    }

    /** @test */
    public function it_returns_error_for_non_existing_orcid_on_fetch(): void
    {
        Http::fake([
            'pub.orcid.org/v3.0/0000-0001-2345-6789/person' => Http::response(null, 404),
        ]);

        $result = $this->service->fetchOrcidRecord('0000-0001-2345-6789');

        $this->assertFalse($result['success']);
        $this->assertNull($result['data']);
        $this->assertEquals('ORCID not found', $result['error']);
    }

    /** @test */
    public function it_caches_orcid_records(): void
    {
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

        // First call
        $result1 = $this->service->fetchOrcidRecord('0000-0001-2345-6789');
        $this->assertTrue($result1['success']);

        // Second call should be from cache
        Http::assertSentCount(1); // Only one API call

        $result2 = $this->service->fetchOrcidRecord('0000-0001-2345-6789');
        $this->assertTrue($result2['success']);
        $this->assertEquals($result1['data'], $result2['data']);

        // Still only one API call (second was cached)
        Http::assertSentCount(1);
    }

    /** @test */
    public function it_searches_orcid_successfully(): void
    {
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

        $this->assertTrue($result['success']);
        $this->assertNull($result['error']);
        $this->assertEquals(2, $result['data']['total']);
        $this->assertCount(2, $result['data']['results']);

        $firstResult = $result['data']['results'][0];
        $this->assertEquals('0000-0001-2345-6789', $firstResult['orcid']);
        $this->assertEquals('Albert', $firstResult['firstName']);
        $this->assertEquals('Einstein', $firstResult['lastName']);
    }

    /** @test */
    public function it_returns_error_for_empty_search_query(): void
    {
        $result = $this->service->searchOrcid('', 10);

        $this->assertFalse($result['success']);
        $this->assertNull($result['data']);
        $this->assertEquals('Search query is required', $result['error']);
    }

    /** @test */
    public function test_it_limits_search_results_to_maximum(): void
    {
        Http::fake([
            'pub.orcid.org/v3.0/search*' => Http::response([
                'num-found' => 0,
                'result' => [],
            ], 200),
        ]);

        $this->service->searchOrcid('Test Query', 300); // Request more than max

        Http::assertSent(function ($request) {
            // Check if rows parameter is limited to 200
            $url = $request->url();
            parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $params);

            return str_contains($url, 'pub.orcid.org/v3.0/search')
                && isset($params['rows'])
                && (int) $params['rows'] === 200;
        });
    }

    /** @test */
    public function test_it_validates_orcid_checksum_correctly(): void
    {
        // Valid checksums (verified against ORCID spec)
        $this->assertTrue($this->service->validateOrcidChecksum('0000-0002-1825-0097'));
        $this->assertTrue($this->service->validateOrcidChecksum('0000-0001-5109-3700'));
        $this->assertTrue($this->service->validateOrcidChecksum('0000-0002-9079-593X')); // With X checksum
        $this->assertTrue($this->service->validateOrcidChecksum('0000-0002-0275-1903')); // Issue #403 ORCID

        // Invalid checksums
        $this->assertFalse($this->service->validateOrcidChecksum('0000-0002-1825-0098')); // Wrong check digit
        $this->assertFalse($this->service->validateOrcidChecksum('0000-0000-0000-0000')); // Invalid ORCID
        $this->assertFalse($this->service->validateOrcidChecksum('1234-5678-9012-3456')); // Random invalid
    }

    /** @test */
    public function test_it_returns_checksum_error_type_for_invalid_checksum(): void
    {
        $result = $this->service->validateOrcid('0000-0002-1825-0098'); // Wrong checksum

        $this->assertFalse($result['valid']);
        $this->assertNull($result['exists']);
        $this->assertEquals('checksum', $result['errorType']);
        $this->assertStringContainsString('checksum', $result['message']);
    }

    /** @test */
    public function test_it_returns_format_error_type_for_invalid_format(): void
    {
        $result = $this->service->validateOrcid('invalid-format');

        $this->assertFalse($result['valid']);
        $this->assertEquals('format', $result['errorType']);
    }

    /** @test */
    public function test_it_returns_not_found_error_type_for_404(): void
    {
        Http::fake([
            'pub.orcid.org/v3.0/0000-0002-1825-0097/person' => Http::response(null, 404),
        ]);

        $result = $this->service->validateOrcid('0000-0002-1825-0097');

        $this->assertTrue($result['valid']);
        $this->assertFalse($result['exists']);
        $this->assertEquals('not_found', $result['errorType']);
    }

    /** @test */
    public function test_it_caches_negative_result_for_404(): void
    {
        Http::fake([
            'pub.orcid.org/v3.0/0000-0002-1825-0097/person' => Http::response(null, 404),
        ]);

        // First call - result must be used due to NoDiscard attribute
        $firstResult = $this->service->validateOrcid('0000-0002-1825-0097');
        $this->assertEquals('not_found', $firstResult['errorType']);

        // Second call should use cache (same fake is still in place)
        $result = $this->service->validateOrcid('0000-0002-1825-0097');

        $this->assertFalse($result['exists']);
        $this->assertEquals('not_found', $result['errorType']);

        // Verify only one HTTP call was made (second used cache)
        Http::assertSentCount(1);
    }

    /** @test */
    public function test_it_returns_api_error_type_for_server_errors(): void
    {
        Http::fake([
            'pub.orcid.org/v3.0/0000-0002-1825-0097/person' => Http::response(null, 500),
        ]);

        $result = $this->service->validateOrcid('0000-0002-1825-0097');

        $this->assertTrue($result['valid']);
        $this->assertNull($result['exists']);
        $this->assertEquals('api_error', $result['errorType']);
    }

    /** @test */
    public function test_it_returns_null_error_type_for_successful_validation(): void
    {
        Http::fake([
            'pub.orcid.org/v3.0/0000-0002-1825-0097/person' => Http::response(['name' => []], 200),
        ]);

        $result = $this->service->validateOrcid('0000-0002-1825-0097');

        $this->assertTrue($result['valid']);
        $this->assertTrue($result['exists']);
        $this->assertNull($result['errorType']);
    }
}
