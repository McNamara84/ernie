<?php

declare(strict_types=1);

use App\Services\Assessment\FairImprovementContext;
use App\Services\Assessment\FairImprovementOpportunityResolver;
use App\Services\Assessment\FairImprovementTipCatalog;

function fairOpportunityResolver(): FairImprovementOpportunityResolver
{
    return new FairImprovementOpportunityResolver(new FairImprovementTipCatalog);
}

/**
 * @param  array{F: float|int, A: float|int, I: float|int, R: float|int}  $gaps
 * @param  list<array<string, mixed>>  $results
 * @param  array{F: float|int, A: float|int, I: float|int, R: float|int}  $totals
 * @return array<string, mixed>
 */
function fairPayload(
    array $gaps,
    array $results = [],
    mixed $version = '0.8',
    array $totals = ['F' => 7, 'A' => 7, 'I' => 6, 'R' => 6],
    float|int $fairTotal = 26,
): array {
    $earned = [];

    foreach (['F', 'A', 'I', 'R'] as $dimension) {
        $earned[$dimension] = (float) $totals[$dimension] - (float) $gaps[$dimension];
    }

    $earned['FAIR'] = max(0.0, (float) $fairTotal - array_sum(array_map('floatval', $gaps)));

    return [
        'metric_version' => $version,
        'summary' => [
            'score_earned' => $earned,
            'score_total' => [
                ...$totals,
                'FAIR' => $fairTotal,
            ],
        ],
        'results' => $results,
    ];
}

/**
 * @param  array<string, array<string, mixed>>  $tests
 * @return array<string, mixed>
 */
function fairMetric(
    string $identifier,
    float|int $earned,
    float|int $total,
    array $tests = [],
    string $status = 'fail',
): array {
    return [
        'metric_identifier' => $identifier,
        'test_status' => $status,
        'score' => ['earned' => $earned, 'total' => $total],
        'metric_tests' => $tests,
    ];
}

/**
 * @return array<string, mixed>
 */
function fairMetricTest(
    float|int $earned,
    float|int $total,
    string $status = 'fail',
): array {
    return [
        'metric_test_status' => $status,
        'metric_test_score' => ['earned' => $earned, 'total' => $total],
    ];
}

/**
 * @return array<string, mixed>
 */
function fairFixture(string $name): array
{
    $contents = file_get_contents(__DIR__.'/Fixtures/'.$name);

    expect($contents)->not->toBeFalse();

    $decoded = json_decode((string) $contents, true, flags: JSON_THROW_ON_ERROR);

    expect($decoded)->toBeArray();

    /** @var array<string, mixed> $decoded */
    return $decoded;
}

it('selects the unique largest raw gap for every FAIR dimension', function (
    string $expected,
    array $gaps,
): void {
    $result = fairOpportunityResolver()->resolve(
        fairPayload($gaps),
        FairImprovementOpportunityResolver::SCOPE_RESOURCE,
        new FairImprovementContext,
    );

    expect($result)
        ->status->toBe('available')
        ->dimension->toBe($expected);
})->with([
    'Findability' => ['F', ['F' => 4, 'A' => 3, 'I' => 2, 'R' => 1]],
    'Accessibility' => ['A', ['F' => 3, 'A' => 4, 'I' => 2, 'R' => 1]],
    'Interoperability' => ['I', ['F' => 3, 'A' => 2, 'I' => 4, 'R' => 1]],
    'Reusability' => ['R', ['F' => 3, 'A' => 2, 'I' => 1, 'R' => 4]],
]);

it('uses absolute missing points instead of dimension percentages', function (): void {
    $result = fairOpportunityResolver()->resolve(
        fairPayload(['F' => 2, 'A' => 0, 'I' => 1.9, 'R' => 0]),
        FairImprovementOpportunityResolver::SCOPE_RESOURCE,
        new FairImprovementContext,
    );

    expect($result)
        ->dimension->toBe('F')
        ->missingPoints->toBe(2.0);
});

it('uses the largest eligible action to break a raw dimension tie', function (): void {
    $results = [
        fairMetric('FsF-F1-01MD', 0, 1, [
            'FsF-F1-01MD-1' => fairMetricTest(0, 1),
        ]),
        fairMetric('FsF-A1.1-01MD', 0, 0.6, [
            'FsF-A1.1-01MD-1' => fairMetricTest(0, 0.6),
            'FsF-A1.1-01MD-2' => fairMetricTest(0, 0),
        ]),
        fairMetric('FsF-A1.2-01MD', 0, 0.6, [
            'FsF-A1.2-01MD-1' => fairMetricTest(0, 0.6),
            'FsF-A1.2-01MD-2' => fairMetricTest(0, 0),
        ]),
    ];

    $result = fairOpportunityResolver()->resolve(
        fairPayload(['F' => 2, 'A' => 2, 'I' => 0, 'R' => 0], $results),
        FairImprovementOpportunityResolver::SCOPE_RESOURCE,
        new FairImprovementContext,
    );

    expect($result)
        ->dimension->toBe('A')
        ->suggestions->toHaveCount(1)
        ->and($result['suggestions'][0]['key'])->toBe('resource-metadata-https');
});

