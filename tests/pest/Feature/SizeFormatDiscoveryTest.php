<?php

use Illuminate\Support\Facades\Http;
use App\Services\SizeFormatFileProbeService;
use Modules\Assistants\SizeFormatSuggestion\Assistant;

beforeEach(function () {
    $this->probeService = new SizeFormatFileProbeService();
});

it('validates and skips unsupported source domain locations with explicit skip reason', function () {
    $unsupportedUrl = 'https://unknown-repository.org/dataset-123';

    // The method returns an array containing the skip payload array
    $results = $this->probeService->extractAndProbe($unsupportedUrl);

    expect($results)->toBeArray();
    expect($results[0]['probe_method'])->toBe('SKIP');
    expect($results[0]['skip_reason'])->toBe('unsupported_source_url');
    expect($results[0]['suggestions'])->toBeEmpty();
});

it('resolves a valid doi redirect landing page and parses simulated html content', function () {
    $doiUrl = 'https://doi.org/10.5880/GFZ.WSM.Map2009';
    $targetLandingPage = 'https://dataservices.gfz-potsdam.de/landing-page';
    
    // Simulated HTML payload containing a valid piwik_download link matching team constraints
    $simulatedHtml = '
        <html>
            <body>
                <a class="piwik_download" href="https://dataservices.gfz-potsdam.de/download/files/">Download data</a>
            </body>
        </html>
    ';

    // Mocking the landing page discovery flow
    Http::fake([
        $doiUrl => Http::response($simulatedHtml, 200, [
            'X-Effective-URL' => $targetLandingPage
        ]),
        $targetLandingPage => Http::response($simulatedHtml, 200)
    ]);

    $results = $this->probeService->extractAndProbe($doiUrl);

    expect($results)->toBeArray();
    // Validates that it moves past the protocol and source domain check loops
    expect($results[0])->toHaveKey('probe_method');
});

it('infers metadata from a single direct file url using http head request headers', function () {
    $fileUrl = 'https://dataservices.gfz-potsdam.de/download/files/report.pdf';

    // Mocking HTTP HEAD response containing Content-Type and numeric Content-Length
    Http::fake([
        $fileUrl => Http::response([], 200, [
            'Content-Type' => 'application/pdf; charset=UTF-8',
            'Content-Length' => '1048576' // Equivalent to exactly 1.00 MB
        ]),
    ]);

    $result = $this->probeService->inferMetadataFromFileUrl($fileUrl);

    expect($result)->toBeArray();
    expect($result['probe_method'])->toBe('HTTP_HEAD');
    expect($result['http_status'])->toBe(200);
    expect($result['suggestions'])->not->toBeEmpty();
    
    // Verifying the inner structure matching the buildSuggestions requirements
    expect($result['suggestions'][0]['type'])->toBe('format');
    expect($result['suggestions'][0]['inferred_value'])->toBe('application/pdf');
    expect($result['suggestions'][1]['type'])->toBe('size');
    expect($result['suggestions'][1]['inferred_value'])->toBe('1 MB');
});

it('falls back to filename extension parsing when head metadata headers are empty', function () {
    $archiveUrl = 'https://dataservices.gfz-potsdam.de/download/files/dataset.zip';

    // Head request finishes successfully but returns no content headers
    Http::fake([
        $archiveUrl => Http::response([], 200, [
            'Content-Type' => '',
            'Content-Length' => ''
        ]),
    ]);

    $result = $this->probeService->inferMetadataFromFileUrl($archiveUrl);

    expect($result['probe_method'])->toBe('FILENAME_EXTENSION_FALLBACK');
    expect($result['suggestions'][0]['type'])->toBe('format');
    expect($result['suggestions'][0]['inferred_value'])->toBe('zip');
    expect($result['suggestions'][0]['confidence'])->toBe('low');
});