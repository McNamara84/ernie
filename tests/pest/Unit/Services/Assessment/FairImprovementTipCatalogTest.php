<?php

declare(strict_types=1);

use App\Services\Assessment\FairImprovementContext;
use App\Services\Assessment\FairImprovementOpportunityResolver;
use App\Services\Assessment\FairImprovementTipCatalog;

/**
 * @param  'curator'|'administrator'  $actor
 * @param  list<string>  $testIdentifiers
 * @param  'cumulative'|'alternative'  $aggregation
 * @param  'full'|'partial'  $applicability
 * @return array{
 *     actionKey: string,
 *     actor: 'curator'|'administrator',
 *     applicability: 'full'|'partial',
 *     testIdentifiers: list<string>,
 *     aggregation: 'cumulative'|'alternative',
 *     requiresTestDetails: bool,
 *     priority: int,
 *     text: string
 * }
 */
function fairCatalogExpectedRule(
    string $actionKey,
    string $actor,
    array $testIdentifiers,
    string $aggregation,
    bool $requiresTestDetails,
    int $priority,
    string $text,
    string $applicability = 'full',
): array {
    return compact(
        'actionKey',
        'actor',
        'applicability',
        'testIdentifiers',
        'aggregation',
        'requiresTestDetails',
        'priority',
        'text',
    );
}

/**
 * @param  array<string, mixed>  $rule
 * @return array{
 *     actionKey: mixed,
 *     actor: mixed,
 *     applicability: mixed,
 *     testIdentifiers: mixed,
 *     aggregation: mixed,
 *     requiresTestDetails: mixed,
 *     priority: mixed,
 *     text: mixed
 * }
 */
function fairCatalogRuleSnapshot(array $rule): array
{
    return [
        'actionKey' => $rule['actionKey'] ?? null,
        'actor' => $rule['actor'] ?? null,
        'applicability' => $rule['applicability'] ?? null,
        'testIdentifiers' => $rule['testIdentifiers'] ?? null,
        'aggregation' => $rule['aggregation'] ?? null,
        'requiresTestDetails' => $rule['requiresTestDetails'] ?? null,
        'priority' => $rule['priority'] ?? null,
        'text' => $rule['text'] ?? null,
    ];
}