it('allows an administrator action to break a raw dimension tie', function (): void {
    $result = fairOpportunityResolver()->resolve(
        fairPayload(
            ['F' => 1, 'A' => 1, 'I' => 0, 'R' => 0],
            [fairMetric('FsF-A1-01M', 0, 1, [
                'FsF-A1-01M-1' => fairMetricTest(0, 1),
            ])],
        ),
        FairImprovementOpportunityResolver::SCOPE_RESOURCE,
        new FairImprovementContext,
    );

    expect($result)
        ->dimension->toBe('A')
        ->and($result['suggestions'][0]['actor'])->toBe('administrator');
});

it('falls back to the stable F A I R order when gaps and action gains tie', function (): void {
    $result = fairOpportunityResolver()->resolve(
        fairPayload(['F' => 2, 'A' => 2, 'I' => 2, 'R' => 2]),
        FairImprovementOpportunityResolver::SCOPE_RESOURCE,
        new FairImprovementContext,
    );

    expect($result)->dimension->toBe('F');
});

it('accepts integer float and numeric-string summary values', function (): void {
    $payload = [
        'metric_version' => '0.8',
        'summary' => [
            'score_earned' => [
                'F' => '3.5',
                'A' => 6,
                'I' => 5.0,
                'R' => '5',
                'FAIR' => '19.5',
            ],
            'score_total' => [
                'F' => '7',
                'A' => 7.0,
                'I' => '6.0',
                'R' => 6,
                'FAIR' => '26',
            ],
        ],
        'results' => [],
    ];

    $result = fairOpportunityResolver()->resolve(
        $payload,
        FairImprovementOpportunityResolver::SCOPE_RESOURCE,
        new FairImprovementContext,
    );

    expect($result)
        ->status->toBe('available')
        ->dimension->toBe('F')
        ->missingPoints->toBe(3.5)
        ->potentialFairGain->toBe(13.46);
});

it('clamps earned summary points to the valid zero-to-total range', function (): void {
    $payload = fairPayload(['F' => 0, 'A' => 0, 'I' => 0, 'R' => 0]);
    $payload['summary']['score_earned']['F'] = -3;
    $payload['summary']['score_earned']['A'] = 99;

    $result = fairOpportunityResolver()->resolve(
        $payload,
        FairImprovementOpportunityResolver::SCOPE_RESOURCE,
        new FairImprovementContext,
    );

    expect($result)
        ->dimension->toBe('F')
        ->missingPoints->toBe(7.0);
});

it('returns unavailable for missing malformed partial or unsafe summaries', function (
    ?array $payload,
): void {
    $result = fairOpportunityResolver()->resolve(
        $payload,
        FairImprovementOpportunityResolver::SCOPE_RESOURCE,
        new FairImprovementContext,
    );

    expect($result)
        ->status->toBe('unavailable')
        ->reason->toBe('invalid-payload');
})->with([
    'missing payload' => null,
    'missing summary' => [[]],
    'missing dimension' => [[
        'summary' => [
            'score_earned' => ['F' => 1, 'A' => 1, 'I' => 1, 'FAIR' => 4],
            'score_total' => ['F' => 7, 'A' => 7, 'I' => 6, 'FAIR' => 26],
        ],
    ]],
    'non-numeric earned value' => [[
        'summary' => [
            'score_earned' => ['F' => 'nope', 'A' => 1, 'I' => 1, 'R' => 1, 'FAIR' => 4],
            'score_total' => ['F' => 7, 'A' => 7, 'I' => 6, 'R' => 6, 'FAIR' => 26],
        ],
    ]],
    'zero dimension total' => [[
        'summary' => [
            'score_earned' => ['F' => 0, 'A' => 1, 'I' => 1, 'R' => 1, 'FAIR' => 3],
            'score_total' => ['F' => 0, 'A' => 7, 'I' => 6, 'R' => 6, 'FAIR' => 26],
        ],
    ]],
    'zero FAIR total' => [[
        'summary' => [
            'score_earned' => ['F' => 1, 'A' => 1, 'I' => 1, 'R' => 1, 'FAIR' => 0],
            'score_total' => ['F' => 7, 'A' => 7, 'I' => 6, 'R' => 6, 'FAIR' => 0],
        ],
    ]],
    'NaN earned value' => [[
        'summary' => [
            'score_earned' => ['F' => NAN, 'A' => 1, 'I' => 1, 'R' => 1, 'FAIR' => 4],
            'score_total' => ['F' => 7, 'A' => 7, 'I' => 6, 'R' => 6, 'FAIR' => 26],
        ],
    ]],
    'infinite total' => [[
        'summary' => [
            'score_earned' => ['F' => 1, 'A' => 1, 'I' => 1, 'R' => 1, 'FAIR' => 4],
            'score_total' => ['F' => INF, 'A' => 7, 'I' => 6, 'R' => 6, 'FAIR' => 26],
        ],
    ]],
    'boolean total' => [[
        'summary' => [
            'score_earned' => ['F' => 1, 'A' => 1, 'I' => 1, 'R' => 1, 'FAIR' => 4],
            'score_total' => ['F' => true, 'A' => 7, 'I' => 6, 'R' => 6, 'FAIR' => 26],
        ],
    ]],
]);

