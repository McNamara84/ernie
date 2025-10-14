<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\MslLaboratoryService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Feature tests for MSL Laboratory Service
 * Tests fetching and caching of MSL laboratory data from Utrecht University vocabulary
 */
class MslLaboratoryServiceTest extends TestCase
{
    private MslLaboratoryService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new MslLaboratoryService();
        $this->service->clearCache();
    }

    public function test_find_by_lab_id_returns_laboratory(): void
    {
        Http::fake([
            'raw.githubusercontent.com/*' => Http::response([
                [
                    'identifier' => 'test123',
                    'name' => 'Test Lab',
                    'affiliation_name' => 'Test University',
                    'affiliation_ror' => 'https://ror.org/test',
                ],
            ], 200),
        ]);

        $lab = $this->service->findByLabId('test123');

        $this->assertIsArray($lab);
        $this->assertEquals('Test Lab', $lab['name']);
        $this->assertEquals('test123', $lab['identifier']);
    }

    public function test_find_by_lab_id_caches_result(): void
    {
        Http::fake([
            'raw.githubusercontent.com/*' => Http::response([
                [
                    'identifier' => 'test123',
                    'name' => 'Test Lab',
                    'affiliation_name' => 'Test University',
                    'affiliation_ror' => 'https://ror.org/test',
                ],
            ], 200),
        ]);

        // First call
        $this->service->findByLabId('test123');

        // Second call should use cache
        Http::assertSentCount(1);
        
        $lab = $this->service->findByLabId('test123');
        
        // Should still be only 1 HTTP call
        Http::assertSentCount(1);
        
        $this->assertIsArray($lab);
    }

    public function test_find_by_lab_id_returns_null_for_unknown_id(): void
    {
        Http::fake([
            'raw.githubusercontent.com/*' => Http::response([
                [
                    'identifier' => 'test123',
                    'name' => 'Test Lab',
                    'affiliation_name' => 'Test University',
                    'affiliation_ror' => 'https://ror.org/test',
                ],
            ], 200),
        ]);

        $lab = $this->service->findByLabId('unknown456');

        $this->assertNull($lab);
    }

    public function test_is_valid_lab_id_returns_true_for_existing_id(): void
    {
        Http::fake([
            'raw.githubusercontent.com/*' => Http::response([
                [
                    'identifier' => 'test123',
                    'name' => 'Test Lab',
                    'affiliation_name' => 'Test University',
                    'affiliation_ror' => 'https://ror.org/test',
                ],
            ], 200),
        ]);

        $isValid = $this->service->isValidLabId('test123');

        $this->assertTrue($isValid);
    }

    public function test_is_valid_lab_id_returns_false_for_unknown_id(): void
    {
        Http::fake([
            'raw.githubusercontent.com/*' => Http::response([
                [
                    'identifier' => 'test123',
                    'name' => 'Test Lab',
                    'affiliation_name' => 'Test University',
                    'affiliation_ror' => 'https://ror.org/test',
                ],
            ], 200),
        ]);

        $isValid = $this->service->isValidLabId('unknown456');

        $this->assertFalse($isValid);
    }

    public function test_enrich_laboratory_data_uses_vocabulary_data(): void
    {
        Http::fake([
            'raw.githubusercontent.com/*' => Http::response([
                [
                    'identifier' => 'test123',
                    'name' => 'Official Lab Name',
                    'affiliation_name' => 'Official University',
                    'affiliation_ror' => 'https://ror.org/official',
                ],
            ], 200),
        ]);

        $enriched = $this->service->enrichLaboratoryData(
            'test123',
            'XML Lab Name',
            'XML University',
            'https://ror.org/xml'
        );

        // Should use vocabulary data
        $this->assertEquals('Official Lab Name', $enriched['name']);
        $this->assertEquals('Official University', $enriched['affiliation_name']);
        $this->assertEquals('https://ror.org/official', $enriched['affiliation_ror']);
    }

    public function test_enrich_laboratory_data_falls_back_to_xml_data(): void
    {
        Http::fake([
            'raw.githubusercontent.com/*' => Http::response([
                [
                    'identifier' => 'other123',
                    'name' => 'Other Lab',
                    'affiliation_name' => 'Other University',
                    'affiliation_ror' => 'https://ror.org/other',
                ],
            ], 200),
        ]);

        // Try to enrich with unknown lab ID
        $enriched = $this->service->enrichLaboratoryData(
            'unknown456',
            'XML Lab Name',
            'XML University',
            'https://ror.org/xml'
        );

        // Should use XML data as fallback
        $this->assertEquals('XML Lab Name', $enriched['name']);
        $this->assertEquals('XML University', $enriched['affiliation_name']);
        $this->assertEquals('https://ror.org/xml', $enriched['affiliation_ror']);
    }

    public function test_enrich_laboratory_data_handles_empty_xml_data(): void
    {
        Http::fake([
            'raw.githubusercontent.com/*' => Http::response([
                [
                    'identifier' => 'other123',
                    'name' => 'Other Lab',
                    'affiliation_name' => 'Other University',
                    'affiliation_ror' => 'https://ror.org/other',
                ],
            ], 200),
        ]);

        // Unknown lab ID with no XML data
        $enriched = $this->service->enrichLaboratoryData('unknown456');

        // Should return empty strings
        $this->assertEquals('unknown456', $enriched['identifier']);
        $this->assertEquals('', $enriched['name']);
        $this->assertEquals('', $enriched['affiliation_name']);
        $this->assertEquals('', $enriched['affiliation_ror']);
    }

    public function test_clear_cache_removes_cached_data(): void
    {
        Http::fake([
            'raw.githubusercontent.com/*' => Http::response([
                [
                    'identifier' => 'test123',
                    'name' => 'Test Lab',
                    'affiliation_name' => 'Test University',
                    'affiliation_ror' => 'https://ror.org/test',
                ],
            ], 200),
        ]);

        // First call
        $this->service->findByLabId('test123');
        Http::assertSentCount(1);

        // Clear cache
        $this->service->clearCache();

        // Second call should make a new HTTP request
        $this->service->findByLabId('test123');
        Http::assertSentCount(2);
    }

    public function test_handles_http_error_gracefully(): void
    {
        Http::fake([
            'raw.githubusercontent.com/*' => Http::response('', 500),
        ]);

        $lab = $this->service->findByLabId('test123');

        $this->assertNull($lab);
    }

    public function test_handles_invalid_json_gracefully(): void
    {
        Http::fake([
            'raw.githubusercontent.com/*' => Http::response('not valid json', 200),
        ]);

        $lab = $this->service->findByLabId('test123');

        $this->assertNull($lab);
    }

    public function test_handles_malformed_laboratory_data(): void
    {
        Http::fake([
            'raw.githubusercontent.com/*' => Http::response([
                [
                    // Missing identifier field
                    'name' => 'Invalid Lab',
                ],
                [
                    'identifier' => 'valid123',
                    'name' => 'Valid Lab',
                    'affiliation_name' => 'University',
                    'affiliation_ror' => 'https://ror.org/valid',
                ],
            ], 200),
        ]);

        // Invalid lab should not be found
        $invalidLab = $this->service->findByLabId('invalid');
        $this->assertNull($invalidLab);

        // Valid lab should be found
        $validLab = $this->service->findByLabId('valid123');
        $this->assertNotNull($validLab);
        $this->assertEquals('Valid Lab', $validLab['name']);
    }
}
