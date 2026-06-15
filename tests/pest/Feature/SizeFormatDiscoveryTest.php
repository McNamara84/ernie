<?php

// Active Feature Tests for Size and Format Discovery Module
use Modules\Assistants\SizeFormatSuggestion\Services\SizeFormatFileProbeService;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->probeService = new SizeFormatFileProbeService();
});

/**
 * Scenario 1: Institutional Rules (Geofon & Live Streams)
 */
test('it skips dynamic streaming metadata for geofon URLs based on policy', function () {
    $geofonUrl = 'https://geofon.gfz.de/doi/network/3I/2019';
    
    // Mock HTTP to prevent real network requests
    Http::fake([
        $geofonUrl => Http::response('', 200),
    ]);
    
    $result = $this->probeService->inferMetadata($geofonUrl);

    expect($result['success'])->toBeTrue();
    expect($result['probe_method'])->toBe('Skipped (Form or Stream)');
    expect($result['size'])->toBe('Dynamic');
    expect($result['format'])->toBe('Dynamic Stream / Form Protected');
});

/**
 * Scenario 2: Form-Based Repositories (Blacklisted/Skipped)
 */
test('it skips form-based repositories automatically', function () {
    $formUrl = 'https://arbodat.example.org/dataset/download/123';
    
    // Mock HTTP to prevent real network requests
    Http::fake([
        $formUrl => Http::response('', 200),
    ]);
    
    $result = $this->probeService->inferMetadata($formUrl);

    expect($result['success'])->toBeTrue();
    expect($result['probe_method'])->toBe('Skipped (Form or Stream)');
    expect($result['format'])->toBe('Dynamic Stream / Form Protected');
    expect($result['size'])->toBe('Dynamic');
});

/**
 * Scenario 3: Ideal Case (Successful HTTP HEAD Request with Metadata)
 */
test('it successfully extracts format and size from HTTP HEAD headers', function () {
    $targetUrl = 'https://dataservices.gfz-potsdam.de/files/data.pdf';
    
    Http::fake([
        $targetUrl => Http::response([], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Length' => '2097152', // 2 MB
        ])
    ]);

    $result = $this->probeService->inferMetadata($targetUrl);

    expect($result['success'])->toBeTrue();
    expect($result['probe_method'])->toBe('HTTP HEAD Request');
    expect($result['format'])->toBe('application/pdf');
    expect($result['size'])->toBe('2 MB');
});

/**
 * Scenario 4: Folders / Multi-links (Fallback to Filename Extension)
 */
test('it falls back to filename extension when network probing fails or headers are missing', function () {
    $folderZipUrl = 'https://dataservices.gfz-potsdam.de/download/nested_folder/dataset_archive.zip';
    
    Http::fake([
        $folderZipUrl => Http::response([], 404)
    ]);

    $result = $this->probeService->inferMetadata($folderZipUrl);

    expect($result['success'])->toBeTrue();
    expect($result['probe_method'])->toBe('Filename Extension Fallback');
    expect($result['format'])->toBe('Unknown (ZIP)');
    expect($result['size'])->toBe('Unknown');
});

/**
 * Scenario 5: Missing Content-Type Header
 */
test('it handles missing Content-Type header gracefully', function () {
    $url = 'https://dataservices.gfz-potsdam.de/files/data.bin';
    
    Http::fake([
        $url => Http::response([], 200, [
            'Content-Length' => '1048576', // 1 MB
        ])
    ]);

    $result = $this->probeService->inferMetadata($url);

    expect($result['success'])->toBeTrue();
    expect($result['size'])->toBe('1 MB');
    expect($result['format'])->toBe('Unknown');
});

/**
 * Scenario 6: Missing Content-Length Header
 */
test('it handles missing Content-Length header gracefully', function () {
    $url = 'https://dataservices.gfz-potsdam.de/files/data.pdf';
    
    Http::fake([
        $url => Http::response([], 200, [
            'Content-Type' => 'application/pdf',
        ])
    ]);

    $result = $this->probeService->inferMetadata($url);

    expect($result['success'])->toBeTrue();
    expect($result['format'])->toBe('application/pdf');
    expect($result['size'])->toBe('Unknown');
});

/**
 * Scenario 7: HTTP Connection Timeout
 */
test('it handles HTTP timeouts gracefully', function () {
    $url = 'https://slow-server.example.com/data.csv';
    
    Http::fake([
        $url => Http::sequence(
            Http::response([], 0, []) // Simulate timeout
        )
    ]);

    $result = $this->probeService->inferMetadata($url);

    expect($result['success'])->toBeFalse();
    expect($result['probe_method'])->toContain('Exception');
});

/**
 * Scenario 8: Large File Size Handling
 */
test('it correctly formats very large file sizes', function () {
    $url = 'https://dataservices.gfz-potsdam.de/files/large_dataset.tar.gz';
    
    Http::fake([
        $url => Http::response([], 200, [
            'Content-Type' => 'application/gzip',
            'Content-Length' => '5368709120', // 5 GB
        ])
    ]);

    $result = $this->probeService->inferMetadata($url);

    expect($result['success'])->toBeTrue();
    expect($result['size'])->toContain('GB');
});

/**
 * Scenario 9: Invalid URL Format
 */
test('it rejects invalid URL formats', function () {
    $invalidUrl = 'not-a-valid-url';
    
    $result = $this->probeService->inferMetadata($invalidUrl);

    expect($result['success'])->toBeFalse();
});

/**
 * Scenario 10: HTTP Redirect (3xx Status)
 */
test('it follows HTTP redirects', function () {
    $originalUrl = 'https://dataservices.gfz-potsdam.de/old-path/data.pdf';
    $redirectedUrl = 'https://dataservices.gfz-potsdam.de/new-path/data.pdf';
    
    Http::fake([
        $redirectedUrl => Http::response([], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Length' => '2097152',
        ])
    ]);

    $result = $this->probeService->inferMetadata($originalUrl);

    expect($result['success'])->toBeTrue();
    expect($result['format'])->toBe('application/pdf');
});