dataset('base FAIR tip catalog', [
    'Resource F1 unique identifier' => [
        'resource',
        'FsF-F1-01MD',
        [
            fairCatalogExpectedRule(
                'resource-persistent-identifier',
                'administrator',
                ['FsF-F1-01MD-1'],
                'cumulative',
                false,
                10,
                'Register a DOI for the digital resource and point it to the published ERNIE landing page.',
            ),
        ],
    ],
    'Resource F1 persistent identifier' => [
        'resource',
        'FsF-F1-02MD',
        [
            fairCatalogExpectedRule(
                'resource-persistent-identifier',
                'administrator',
                ['FsF-F1-02MD-1', 'FsF-F1-02MD-2'],
                'cumulative',
                false,
                10,
                'Register a DOI for the digital resource and point it to the published ERNIE landing page.',
            ),
        ],
    ],
    'Resource F2' => [
        'resource',
        'FsF-F2-01M',
        [
            fairCatalogExpectedRule(
                'resource-core-citation-metadata',
                'curator',
                ['FsF-F2-01M-2'],
                'cumulative',
                true,
                20,
                'Complete any missing citation metadata in ERNIE.',
            ),
            fairCatalogExpectedRule(
                'resource-descriptive-metadata',
                'curator',
                ['FsF-F2-01M-3'],
                'cumulative',
                true,
                30,
                'Complete the descriptive metadata by adding any missing Abstract or keywords.',
            ),
        ],
    ],
    'Resource F3' => [
        'resource',
        'FsF-F3-01M',
        [
            fairCatalogExpectedRule(
                'resource-machine-readable-distribution',
                'administrator',
                ['FsF-F3-01M-2'],
                'cumulative',
                false,
                40,
                'Expose the configured download URL as a machine-readable data distribution in ERNIE so F-UJI can identify and retrieve the digital resource.',
            ),
        ],
    ],
    'Resource F4' => [
        'resource',
        'FsF-F4-01M',
        [
            fairCatalogExpectedRule(
                'resource-searchable-landing-page',
                'curator',
                ['FsF-F4-01M-1'],
                'cumulative',
                false,
                50,
                'Use an ERNIE landing-page template and keep the page published so search-engine-readable Schema.org metadata is embedded.',
            ),
        ],
    ],
    'Resource A1 access information' => [
        'resource',
        'FsF-A1-01M',
        [
            fairCatalogExpectedRule(
                'resource-machine-readable-access-level',
                'administrator',
                ['FsF-A1-01M-1'],
                'alternative',
                false,
                10,
                'Add a reliable data-access level to ERNIE and expose the digital resource\'s access conditions through machine-readable metadata.',
            ),
        ],
    ],
    'Resource A1 retrievable metadata and data' => [
        'resource',
        'FsF-A1-02MD',
        [
            fairCatalogExpectedRule(
                'resource-retrievable-metadata',
                'curator',
                ['FsF-A1-02MD-1'],
                'cumulative',
                true,
                20,
                'Publish the ERNIE landing page and update the DOI registration so the digital resource\'s metadata is retrievable by its identifier.',
            ),
            fairCatalogExpectedRule(
                'resource-machine-readable-distribution',
                'administrator',
                ['FsF-A1-02MD-2'],
                'cumulative',
                true,
                30,
                'Expose the configured download URL as a machine-readable data distribution in ERNIE so F-UJI can identify and retrieve the digital resource.',
            ),
        ],
    ],
    'Resource A1 standard protocol' => [
        'resource',
        'FsF-A1.1-01MD',
        [
            fairCatalogExpectedRule(
                'resource-metadata-https',
                'curator',
                ['FsF-A1.1-01MD-1'],
                'cumulative',
                true,
                40,
                'Use an HTTPS landing-page target for the digital resource so its metadata is available through a standard, authentication-capable web protocol.',
            ),
            fairCatalogExpectedRule(
                'resource-machine-readable-distribution',
                'administrator',
                ['FsF-A1.1-01MD-2'],
                'cumulative',
                true,
                40,
                'Expose the configured download URL as a machine-readable data distribution in ERNIE so F-UJI can identify and retrieve the digital resource.',
            ),
        ],
    ],
    'Resource A1 authentication protocol' => [
        'resource',
        'FsF-A1.2-01MD',
        [
            fairCatalogExpectedRule(
                'resource-metadata-https',
                'curator',
                ['FsF-A1.2-01MD-1'],
                'cumulative',
                true,
                40,
                'Use an HTTPS landing-page target for the digital resource so its metadata is available through a standard, authentication-capable web protocol.',
            ),
            fairCatalogExpectedRule(
                'resource-machine-readable-distribution',
                'administrator',
                ['FsF-A1.2-01MD-2'],
                'cumulative',
                true,
                40,
                'Expose the configured download URL as a machine-readable data distribution in ERNIE so F-UJI can identify and retrieve the digital resource.',
            ),
        ],
    ],
    'Resource I1' => [
        'resource',
        'FsF-I1-01M',
        [
            fairCatalogExpectedRule(
                'resource-formal-landing-page',
                'curator',
                ['FsF-I1-01M-1', 'FsF-I1-01M-2'],
                'alternative',
                false,
                10,
                'Publish the ERNIE landing page so it exposes parsable Schema.org JSON-LD.',
            ),
        ],
    ],
    'Resource I2' => [
        'resource',
        'FsF-I2-01M',
        [
            fairCatalogExpectedRule(
                'resource-registered-vocabularies',
                'curator',
                ['FsF-I2-01M-2'],
                'cumulative',
                false,
                20,
                'Select keywords from ERNIE\'s registered controlled vocabularies and preserve their identifiers.',
            ),
        ],
    ],
    'Resource I3' => [
        'resource',
        'FsF-I3-01M',
        [
            fairCatalogExpectedRule(
                'resource-qualified-relations',
                'curator',
                ['FsF-I3-01M-1', 'FsF-I3-01M-2'],
                'alternative',
                false,
                30,
                'Add related work with a relation type and a machine-readable DOI, IGSN, or other supported identifier.',
            ),
        ],
    ],
    'Resource R1 content metadata' => [
        'resource',
        'FsF-R1-01M',
        [
            fairCatalogExpectedRule(
                'resource-specific-resource-type',
                'curator',
                ['FsF-R1-01M-1'],
                'cumulative',
                true,
                10,
                'Select the specific Resource Type in Resource Information and save the digital resource.',
            ),
            fairCatalogExpectedRule(
                'resource-machine-readable-distribution',
                'administrator',
                ['FsF-R1-01M-2'],
                'cumulative',
                true,
                40,
                'Expose the configured download URL as a machine-readable data distribution in ERNIE so F-UJI can identify and retrieve the digital resource.',
            ),
        ],
    ],
    'Resource R1 licence' => [
        'resource',
        'FsF-R1.1-01M',
        [
            fairCatalogExpectedRule(
                'resource-usage-licence',
                'curator',
                ['FsF-R1.1-01M-1'],
                'cumulative',
                false,
                20,
                'Select an explicit licence in Licences and Rights and save the digital resource so ERNIE republishes it to DataCite.',
            ),
        ],
    ],
    'Resource R1 provenance' => [
        'resource',
        'FsF-R1.2-01M',
        [
            fairCatalogExpectedRule(
                'resource-provenance-elements',
                'curator',
                ['FsF-R1.2-01M-1'],
                'alternative',
                true,
                30,
                'Complete any missing creation dates, contributor roles, or qualified source and version relations in ERNIE.',
            ),
            fairCatalogExpectedRule(
                'resource-formal-provenance-export',
                'administrator',
                ['FsF-R1.2-01M-2'],
                'alternative',
                true,
                35,
                'Expose the digital resource\'s provenance with a recognised PROV or PAV namespace in ERNIE\'s structured metadata.',
            ),
        ],
    ],
    'Resource R1 community metadata standard' => [
        'resource',
        'FsF-R1.3-01M',
        [
            fairCatalogExpectedRule(
                'resource-community-metadata-standard',
                'administrator',
                ['FsF-R1.3-01M-1', 'FsF-R1.3-01M-3'],
                'alternative',
                false,
                50,
                'Expose the digital resource metadata through a recognised community metadata schema and namespace.',
            ),
        ],
    ],
    'Resource R1 community file format' => [
        'resource',
        'FsF-R1.3-02D',
        [
            fairCatalogExpectedRule(
                'resource-machine-readable-distribution',
                'administrator',
                ['FsF-R1.3-02D-1'],
                'alternative',
                false,
                60,
                'Expose the configured download URL as a machine-readable data distribution in ERNIE so F-UJI can identify and retrieve the digital resource.',
            ),
        ],
    ],
    'IGSN F1 unique identifier' => [
        'igsn',
        'FsF-F1-01MD',
        [
            fairCatalogExpectedRule(
                'igsn-persistent-identifier',
                'curator',
                ['FsF-F1-01MD-1'],
                'cumulative',
                false,
                10,
                'Register the IGSN with DataCite and point it to a published ERNIE sample landing page so the identifier remains persistent and resolvable.',
            ),
        ],
    ],
    'IGSN F1 persistent identifier' => [
        'igsn',
        'FsF-F1-02MD',
        [
            fairCatalogExpectedRule(
                'igsn-persistent-identifier',
                'curator',
                ['FsF-F1-02MD-1', 'FsF-F1-02MD-2'],
                'cumulative',
                false,
                10,
                'Register the IGSN with DataCite and point it to a published ERNIE sample landing page so the identifier remains persistent and resolvable.',
            ),
        ],
    ],
    'IGSN F2' => [
        'igsn',
        'FsF-F2-01M',
        [
            fairCatalogExpectedRule(
                'igsn-core-citation-export',
                'administrator',
                ['FsF-F2-01M-2'],
                'cumulative',
                true,
                20,
                'Expose any missing core physical-sample citation metadata through ERNIE\'s DataCite and structured-metadata exports.',
            ),
            fairCatalogExpectedRule(
                'igsn-description-export',
                'administrator',
                ['FsF-F2-01M-3'],
                'cumulative',
                true,
                30,
                'Expose the physical sample\'s description and keywords through standard machine-readable metadata.',
            ),
        ],
    ],
    'IGSN F3 is not applicable' => ['igsn', 'FsF-F3-01M', []],
    'IGSN F4' => [
        'igsn',
        'FsF-F4-01M',
        [
            fairCatalogExpectedRule(
                'igsn-searchable-landing-page',
                'curator',
                ['FsF-F4-01M-1'],
                'cumulative',
                false,
                50,
                'Publish the ERNIE sample landing page so its structured metadata can be indexed by search engines.',
            ),
        ],
    ],
    'IGSN A1 access information' => [
        'igsn',
        'FsF-A1-01M',
        [
            fairCatalogExpectedRule(
                'igsn-machine-readable-access-level',
                'administrator',
                ['FsF-A1-01M-1'],
                'alternative',
                false,
                10,
                'Expose the physical sample\'s access conditions through DataCite rightsList and the ERNIE sample metadata.',
            ),
        ],
    ],
    'IGSN A1 retrievable metadata' => [
        'igsn',
        'FsF-A1-02MD',
        [
            fairCatalogExpectedRule(
                'igsn-retrievable-metadata',
                'curator',
                ['FsF-A1-02MD-1'],
                'cumulative',
                true,
                20,
                'Publish an ERNIE sample landing page and update the DataCite registration so the IGSN resolves to retrievable sample metadata.',
                'partial',
            ),
        ],
    ],
    'IGSN A1 standard protocol' => [
        'igsn',
        'FsF-A1.1-01MD',
        [
            fairCatalogExpectedRule(
                'igsn-metadata-https',
                'curator',
                ['FsF-A1.1-01MD-1'],
                'cumulative',
                true,
                40,
                'Use an HTTPS landing-page target for this IGSN so its metadata is available through a standard, authentication-capable web protocol.',
                'partial',
            ),
        ],
    ],
    'IGSN A1 authentication protocol' => [
        'igsn',
        'FsF-A1.2-01MD',
        [
            fairCatalogExpectedRule(
                'igsn-metadata-https',
                'curator',
                ['FsF-A1.2-01MD-1'],
                'cumulative',
                true,
                40,
                'Use an HTTPS landing-page target for this IGSN so its metadata is available through a standard, authentication-capable web protocol.',
                'partial',
            ),
        ],
    ],
    'IGSN I1' => [
        'igsn',
        'FsF-I1-01M',
        [
            fairCatalogExpectedRule(
                'igsn-formal-landing-page',
                'curator',
                ['FsF-I1-01M-1', 'FsF-I1-01M-2'],
                'alternative',
                false,
                10,
                'Publish the ERNIE sample landing page so it exposes parsable structured metadata.',
            ),
        ],
    ],
    'IGSN I2' => [
        'igsn',
        'FsF-I2-01M',
        [
            fairCatalogExpectedRule(
                'igsn-registered-vocabulary-export',
                'administrator',
                ['FsF-I2-01M-2'],
                'cumulative',
                false,
                20,
                'Expose sample classifications, materials, and geological terms with registered vocabulary identifiers in the IGSN metadata.',
            ),
        ],
    ],
    'IGSN I3' => [
        'igsn',
        'FsF-I3-01M',
        [
            fairCatalogExpectedRule(
                'igsn-qualified-relations-export',
                'administrator',
                ['FsF-I3-01M-1', 'FsF-I3-01M-2'],
                'alternative',
                false,
                30,
                'Extend the IGSN update workflow to expose parent, child, or related-sample identifiers with an explicit relation type.',
            ),
        ],
    ],
    'IGSN R1 content metadata' => [
        'igsn',
        'FsF-R1-01M',
        [
            fairCatalogExpectedRule(
                'igsn-sample-type-export',
                'administrator',
                ['FsF-R1-01M-1'],
                'cumulative',
                true,
                10,
                'Expose the physical sample type through standard machine-readable metadata.',
                'partial',
            ),
        ],
    ],
    'IGSN R1 licence' => [
        'igsn',
        'FsF-R1.1-01M',
        [
            fairCatalogExpectedRule(
                'igsn-usage-licence-export',
                'administrator',
                ['FsF-R1.1-01M-1'],
                'cumulative',
                false,
                20,
                'Expose the physical sample\'s licence or reuse conditions through DataCite rightsList.',
            ),
        ],
    ],
    'IGSN R1 provenance' => [
        'igsn',
        'FsF-R1.2-01M',
        [
            fairCatalogExpectedRule(
                'igsn-provenance-elements-export',
                'administrator',
                ['FsF-R1.2-01M-1'],
                'alternative',
                true,
                30,
                'Expose the physical sample\'s collection dates, responsible contributors, and qualified source relations as machine-readable provenance metadata.',
            ),
            fairCatalogExpectedRule(
                'igsn-formal-provenance-export',
                'administrator',
                ['FsF-R1.2-01M-2'],
                'alternative',
                true,
                35,
                'Expose the physical sample\'s provenance with a recognised PROV or PAV namespace in ERNIE\'s structured metadata.',
            ),
        ],
    ],
    'IGSN R1 community metadata standard' => [
        'igsn',
        'FsF-R1.3-01M',
        [
            fairCatalogExpectedRule(
                'igsn-community-metadata-standard',
                'administrator',
                ['FsF-R1.3-01M-1', 'FsF-R1.3-01M-3'],
                'alternative',
                false,
                50,
                'Expose the physical-sample metadata through a recognised IGSN or sample-community schema and namespace.',
            ),
        ],
    ],
    'IGSN R1 community file format is not applicable' => ['igsn', 'FsF-R1.3-02D', []],
]);

