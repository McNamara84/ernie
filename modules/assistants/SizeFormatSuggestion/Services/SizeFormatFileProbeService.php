<?php

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
    
    $result = $this->probeService->inferMetadata($formUrl);

    expect($result['success'])->toBeTrue();
    expect($result['probe_method'])->toBe('Skipped (Form or Stream)');
    expect($result['format'])->toBe('Dynamic Stream / Form Protected');
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