it('returns complete when every raw dimension gap is zero', function (): void {
    $result = fairOpportunityResolver()->resolve(
        fairPayload(['F' => 0, 'A' => 0, 'I' => 0, 'R' => 0]),
        FairImprovementOpportunityResolver::SCOPE_RESOURCE,
        new FairImprovementContext,
    );

    expect($result)->toBe([
        'status' => 'complete',
        'message' => 'No FAIR improvement gap was found.',
    ]);
});

it('calculates overall FAIR impact and severity boundaries', function (
    float $gap,
    string $severity,
): void {
    $result = fairOpportunityResolver()->resolve(
        fairPayload(
            ['F' => $gap, 'A' => 0, 'I' => 0, 'R' => 0],
            totals: ['F' => 20, 'A' => 1, 'I' => 1, 'R' => 1],
            fairTotal: 100,
        ),
        FairImprovementOpportunityResolver::SCOPE_RESOURCE,
        new FairImprovementContext,
    );

    expect($result)
        ->potentialFairGain->toBe(round($gap, 2))
        ->severity->toBe($severity);
})->with([
    'below five' => [4.99, 'low'],
    'five' => [5.0, 'medium'],
    'below ten' => [9.99, 'medium'],
    'ten' => [10.0, 'high'],
    'below fifteen' => [14.99, 'high'],
    'fifteen' => [15.0, 'very-high'],
]);

it('uses score gaps even when a metric and test report pass', function (): void {
    $result = fairOpportunityResolver()->resolve(
        fairPayload(
            ['F' => 2, 'A' => 0, 'I' => 0, 'R' => 0],
            [fairMetric(
                'FsF-F4-01M',
                0,
                2,
                ['FsF-F4-01M-1' => fairMetricTest(0, 2, 'pass')],
                'pass',
            )],
        ),
        FairImprovementOpportunityResolver::SCOPE_RESOURCE,
        new FairImprovementContext,
    );

    expect($result['suggestions'])
        ->toHaveCount(1)
        ->and($result['suggestions'][0]['key'])->toBe('resource-searchable-landing-page');
});

it('ignores failed zero-point compatibility tests', function (): void {
    $result = fairOpportunityResolver()->resolve(
        fairPayload(
            ['F' => 1, 'A' => 0, 'I' => 0, 'R' => 0],
            [fairMetric('FsF-F1-01MD', 1, 1, [
                'FsF-F1-01MD-2' => fairMetricTest(0, 0),
            ])],
        ),
        FairImprovementOpportunityResolver::SCOPE_RESOURCE,
        new FairImprovementContext,
    );

    expect($result)
        ->status->toBe('available')
        ->suggestions->toBe([])
        ->guidanceMessage->toBe(FairImprovementOpportunityResolver::NO_VERIFIED_ACTION_MESSAGE);
});

it('sums the two verified half-point F1 persistent-identifier tests', function (): void {
    $results = [
        fairMetric('FsF-F1-02MD', 0, 1, [
            'FsF-F1-02MD-1' => fairMetricTest(0, 0.5),
            'FsF-F1-02MD-2' => fairMetricTest(0, 0.5),
        ]),
        fairMetric('FsF-R1.2-01M', 0, 1, [
            'FsF-R1.2-01M-1' => fairMetricTest(0, 0.75),
            'FsF-R1.2-01M-2' => fairMetricTest(0, 0.75),
        ]),
    ];

    $result = fairOpportunityResolver()->resolve(
        fairPayload(['F' => 1, 'A' => 0, 'I' => 0, 'R' => 1], $results),
        FairImprovementOpportunityResolver::SCOPE_RESOURCE,
        new FairImprovementContext,
    );

    expect($result)
        ->dimension->toBe('F')
        ->and($result['suggestions'][0]['key'])->toBe('resource-persistent-identifier');
});

it('uses the largest alternative test gain instead of summing alternatives', function (): void {
    $results = [
        fairMetric('FsF-F1-01MD', 0, 0.8, [
            'FsF-F1-01MD-1' => fairMetricTest(0, 0.8),
        ]),
        fairMetric('FsF-I1-01M', 0, 1, [
            'FsF-I1-01M-1' => fairMetricTest(0, 0.75),
            'FsF-I1-01M-2' => fairMetricTest(0, 0.75),
        ]),
    ];

    $result = fairOpportunityResolver()->resolve(
        fairPayload(['F' => 1, 'A' => 0, 'I' => 1, 'R' => 0], $results),
        FairImprovementOpportunityResolver::SCOPE_RESOURCE,
        new FairImprovementContext,
    );

    expect($result)->dimension->toBe('F');
});