it('matches the exact base rule matrix and English copy', function (
    string $scope,
    string $metricIdentifier,
    array $expected,
): void {
    $context = $scope === FairImprovementOpportunityResolver::SCOPE_IGSN
        ? new FairImprovementContext(hasIgsnMetadata: true)
        : new FairImprovementContext;
    $rules = (new FairImprovementTipCatalog)->rulesFor(
        FairImprovementTipCatalog::VERSION,
        $scope,
        $metricIdentifier,
        $context,
    );

    foreach ($rules as $rule) {
        expect($rule)
            ->version->toBe(FairImprovementTipCatalog::VERSION)
            ->scope->toBe($scope)
            ->metricIdentifier->toBe($metricIdentifier);
    }

    expect(array_map(fairCatalogRuleSnapshot(...), $rules))->toBe($expected);
})->with('base FAIR tip catalog');

it('normalizes only supported v0.8 profile aliases', function (mixed $input, ?string $expected): void {
    expect((new FairImprovementTipCatalog)->normalizeVersion($input))->toBe($expected);
})->with([
    'float' => [0.8, FairImprovementTipCatalog::VERSION],
    'plain string' => ['0.8', FairImprovementTipCatalog::VERSION],
    'prefixed string' => [' v0.8 ', FairImprovementTipCatalog::VERSION],
    'profile name' => ['METRICS_V0.8', FairImprovementTipCatalog::VERSION],
    'equivalent numeric string' => ['0.80', FairImprovementTipCatalog::VERSION],
    'unknown profile' => ['0.9', null],
    'empty string' => ['', null],
    'integer' => [8, null],
    'boolean' => [true, null],
    'null' => [null, null],
    'array' => [[], null],
    'infinite' => [INF, null],
    'not a number' => [NAN, null],
]);

