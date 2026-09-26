<?php

declare(strict_types=1);

use App\Services\Assessment\FujiAssessmentDiagnosticsService;

it('presents F-UJI provenance and the exact F4 subtest from a stored result', function (): void {
    $details = app(FujiAssessmentDiagnosticsService::class)->fromPayload([
        'software_version' => '4.0.1',
        'metric_version' => '0.8',
        'resolved_url' => 'https://dataservices.gfz.de/10.1594/gfz.sddb.1105/example',
        'harvested_metadata' => [[
            'metadata_source' => 'embedded',
            'metadata_format' => 'jsonld',
            'metadata_schema' => 'schema.org',
            'url' => 'https://dataservices.gfz.de/10.1594/gfz.sddb.1105/example',
        ]],
        'results' => [[
            'metric_identifier' => 'FsF-F4-01M',
            'test_status' => 'pass',
            'metric_tests' => ['FsF-F4-01M-1' => ['metric_test_status' => 'fail']],
        ]],
    ]);

    expect($details)->toMatchArray([
        'softwareVersion' => '4.0.1',
        'metricVersion' => '0.8',
        'f4Status' => 'fail',
        'metadataSources' => [[
            'source' => 'embedded',
            'format' => 'jsonld',
            'schema' => 'schema.org',
            'url' => 'https://dataservices.gfz.de/10.1594/gfz.sddb.1105/example',
        ]],
    ]);
});

it('handles assessments without provenance or F4 details', function (): void {
    expect(app(FujiAssessmentDiagnosticsService::class)->fromPayload(null))->toBe([
        'softwareVersion' => null,
        'metricVersion' => null,
        'resolvedUrl' => null,
        'f4Status' => null,
        'metadataSources' => [],
    ]);
});

it('ignores scalar metric results while retaining other diagnostics', function (bool|int|string $results): void {
    expect(app(FujiAssessmentDiagnosticsService::class)->fromPayload([
        'software_version' => '4.0.1',
        'metric_version' => '0.8',
        'resolved_url' => 'https://example.org/landing-page',
        'results' => $results,
    ]))->toBe([
        'softwareVersion' => '4.0.1',
        'metricVersion' => '0.8',
        'resolvedUrl' => 'https://example.org/landing-page',
        'f4Status' => null,
        'metadataSources' => [],
    ]);
})->with([
    'string' => 'invalid',
    'integer' => 42,
    'boolean' => false,
]);