it('deduplicates action keys and combines non-overlapping metric gains', function (): void {
    $results = [
        fairMetric('FsF-F1-01MD', 0, 1, [
            'FsF-F1-01MD-1' => fairMetricTest(0, 1),
        ]),
        fairMetric('FsF-A1.1-01MD', 0, 0.6, [
            'FsF-A1.1-01MD-1' => fairMetricTest(0, 0.6),
        ]),
        fairMetric('FsF-A1.2-01MD', 0, 0.6, [
            'FsF-A1.2-01MD-1' => fairMetricTest(0, 0.6),
        ]),
    ];

    $result = fairOpportunityResolver()->resolve(
        fairPayload(['F' => 2, 'A' => 2, 'I' => 0, 'R' => 0], $results),
        FairImprovementOpportunityResolver::SCOPE_RESOURCE,
        new FairImprovementContext,
    );

    expect($result)
        ->dimension->toBe('A')
        ->suggestions->toHaveCount(1);
});

it('returns at most three distinct actions ordered by verified gain and priority', function (): void {
    $results = [
        fairMetric('FsF-F1-01MD', 0, 1, [
            'FsF-F1-01MD-1' => fairMetricTest(0, 1),
        ]),
        fairMetric('FsF-F1-02MD', 0, 1, [
            'FsF-F1-02MD-1' => fairMetricTest(0, 0.5),
            'FsF-F1-02MD-2' => fairMetricTest(0, 0.5),
        ]),
        fairMetric('FsF-F2-01M', 0, 2, [
            'FsF-F2-01M-2' => fairMetricTest(0, 1),
            'FsF-F2-01M-3' => fairMetricTest(0, 1),
        ]),
        fairMetric('FsF-F3-01M', 0, 1, [
            'FsF-F3-01M-2' => fairMetricTest(0, 1),
        ]),
        fairMetric('FsF-F4-01M', 0, 2, [
            'FsF-F4-01M-1' => fairMetricTest(0, 2),
        ]),
    ];

    $result = fairOpportunityResolver()->resolve(
        fairPayload(['F' => 6, 'A' => 0, 'I' => 0, 'R' => 0], $results),
        FairImprovementOpportunityResolver::SCOPE_RESOURCE,
        new FairImprovementContext,
    );

    expect(array_column($result['suggestions'], 'key'))->toBe([
        'resource-persistent-identifier',
        'resource-searchable-landing-page',
        'resource-core-citation-metadata',
    ]);
});

it('uses distinct digital-resource and physical-sample wording', function (): void {
    $payload = fairPayload(
        ['F' => 1, 'A' => 0, 'I' => 0, 'R' => 0],
        [fairMetric('FsF-F1-01MD', 0, 1, [
            'FsF-F1-01MD-1' => fairMetricTest(0, 1),
        ])],
    );

    $resourceResult = fairOpportunityResolver()->resolve(
        $payload,
        FairImprovementOpportunityResolver::SCOPE_RESOURCE,
        new FairImprovementContext,
    );
    $igsnResult = fairOpportunityResolver()->resolve(
        $payload,
        FairImprovementOpportunityResolver::SCOPE_IGSN,
        new FairImprovementContext(hasIgsnMetadata: true),
    );

    expect($resourceResult['suggestions'][0]['text'])
        ->toBe('Register a DOI for the digital resource and point it to the published ERNIE landing page.')
        ->and($igsnResult['suggestions'][0]['text'])
        ->toBe('Register the IGSN with DataCite and point it to a published ERNIE sample landing page so the identifier remains persistent and resolvable.');
});

it('selects absent draft and published landing-page actions from local state', function (
    FairImprovementContext $context,
    string $actor,
    string $text,
): void {
    $payload = fairPayload(
        ['F' => 2, 'A' => 0, 'I' => 0, 'R' => 0],
        [fairMetric('FsF-F4-01M', 0, 2, [
            'FsF-F4-01M-1' => fairMetricTest(0, 2),
        ])],
    );
    $result = fairOpportunityResolver()->resolve(
        $payload,
        FairImprovementOpportunityResolver::SCOPE_RESOURCE,
        $context,
    );

    expect($result['suggestions'][0])
        ->actor->toBe($actor)
        ->text->toBe($text);
})->with([
    'absent' => [
        new FairImprovementContext,
        'curator',
        'Use an ERNIE landing-page template and keep the page published so search-engine-readable Schema.org metadata is embedded.',
    ],
    'draft' => [
        new FairImprovementContext(
            landingPageExists: true,
            landingPageIsInternal: true,
            landingPageUsesHttps: true,
        ),
        'curator',
        'Use an ERNIE landing-page template and keep the page published so search-engine-readable Schema.org metadata is embedded.',
    ],
    'published' => [
        new FairImprovementContext(
            landingPageExists: true,
            landingPagePublished: true,
            landingPageIsInternal: true,
            landingPageUsesHttps: true,
        ),
        'administrator',
        'Make the published ERNIE landing page\'s Schema.org metadata crawlable in the initial server response.',
    ],
]);