it('returns no rules for unsupported versions scopes or metric identifiers', function (
    string $version,
    string $scope,
    string $metricIdentifier,
): void {
    expect((new FairImprovementTipCatalog)->rulesFor(
        $version,
        $scope,
        $metricIdentifier,
        new FairImprovementContext,
    ))->toBe([]);
})->with([
    'version' => ['metrics_v0.9', 'resource', 'FsF-F1-01MD'],
    'scope' => [FairImprovementTipCatalog::VERSION, 'sample', 'FsF-F1-01MD'],
    'metric' => [FairImprovementTipCatalog::VERSION, 'resource', 'FsF-F9-99M'],
]);

it('pins the exact positive-score digital-data tests excluded for IGSNs', function (): void {
    expect(FairImprovementTipCatalog::NON_APPLICABLE_IGSN_TESTS)->toBe([
        'FsF-F3-01M-2' => [
            'metricIdentifier' => 'FsF-F3-01M',
            'dimension' => 'F',
            'points' => 1.0,
        ],
        'FsF-A1-02MD-2' => [
            'metricIdentifier' => 'FsF-A1-02MD',
            'dimension' => 'A',
            'points' => 1.0,
        ],
        'FsF-A1.1-01MD-2' => [
            'metricIdentifier' => 'FsF-A1.1-01MD',
            'dimension' => 'A',
            'points' => 1.0,
        ],
        'FsF-A1.2-01MD-2' => [
            'metricIdentifier' => 'FsF-A1.2-01MD',
            'dimension' => 'A',
            'points' => 1.0,
        ],
        'FsF-R1-01M-2' => [
            'metricIdentifier' => 'FsF-R1-01M',
            'dimension' => 'R',
            'points' => 1.0,
        ],
        'FsF-R1.3-02D-1' => [
            'metricIdentifier' => 'FsF-R1.3-02D',
            'dimension' => 'R',
            'points' => 1.0,
        ],
    ]);
});

