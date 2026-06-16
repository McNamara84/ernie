<?php

use Illuminate\Support\Facades\Http;
use App\Services\SizeFormatFileProbeService;

beforeEach(function () {
    $this->probeService = new SizeFormatFileProbeService();
});

// 1. Professor Request: Test inaccessible URLs when the server returns a 500 error
it('handles inaccessible urls gracefully when server returns a 500 error', function () {
    $url = 'https://dataservices.gfz-potsdam.de/broken-link';
    
    Http::fake([
        $url => Http::response('Internal Server Error', 500),
    ]);

    $results = $this->probeService->extractAndProbe($url);

    expect($results)->toBeArray();
    expect($results[0]['probe_method'])->toBe('SKIP');
    expect($results[0]['skip_reason'])->toBe('landing_page_unreachable');
});

// 2. Professor Request: Test inaccessible URLs when connection times out completely
it('handles inaccessible urls when connection times out completely', function () {
    $url = 'https://dataservices.gfz-potsdam.de/timeout-link';
    
    Http::fake([
        $url => Http::sequence()->throwResponseException(
            new \Illuminate\Http\Client\ConnectionException('Connection timed out')
        ),
    ]);

    $results = $this->probeService->extractAndProbe($url);

    expect($results[0]['probe_method'])->toBe('SKIP');
    expect($results[0]['skip_reason'])->toBe('exception');
});

// 3. Coverage Optimization: Skip unsupported protocols instantly
it('skips unsupported protocols instantly', function () {
    $url = 'ftp://dataservices.gfz-potsdam.de/file.zip';

    $results = $this->probeService->extractAndProbe($url);

    expect($results[0]['probe_method'])->toBe('SKIP');
    expect($results[0]['skip_reason'])->toBe('unsupported_protocol');
});

// 4. Coverage Optimization: Infers metadata from file headers via HTTP HEAD request
it('infers metadata from file headers via http head request', function () {
    $fileUrl = 'https://dataservices.gfz-potsdam.de/download/report.pdf';

    Http::fake([
        $fileUrl => Http::response([], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Length' => '1048576'
        ]),
    ]);

    $result = $this->probeService->inferMetadataFromFileUrl($fileUrl);

    expect($result['probe_method'])->toBe('HTTP_HEAD');
    expect($result['suggestions'][0]['inferred_value'])->toBe('application/pdf');
});