it('selects missing DOI and existing DOI actions from local state', function (
    bool $hasDoi,
    string $expected,
): void {
    $payload = fairPayload(
        ['F' => 1, 'A' => 0, 'I' => 0, 'R' => 0],
        [fairMetric('FsF-F1-01MD', 0, 1, [
            'FsF-F1-01MD-1' => fairMetricTest(0, 1),
        ])],
    );

    $result = fairOpportunityResolver()->resolve(
        $payload,
        FairImprovementOpportunityResolver::SCOPE_RESOURCE,
        new FairImprovementContext(hasDoi: $hasDoi),
    );

    expect($result['suggestions'][0]['text'])->toBe($expected);
})->with([
    'missing' => [
        false,
        'Register a DOI for the digital resource and point it to the published ERNIE landing page.',
    ],
    'existing' => [
        true,
        'Verify and correct the DOI registration or target so it resolves to the published ERNIE landing page.',
    ],
]);

it('selects unregistered and registered IGSN actions from local state', function (
    bool $registered,
    string $actor,
    string $expected,
): void {
    $payload = fairPayload(
        ['F' => 1, 'A' => 0, 'I' => 0, 'R' => 0],
        [fairMetric('FsF-F1-01MD', 0, 1, [
            'FsF-F1-01MD-1' => fairMetricTest(0, 1),
        ])],
    );

    $result = fairOpportunityResolver()->resolve(
        $payload,
        FairImprovementOpportunityResolver::SCOPE_IGSN,
        new FairImprovementContext(
            hasIgsnMetadata: true,
            igsnRegistered: $registered,
        ),
    );

    expect($result['suggestions'][0])
        ->actor->toBe($actor)
        ->text->toBe($expected);
})->with([
    'unregistered' => [
        false,
        'curator',
        'Register the IGSN with DataCite and point it to a published ERNIE sample landing page so the identifier remains persistent and resolvable.',
    ],
    'registered' => [
        true,
        'administrator',
        'Verify and correct the IGSN registration or resolver target so it resolves to the published ERNIE sample landing page.',
    ],
]);

it('suppresses actions when tracked ERNIE state is newer than the assessment', function (): void {
    $payload = fairPayload(
        ['F' => 1, 'A' => 0, 'I' => 0, 'R' => 0],
        [fairMetric('FsF-F1-01MD', 0, 1, [
            'FsF-F1-01MD-1' => fairMetricTest(0, 1),
        ])],
    );

    $result = fairOpportunityResolver()->resolve(
        $payload,
        FairImprovementOpportunityResolver::SCOPE_RESOURCE,
        new FairImprovementContext(
            assessedAt: new DateTimeImmutable('2026-07-17T10:00:00+00:00'),
            latestRelevantChangeAt: new DateTimeImmutable('2026-07-17T10:00:01+00:00'),
        ),
    );

    expect($result)
        ->status->toBe('available')
        ->dimension->toBe('F')
        ->requiresReassessment->toBeTrue()
        ->suggestions->toBe([])
        ->guidanceMessage->toBe(FairImprovementOpportunityResolver::REASSESSMENT_MESSAGE);
});

it('requires reassessment when the assessed identifier differs from the current DOI', function (): void {
    $payload = fairPayload(['F' => 1, 'A' => 0, 'I' => 0, 'R' => 0]);

    $result = fairOpportunityResolver()->resolve(
        $payload,
        FairImprovementOpportunityResolver::SCOPE_RESOURCE,
        new FairImprovementContext(
            currentIdentifier: '10.5880/current',
            assessedIdentifier: '10.5880/previous',
        ),
    );

    expect($result)
        ->requiresReassessment->toBeTrue()
        ->suggestions->toBe([]);
});

it('treats DOI casing and surrounding whitespace as the same assessed identifier', function (): void {
    $payload = fairPayload(
        ['F' => 1, 'A' => 0, 'I' => 0, 'R' => 0],
        [fairMetric('FsF-F1-01MD', 0, 1, [
            'FsF-F1-01MD-1' => fairMetricTest(0, 1),
        ])],
    );

    $result = fairOpportunityResolver()->resolve(
        $payload,
        FairImprovementOpportunityResolver::SCOPE_RESOURCE,
        new FairImprovementContext(
            currentIdentifier: '10.5880/Example',
            assessedIdentifier: ' 10.5880/example ',
        ),
    );

    expect($result)
        ->requiresReassessment->toBeFalse()
        ->and($result['suggestions'])->toHaveCount(1);
});

it('keeps distribution-dependent Resource guidance at the administrator prerequisite', function (): void {
    $payload = fairPayload(
        ['F' => 1, 'A' => 0, 'I' => 0, 'R' => 0],
        [fairMetric('FsF-F3-01M', 0, 1, [
            'FsF-F3-01M-2' => fairMetricTest(0, 1),
        ])],
    );

    $prerequisite = fairOpportunityResolver()->resolve(
        $payload,
        FairImprovementOpportunityResolver::SCOPE_RESOURCE,
        new FairImprovementContext(hasConfiguredDownloads: false),
    );
    $verifiedFollowUp = fairOpportunityResolver()->resolve(
        $payload,
        FairImprovementOpportunityResolver::SCOPE_RESOURCE,
        new FairImprovementContext(machineReadableDistributionVerified: true),
    );

    expect($prerequisite['suggestions'][0])
        ->key->toBe('resource-machine-readable-distribution')
        ->actor->toBe('administrator')
        ->and($verifiedFollowUp['suggestions'][0])
        ->key->toBe('resource-data-identifier')
        ->actor->toBe('curator');
});