it('selects exact identifier wording from current ERNIE state', function (
    string $scope,
    FairImprovementContext $context,
    string $actor,
    string $text,
): void {
    $rule = (new FairImprovementTipCatalog)->rulesFor(
        FairImprovementTipCatalog::VERSION,
        $scope,
        'FsF-F1-01MD',
        $context,
    )[0];

    expect($rule)
        ->actor->toBe($actor)
        ->text->toBe($text);
})->with([
    'existing Resource DOI' => [
        'resource',
        new FairImprovementContext(hasDoi: true),
        'administrator',
        'Verify and correct the DOI registration or target so it resolves to the published ERNIE landing page.',
    ],
    'registered IGSN' => [
        'igsn',
        new FairImprovementContext(hasIgsnMetadata: true, igsnRegistered: true),
        'administrator',
        'Verify and correct the IGSN registration or resolver target so it resolves to the published ERNIE sample landing page.',
    ],
]);

it('selects exact published landing-page platform wording', function (
    string $scope,
    string $metricIdentifier,
    FairImprovementContext $context,
    string $actionKey,
    string $text,
): void {
    $rule = (new FairImprovementTipCatalog)->rulesFor(
        FairImprovementTipCatalog::VERSION,
        $scope,
        $metricIdentifier,
        $context,
    )[0];

    expect($rule)
        ->actionKey->toBe($actionKey)
        ->actor->toBe('administrator')
        ->text->toBe($text);
})->with([
    'Resource searchable export' => [
        'resource',
        'FsF-F4-01M',
        new FairImprovementContext(
            landingPageExists: true,
            landingPagePublished: true,
            landingPageIsInternal: true,
            landingPageUsesHttps: true,
        ),
        'resource-searchable-metadata-export',
        'Make the published ERNIE landing page\'s Schema.org metadata crawlable in the initial server response.',
    ],
    'external IGSN searchable export' => [
        'igsn',
        'FsF-F4-01M',
        new FairImprovementContext(
            hasIgsnMetadata: true,
            landingPageExists: true,
            landingPagePublished: true,
            landingPageIsInternal: false,
            landingPageUsesHttps: true,
        ),
        'igsn-searchable-metadata-export',
        'Configure the published external sample landing page to expose crawlable structured IGSN metadata.',
    ],
    'internal IGSN searchable export' => [
        'igsn',
        'FsF-F4-01M',
        new FairImprovementContext(
            hasIgsnMetadata: true,
            landingPageExists: true,
            landingPagePublished: true,
            landingPageIsInternal: true,
            landingPageUsesHttps: true,
        ),
        'igsn-searchable-metadata-export',
        'Correct the published ERNIE sample landing page\'s IGSN JSON-LD export so search engines can index it.',
    ],
    'Resource retrievable metadata endpoint' => [
        'resource',
        'FsF-A1-02MD',
        new FairImprovementContext(
            landingPageExists: true,
            landingPagePublished: true,
            landingPageIsInternal: true,
            landingPageUsesHttps: true,
        ),
        'resource-retrievable-metadata-endpoint',
        'Verify and correct the DOI resolver, landing-page endpoint, or metadata response so the published digital resource metadata is retrievable.',
    ],
    'IGSN retrievable metadata endpoint' => [
        'igsn',
        'FsF-A1-02MD',
        new FairImprovementContext(
            hasIgsnMetadata: true,
            igsnRegistered: true,
            landingPageExists: true,
            landingPagePublished: true,
            landingPageIsInternal: true,
            landingPageUsesHttps: true,
        ),
        'igsn-retrievable-metadata-endpoint',
        'Verify and correct the IGSN resolver or published sample metadata response so the metadata is retrievable by its identifier.',
    ],
    'Resource HTTPS metadata endpoint' => [
        'resource',
        'FsF-A1.1-01MD',
        new FairImprovementContext(
            landingPageExists: true,
            landingPagePublished: true,
            landingPageIsInternal: true,
            landingPageUsesHttps: true,
        ),
        'resource-metadata-https-endpoint',
        'Verify and correct the published digital-resource metadata endpoint so its standard, authentication-capable HTTPS protocol is machine-readable.',
    ],
    'IGSN non-HTTPS metadata endpoint' => [
        'igsn',
        'FsF-A1.1-01MD',
        new FairImprovementContext(
            hasIgsnMetadata: true,
            landingPageExists: true,
            landingPagePublished: true,
            landingPageIsInternal: false,
            landingPageUsesHttps: false,
        ),
        'igsn-metadata-https-endpoint',
        'Configure the published IGSN landing-page target to use an authentication-capable HTTPS protocol.',
    ],
    'IGSN HTTPS metadata endpoint' => [
        'igsn',
        'FsF-A1.1-01MD',
        new FairImprovementContext(
            hasIgsnMetadata: true,
            landingPageExists: true,
            landingPagePublished: true,
            landingPageIsInternal: true,
            landingPageUsesHttps: true,
        ),
        'igsn-metadata-https-endpoint',
        'Verify and correct the published IGSN metadata endpoint so its standard, authentication-capable HTTPS protocol is machine-readable.',
    ],
    'Resource formal metadata export' => [
        'resource',
        'FsF-I1-01M',
        new FairImprovementContext(
            landingPageExists: true,
            landingPagePublished: true,
            landingPageIsInternal: true,
            landingPageUsesHttps: true,
        ),
        'resource-formal-metadata-export',
        'Correct the published landing page so its Schema.org JSON-LD is parsable in the harvested response.',
    ],
    'IGSN formal metadata export' => [
        'igsn',
        'FsF-I1-01M',
        new FairImprovementContext(
            hasIgsnMetadata: true,
            landingPageExists: true,
            landingPagePublished: true,
            landingPageIsInternal: true,
            landingPageUsesHttps: true,
        ),
        'igsn-formal-metadata-export',
        'Correct the published sample landing page so its IGSN-specific structured metadata is parsable in the harvested response.',
    ],
]);

it('unlocks exact curator distribution follow-up copy only after capability verification', function (
    string $metricIdentifier,
    string $expectedKey,
    string $withoutDownload,
    string $withDownload,
): void {
    $catalog = new FairImprovementTipCatalog;

    foreach ([
        [false, $withoutDownload],
        [true, $withDownload],
    ] as [$hasDownloads, $expectedText]) {
        $rules = $catalog->rulesFor(
            FairImprovementTipCatalog::VERSION,
            FairImprovementOpportunityResolver::SCOPE_RESOURCE,
            $metricIdentifier,
            new FairImprovementContext(
                hasConfiguredDownloads: $hasDownloads,
                machineReadableDistributionVerified: true,
            ),
        );
        $rule = collect($rules)->firstWhere('actionKey', $expectedKey);

        expect($rule)
            ->not->toBeNull()
            ->actor->toBe('curator')
            ->text->toBe($expectedText);
    }
})->with([
    'data identifier' => [
        'FsF-F3-01M',
        'resource-data-identifier',
        'Add a public Download URL in Landing Page settings so the digital resource is identified in its machine-readable metadata.',
        'Verify the configured public Download URL so it remains identifiable in the digital resource\'s machine-readable metadata.',
    ],
    'data retrieval' => [
        'FsF-A1-02MD',
        'resource-data-retrieval',
        'Add a public Download URL in Landing Page settings so the digital data can be retrieved from its machine-readable metadata.',
        'Correct the configured public Download URL so the digital data is retrievable from its machine-readable metadata.',
    ],
    'standard data protocol' => [
        'FsF-A1.1-01MD',
        'resource-data-https',
        'Add an HTTPS Download URL in Landing Page settings so the digital data uses a standard web protocol.',
        'Use an HTTPS endpoint for the configured digital-resource download.',
    ],
    'authentication-capable data protocol' => [
        'FsF-A1.2-01MD',
        'resource-data-https',
        'Add an HTTPS Download URL in Landing Page settings so the digital data uses an authentication-capable protocol.',
        'Use an HTTPS endpoint for the configured digital-resource download so its protocol supports authentication.',
    ],
    'content descriptors' => [
        'FsF-R1-01M',
        'resource-data-content-descriptors',
        'Add a public Download URL, then use Size and Format Suggestions to expose its digital file type and size.',
        'Use Size and Format Suggestions to expose any missing digital file type and size for the configured download.',
    ],
    'community file format' => [
        'FsF-R1.3-02D',
        'resource-community-file-format',
        'Add a public Download URL for digital content in an open or scientific file format.',
        'Point the Download URL to an open or scientific file format and accept the detected format in Size and Format Suggestions.',
    ],
]);