it('keeps a valid raw gap available when no verified action exists', function (): void {
    $payload = fairPayload(
        ['F' => 3, 'A' => 0, 'I' => 0, 'R' => 0],
        [fairMetric('FsF-F9-99M', 0, 3)],
    );

    $result = fairOpportunityResolver()->resolve(
        $payload,
        FairImprovementOpportunityResolver::SCOPE_RESOURCE,
        new FairImprovementContext,
    );

    expect($result)
        ->status->toBe('available')
        ->dimension->toBe('F')
        ->suggestions->toBe([])
        ->guidanceMessage->toBe(FairImprovementOpportunityResolver::NO_VERIFIED_ACTION_MESSAGE);
});

it('never invents actions for an unknown metric profile', function (): void {
    $payload = fairPayload(
        ['F' => 2, 'A' => 0, 'I' => 0, 'R' => 0],
        [fairMetric('FsF-F4-01M', 0, 2, [
            'FsF-F4-01M-1' => fairMetricTest(0, 2),
        ])],
        '0.9',
    );

    $result = fairOpportunityResolver()->resolve(
        $payload,
        FairImprovementOpportunityResolver::SCOPE_RESOURCE,
        new FairImprovementContext,
    );

    expect($result)
        ->status->toBe('available')
        ->suggestions->toBe([])
        ->guidanceMessage->toBe(FairImprovementOpportunityResolver::NO_VERIFIED_ACTION_MESSAGE);
});

it('excludes every exact non-applicable digital-data test from IGSN actions', function (
    string $metricIdentifier,
    string $testIdentifier,
    string $dimension,
): void {
    $gaps = ['F' => 0, 'A' => 0, 'I' => 0, 'R' => 0];
    $gaps[$dimension] = 1;
    $payload = fairPayload(
        $gaps,
        [fairMetric($metricIdentifier, 0, 1, [
            $testIdentifier => fairMetricTest(0, 1),
        ])],
    );

    $result = fairOpportunityResolver()->resolve(
        $payload,
        FairImprovementOpportunityResolver::SCOPE_IGSN,
        new FairImprovementContext(hasIgsnMetadata: true),
    );

    expect($result)
        ->status->toBe('available')
        ->dimension->toBe($dimension)
        ->suggestions->toBe([])
        ->guidanceMessage->toBe(FairImprovementOpportunityResolver::IGSN_DATASET_ONLY_MESSAGE);
})->with([
    ['FsF-F3-01M', 'FsF-F3-01M-2', 'F'],
    ['FsF-A1-02MD', 'FsF-A1-02MD-2', 'A'],
    ['FsF-A1.1-01MD', 'FsF-A1.1-01MD-2', 'A'],
    ['FsF-A1.2-01MD', 'FsF-A1.2-01MD-2', 'A'],
    ['FsF-R1-01M', 'FsF-R1-01M-2', 'R'],
    ['FsF-R1.3-02D', 'FsF-R1.3-02D-1', 'R'],
]);

it('adds a neutral IGSN scope note beside applicable actions', function (): void {
    $payload = fairPayload(
        ['F' => 3, 'A' => 0, 'I' => 0, 'R' => 0],
        [
            fairMetric('FsF-F4-01M', 0, 2, [
                'FsF-F4-01M-1' => fairMetricTest(0, 2),
            ]),
            fairMetric('FsF-F3-01M', 0, 1, [
                'FsF-F3-01M-2' => fairMetricTest(0, 1),
            ]),
        ],
    );

    $result = fairOpportunityResolver()->resolve(
        $payload,
        FairImprovementOpportunityResolver::SCOPE_IGSN,
        new FairImprovementContext(hasIgsnMetadata: true),
    );

    expect($result['suggestions'])
        ->toHaveCount(1)
        ->and($result['scopeNote'])->toBe(FairImprovementOpportunityResolver::IGSN_SCOPE_NOTE)
        ->and($result['suggestions'][0]['text'])->not->toContain('download');
});

it('keeps physical dimensions and mass from activating digital file advice', function (): void {
    $payload = fairPayload(
        ['F' => 0, 'A' => 0, 'I' => 0, 'R' => 1],
        [fairMetric('FsF-R1-01M', 1, 2, [
            'FsF-R1-01M-1' => fairMetricTest(1, 1, 'pass'),
            'FsF-R1-01M-2' => fairMetricTest(0, 1),
        ], 'pass')],
    );

    $result = fairOpportunityResolver()->resolve(
        $payload,
        FairImprovementOpportunityResolver::SCOPE_IGSN,
        new FairImprovementContext(hasIgsnMetadata: true),
    );

    expect($result)
        ->suggestions->toBe([])
        ->guidanceMessage->toBe(FairImprovementOpportunityResolver::IGSN_DATASET_ONLY_MESSAGE);
});

it('returns unavailable for an IGSN scope without IGSN metadata', function (): void {
    $result = fairOpportunityResolver()->resolve(
        fairPayload(['F' => 1, 'A' => 0, 'I' => 0, 'R' => 0]),
        FairImprovementOpportunityResolver::SCOPE_IGSN,
        new FairImprovementContext,
    );

    expect($result)->toBe([
        'status' => 'unavailable',
        'reason' => 'invalid-scope',
        'message' => 'FAIR improvement guidance is unavailable because this entry has no IGSN sample metadata.',
    ]);
});

it('returns unavailable for an unknown scope or inconsistent context', function (): void {
    $unknownScope = fairOpportunityResolver()->resolve(
        fairPayload(['F' => 1, 'A' => 0, 'I' => 0, 'R' => 0]),
        'other',
        new FairImprovementContext,
    );
    $inconsistentContext = fairOpportunityResolver()->resolve(
        fairPayload(['F' => 1, 'A' => 0, 'I' => 0, 'R' => 0]),
        FairImprovementOpportunityResolver::SCOPE_RESOURCE,
        new FairImprovementContext(landingPagePublished: true),
    );

    expect($unknownScope)
        ->status->toBe('unavailable')
        ->reason->toBe('invalid-scope')
        ->and($inconsistentContext)
        ->status->toBe('unavailable')
        ->reason->toBe('invalid-scope');
});

it('ignores malformed metric details without losing a trusted raw indicator', function (): void {
    $payload = fairPayload(
        ['F' => 2, 'A' => 0, 'I' => 0, 'R' => 0],
        [[
            'metric_identifier' => 'FsF-F4-01M',
            'score' => ['earned' => 'invalid', 'total' => 2],
            'metric_tests' => 'invalid',
        ]],
    );

    $result = fairOpportunityResolver()->resolve(
        $payload,
        FairImprovementOpportunityResolver::SCOPE_RESOURCE,
        new FairImprovementContext,
    );

    expect($result)
        ->status->toBe('available')
        ->dimension->toBe('F')
        ->suggestions->toBe([]);
});

it('handles defensive payload shapes without inventing guidance', function (): void {
    $nonArrayResults = fairPayload(['F' => 1, 'A' => 0, 'I' => 0, 'R' => 0]);
    $nonArrayResults['results'] = 'invalid';
    $nonArraySummaryMaps = [
        'metric_version' => '0.8',
        'summary' => [
            'score_earned' => [],
            'score_total' => 'invalid',
        ],
    ];
    $nonArrayMetricScore = fairPayload(
        ['F' => 1, 'A' => 0, 'I' => 0, 'R' => 0],
        [[
            'metric_identifier' => 'FsF-F4-01M',
            'score' => 'invalid',
        ]],
    );
    $unknownMetricFamily = fairPayload(
        ['F' => 1, 'A' => 0, 'I' => 0, 'R' => 0],
        [[
            'metric_identifier' => 'Other-F4',
            'score' => ['earned' => 0, 'total' => 1],
        ]],
    );

    foreach ([$nonArrayResults, $nonArrayMetricScore, $unknownMetricFamily] as $payload) {
        $result = fairOpportunityResolver()->resolve(
            $payload,
            FairImprovementOpportunityResolver::SCOPE_RESOURCE,
            new FairImprovementContext,
        );

        expect($result)
            ->status->toBe('available')
            ->suggestions->toBe([])
            ->guidanceMessage->toBe(FairImprovementOpportunityResolver::NO_VERIFIED_ACTION_MESSAGE);
    }

    expect(fairOpportunityResolver()->resolve(
        $nonArraySummaryMaps,
        FairImprovementOpportunityResolver::SCOPE_RESOURCE,
        new FairImprovementContext,
    ))
        ->status->toBe('unavailable')
        ->reason->toBe('invalid-payload');
});

it('falls back to a metric gap only for rules that do not require test details', function (): void {
    $payload = fairPayload(
        ['F' => 2, 'A' => 0, 'I' => 0, 'R' => 0],
        [[
            'metric_identifier' => 'FsF-F4-01M',
            'score' => ['earned' => 0, 'total' => 2],
            'metric_tests' => 'invalid',
        ]],
    );

    $result = fairOpportunityResolver()->resolve(
        $payload,
        FairImprovementOpportunityResolver::SCOPE_RESOURCE,
        new FairImprovementContext,
    );

    expect($result['suggestions'][0]['key'])->toBe('resource-searchable-landing-page');
});

it('accepts list-shaped keyed tests and ignores malformed test entries', function (): void {
    $payload = fairPayload(
        ['F' => 2, 'A' => 0, 'I' => 0, 'R' => 0],
        [[
            'metric_identifier' => 'FsF-F4-01M',
            'score' => ['earned' => 0, 'total' => 2],
            'metric_tests' => [
                'ignored',
                [
                    'metric_test_identifier' => 'FsF-F4-01M-1',
                    'metric_test_score' => ['earned' => 0, 'total' => 2],
                ],
            ],
        ]],
    );

    $result = fairOpportunityResolver()->resolve(
        $payload,
        FairImprovementOpportunityResolver::SCOPE_RESOURCE,
        new FairImprovementContext,
    );

    expect($result['suggestions'][0]['key'])->toBe('resource-searchable-landing-page');
});

it('ignores a matching test whose score shape cannot be trusted', function (): void {
    $payload = fairPayload(
        ['F' => 2, 'A' => 0, 'I' => 0, 'R' => 0],
        [fairMetric('FsF-F4-01M', 0, 2, [
            'FsF-F4-01M-1' => [
                'metric_test_score' => ['earned' => 'invalid', 'total' => 2],
            ],
        ])],
    );

    $result = fairOpportunityResolver()->resolve(
        $payload,
        FairImprovementOpportunityResolver::SCOPE_RESOURCE,
        new FairImprovementContext,
    );

    expect($result)
        ->suggestions->toBe([])
        ->guidanceMessage->toBe(FairImprovementOpportunityResolver::NO_VERIFIED_ACTION_MESSAGE);
});

it('tolerates scalar earned and maximum test scores from compatible payload variants', function (): void {
    $payload = fairPayload(
        ['F' => 2, 'A' => 0, 'I' => 0, 'R' => 0],
        [fairMetric('FsF-F4-01M', 0, 2, [
            'FsF-F4-01M-1' => [
                'metric_test_score' => 0,
                'metric_test_score_max' => 2,
            ],
        ])],
    );

    $result = fairOpportunityResolver()->resolve(
        $payload,
        FairImprovementOpportunityResolver::SCOPE_RESOURCE,
        new FairImprovementContext,
    );

    expect($result['suggestions'][0]['key'])->toBe('resource-searchable-landing-page');
});

it('explains an IGSN digital-data gap even when the legacy metric omits test details', function (): void {
    $payload = fairPayload(
        ['F' => 1, 'A' => 0, 'I' => 0, 'R' => 0],
        [fairMetric('FsF-F3-01M', 0, 1)],
    );

    $result = fairOpportunityResolver()->resolve(
        $payload,
        FairImprovementOpportunityResolver::SCOPE_IGSN,
        new FairImprovementContext(hasIgsnMetadata: true),
    );

    expect($result)
        ->suggestions->toBe([])
        ->guidanceMessage->toBe(FairImprovementOpportunityResolver::IGSN_DATASET_ONLY_MESSAGE);
});

it('rejects registered IGSN state that has no IGSN metadata context', function (): void {
    expect((new FairImprovementContext(igsnRegistered: true))->isValidForScope(
        FairImprovementOpportunityResolver::SCOPE_RESOURCE,
    ))->toBeFalse();
});

it('keeps the strongest priority when equivalent actions are discovered in reverse order', function (): void {
    $payload = fairPayload(
        ['F' => 0, 'A' => 2, 'I' => 0, 'R' => 0],
        [
            fairMetric('FsF-A1.1-01MD', 0, 1, [
                'FsF-A1.1-01MD-2' => fairMetricTest(0, 1),
            ]),
            fairMetric('FsF-A1-02MD', 0, 1, [
                'FsF-A1-02MD-2' => fairMetricTest(0, 1),
            ]),
        ],
    );

    $result = fairOpportunityResolver()->resolve(
        $payload,
        FairImprovementOpportunityResolver::SCOPE_RESOURCE,
        new FairImprovementContext,
    );

    expect($result['suggestions'])
        ->toHaveCount(1)
        ->and($result['suggestions'][0]['key'])
        ->toBe('resource-machine-readable-distribution');
});

it('does not attribute an inapplicable IGSN gap to a fully earned metric', function (): void {
    $payload = fairPayload(
        ['F' => 1, 'A' => 0, 'I' => 0, 'R' => 0],
        [fairMetric('FsF-F3-01M', 1, 1)],
    );

    $result = fairOpportunityResolver()->resolve(
        $payload,
        FairImprovementOpportunityResolver::SCOPE_IGSN,
        new FairImprovementContext(hasIgsnMetadata: true),
    );

    expect($result)
        ->suggestions->toBe([])
        ->guidanceMessage->toBe(FairImprovementOpportunityResolver::NO_VERIFIED_ACTION_MESSAGE);
});

it('resolves the sanitized representative v0.8 fixtures', function (
    string $fixture,
    string $scope,
    FairImprovementContext $context,
    string $expectedDimension,
): void {
    $result = fairOpportunityResolver()->resolve(
        fairFixture($fixture),
        $scope,
        $context,
    );

    expect($result)
        ->status->toBe('available')
        ->dimension->toBe($expectedDimension)
        ->suggestions->not->toBeEmpty();
})->with([
    'digital resource' => [
        'resource-v0.8.json',
        FairImprovementOpportunityResolver::SCOPE_RESOURCE,
        new FairImprovementContext,
        'F',
    ],
    'physical sample' => [
        'igsn-v0.8.json',
        FairImprovementOpportunityResolver::SCOPE_IGSN,
        new FairImprovementContext(hasIgsnMetadata: true),
        'F',
    ],
]);
