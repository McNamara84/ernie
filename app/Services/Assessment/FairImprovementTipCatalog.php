<?php

declare(strict_types=1);

namespace App\Services\Assessment;

/**
 * Curated, score-causal actions for the pinned F-UJI metrics_v0.8 profile.
 *
 * Copy is deliberately maintained here instead of deriving it from F-UJI
 * metric names, test names, or diagnostic output.
 *
 * @phpstan-type TipRule array{
 *     actionKey: string,
 *     version: string,
 *     scope: 'resource'|'igsn',
 *     applicability: 'full'|'partial',
 *     actor: 'curator'|'administrator',
 *     metricIdentifier: string,
 *     testIdentifiers: list<string>,
 *     aggregation: 'cumulative'|'alternative',
 *     requiresTestDetails: bool,
 *     priority: int,
 *     text: string
 * }
 */
final class FairImprovementTipCatalog
{
    public const VERSION = 'metrics_v0.8';

    public const ACTOR_CURATOR = 'curator';

    public const ACTOR_ADMINISTRATOR = 'administrator';

    /**
     * Positive-score dataset tests that are not actions for a physical sample.
     *
     * @var array<string, array{metricIdentifier: string, dimension: 'F'|'A'|'R', points: float}>
     */
    public const NON_APPLICABLE_IGSN_TESTS = [
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
    ];

    public function normalizeVersion(mixed $version): ?string
    {
        if (is_int($version) || is_float($version)) {
            $numericVersion = (float) $version;

            return is_finite($numericVersion) && abs($numericVersion - 0.8) < 0.000000001
                ? self::VERSION
                : null;
        }

        if (! is_string($version)) {
            return null;
        }

        $normalized = strtolower(trim($version));

        if (in_array($normalized, ['0.8', 'v0.8', self::VERSION], true)) {
            return self::VERSION;
        }

        if (is_numeric($normalized) && abs((float) $normalized - 0.8) < 0.000000001) {
            return self::VERSION;
        }

        return null;
    }

    /**
     * @return list<array{
     *     actionKey: string,
     *     version: string,
     *     scope: 'resource'|'igsn',
     *     applicability: 'full'|'partial',
     *     actor: 'curator'|'administrator',
     *     metricIdentifier: string,
     *     testIdentifiers: list<string>,
     *     aggregation: 'cumulative'|'alternative',
     *     requiresTestDetails: bool,
     *     priority: int,
     *     text: string
     * }>
     */
    public function rulesFor(
        string $version,
        string $scope,
        string $metricIdentifier,
        FairImprovementContext $context,
    ): array {
        if (
            $version !== self::VERSION
            || ! in_array($scope, [
                FairImprovementOpportunityResolver::SCOPE_RESOURCE,
                FairImprovementOpportunityResolver::SCOPE_IGSN,
            ], true)
        ) {
            return [];
        }

        return match ($scope.'|'.$metricIdentifier) {
            'resource|FsF-F1-01MD' => [
                $this->resourceIdentifierRule($metricIdentifier, ['FsF-F1-01MD-1'], 'cumulative', $context),
            ],
            'resource|FsF-F1-02MD' => [
                $this->resourceIdentifierRule(
                    $metricIdentifier,
                    ['FsF-F1-02MD-1', 'FsF-F1-02MD-2'],
                    'cumulative',
                    $context,
                ),
            ],
            'igsn|FsF-F1-01MD' => [
                $this->igsnIdentifierRule($metricIdentifier, ['FsF-F1-01MD-1'], 'cumulative', $context),
            ],
            'igsn|FsF-F1-02MD' => [
                $this->igsnIdentifierRule(
                    $metricIdentifier,
                    ['FsF-F1-02MD-1', 'FsF-F1-02MD-2'],
                    'cumulative',
                    $context,
                ),
            ],
            'resource|FsF-F2-01M' => [
                $this->rule(
                    actionKey: 'resource-core-citation-metadata',
                    scope: $scope,
                    actor: self::ACTOR_CURATOR,
                    metricIdentifier: $metricIdentifier,
                    testIdentifiers: ['FsF-F2-01M-2'],
                    aggregation: 'cumulative',
                    requiresTestDetails: true,
                    priority: 20,
                    text: 'Complete any missing citation metadata in ERNIE.',
                ),
                $this->rule(
                    actionKey: 'resource-descriptive-metadata',
                    scope: $scope,
                    actor: self::ACTOR_CURATOR,
                    metricIdentifier: $metricIdentifier,
                    testIdentifiers: ['FsF-F2-01M-3'],
                    aggregation: 'cumulative',
                    requiresTestDetails: true,
                    priority: 30,
                    text: 'Complete the descriptive metadata by adding any missing Abstract or keywords.',
                ),
            ],
            'igsn|FsF-F2-01M' => [
                $this->rule(
                    actionKey: 'igsn-core-citation-export',
                    scope: $scope,
                    applicability: 'full',
                    actor: self::ACTOR_ADMINISTRATOR,
                    metricIdentifier: $metricIdentifier,
                    testIdentifiers: ['FsF-F2-01M-2'],
                    aggregation: 'cumulative',
                    requiresTestDetails: true,
                    priority: 20,
                    text: 'Expose any missing core physical-sample citation metadata through ERNIE\'s DataCite and structured-metadata exports.',
                ),
                $this->rule(
                    actionKey: 'igsn-description-export',
                    scope: $scope,
                    applicability: 'full',
                    actor: self::ACTOR_ADMINISTRATOR,
                    metricIdentifier: $metricIdentifier,
                    testIdentifiers: ['FsF-F2-01M-3'],
                    aggregation: 'cumulative',
                    requiresTestDetails: true,
                    priority: 30,
                    text: 'Expose the physical sample\'s description and keywords through standard machine-readable metadata.',
                ),
            ],
            'resource|FsF-F3-01M' => [
                $this->distributionRule(
                    scope: $scope,
                    metricIdentifier: $metricIdentifier,
                    testIdentifiers: ['FsF-F3-01M-2'],
                    aggregation: 'cumulative',
                    requiresTestDetails: false,
                    priority: 40,
                    context: $context,
                    followUpKey: 'resource-data-identifier',
                    noDownloadText: 'Add a public Download URL in Landing Page settings so the digital resource is identified in its machine-readable metadata.',
                    configuredDownloadText: 'Verify the configured public Download URL so it remains identifiable in the digital resource\'s machine-readable metadata.',
                ),
            ],
            'resource|FsF-F4-01M' => [
                $this->searchableMetadataRule($scope, $metricIdentifier, $context),
            ],
            'igsn|FsF-F4-01M' => [
                $this->searchableMetadataRule($scope, $metricIdentifier, $context),
            ],
            'resource|FsF-A1-01M' => [
                $this->rule(
                    actionKey: 'resource-machine-readable-access-level',
                    scope: $scope,
                    actor: self::ACTOR_ADMINISTRATOR,
                    metricIdentifier: $metricIdentifier,
                    testIdentifiers: ['FsF-A1-01M-1'],
                    aggregation: 'alternative',
                    requiresTestDetails: false,
                    priority: 10,
                    text: 'Add a reliable data-access level to ERNIE and expose the digital resource\'s access conditions through machine-readable metadata.',
                ),
            ],
            'igsn|FsF-A1-01M' => [
                $this->rule(
                    actionKey: 'igsn-machine-readable-access-level',
                    scope: $scope,
                    actor: self::ACTOR_ADMINISTRATOR,
                    metricIdentifier: $metricIdentifier,
                    testIdentifiers: ['FsF-A1-01M-1'],
                    aggregation: 'alternative',
                    requiresTestDetails: false,
                    priority: 10,
                    text: 'Expose the physical sample\'s access conditions through DataCite rightsList and the ERNIE sample metadata.',
                ),
            ],
            'resource|FsF-A1-02MD' => [
                $this->retrievableMetadataRule($scope, $metricIdentifier, $context),
                $this->distributionRule(
                    scope: $scope,
                    metricIdentifier: $metricIdentifier,
                    testIdentifiers: ['FsF-A1-02MD-2'],
                    aggregation: 'cumulative',
                    requiresTestDetails: true,
                    priority: 30,
                    context: $context,
                    followUpKey: 'resource-data-retrieval',
                    noDownloadText: 'Add a public Download URL in Landing Page settings so the digital data can be retrieved from its machine-readable metadata.',
                    configuredDownloadText: 'Correct the configured public Download URL so the digital data is retrievable from its machine-readable metadata.',
                ),
            ],
            'igsn|FsF-A1-02MD' => [
                $this->retrievableMetadataRule($scope, $metricIdentifier, $context),
            ],
            'resource|FsF-A1.1-01MD' => [
                $this->metadataProtocolRule($scope, $metricIdentifier, 'FsF-A1.1-01MD-1', $context),
                $this->distributionRule(
                    scope: $scope,
                    metricIdentifier: $metricIdentifier,
                    testIdentifiers: ['FsF-A1.1-01MD-2'],
                    aggregation: 'cumulative',
                    requiresTestDetails: true,
                    priority: 40,
                    context: $context,
                    followUpKey: 'resource-data-https',
                    noDownloadText: 'Add an HTTPS Download URL in Landing Page settings so the digital data uses a standard web protocol.',
                    configuredDownloadText: 'Use an HTTPS endpoint for the configured digital-resource download.',
                ),
            ],
            'igsn|FsF-A1.1-01MD' => [
                $this->metadataProtocolRule($scope, $metricIdentifier, 'FsF-A1.1-01MD-1', $context),
            ],
            'resource|FsF-A1.2-01MD' => [
                $this->metadataProtocolRule($scope, $metricIdentifier, 'FsF-A1.2-01MD-1', $context),
                $this->distributionRule(
                    scope: $scope,
                    metricIdentifier: $metricIdentifier,
                    testIdentifiers: ['FsF-A1.2-01MD-2'],
                    aggregation: 'cumulative',
                    requiresTestDetails: true,
                    priority: 40,
                    context: $context,
                    followUpKey: 'resource-data-https',
                    noDownloadText: 'Add an HTTPS Download URL in Landing Page settings so the digital data uses an authentication-capable protocol.',
                    configuredDownloadText: 'Use an HTTPS endpoint for the configured digital-resource download so its protocol supports authentication.',
                ),
            ],
            'igsn|FsF-A1.2-01MD' => [
                $this->metadataProtocolRule($scope, $metricIdentifier, 'FsF-A1.2-01MD-1', $context),
            ],
            'resource|FsF-I1-01M', 'igsn|FsF-I1-01M' => [
                $this->formalMetadataRule($scope, $metricIdentifier, $context),
            ],
            'resource|FsF-I2-01M' => [
                $this->rule(
                    actionKey: 'resource-registered-vocabularies',
                    scope: $scope,
                    actor: self::ACTOR_CURATOR,
                    metricIdentifier: $metricIdentifier,
                    testIdentifiers: ['FsF-I2-01M-2'],
                    aggregation: 'cumulative',
                    requiresTestDetails: false,
                    priority: 20,
                    text: 'Select keywords from ERNIE\'s registered controlled vocabularies and preserve their identifiers.',
                ),
            ],
            'igsn|FsF-I2-01M' => [
                $this->rule(
                    actionKey: 'igsn-registered-vocabulary-export',
                    scope: $scope,
                    actor: self::ACTOR_ADMINISTRATOR,
                    metricIdentifier: $metricIdentifier,
                    testIdentifiers: ['FsF-I2-01M-2'],
                    aggregation: 'cumulative',
                    requiresTestDetails: false,
                    priority: 20,
                    text: 'Expose sample classifications, materials, and geological terms with registered vocabulary identifiers in the IGSN metadata.',
                ),
            ],
            'resource|FsF-I3-01M' => [
                $this->rule(
                    actionKey: 'resource-qualified-relations',
                    scope: $scope,
                    actor: self::ACTOR_CURATOR,
                    metricIdentifier: $metricIdentifier,
                    testIdentifiers: ['FsF-I3-01M-1', 'FsF-I3-01M-2'],
                    aggregation: 'alternative',
                    requiresTestDetails: false,
                    priority: 30,
                    text: 'Add related work with a relation type and a machine-readable DOI, IGSN, or other supported identifier.',
                ),
            ],
            'igsn|FsF-I3-01M' => [
                $this->rule(
                    actionKey: 'igsn-qualified-relations-export',
                    scope: $scope,
                    actor: self::ACTOR_ADMINISTRATOR,
                    metricIdentifier: $metricIdentifier,
                    testIdentifiers: ['FsF-I3-01M-1', 'FsF-I3-01M-2'],
                    aggregation: 'alternative',
                    requiresTestDetails: false,
                    priority: 30,
                    text: 'Extend the IGSN update workflow to expose parent, child, or related-sample identifiers with an explicit relation type.',
                ),
            ],
            'resource|FsF-R1-01M' => [
                $this->rule(
                    actionKey: 'resource-specific-resource-type',
                    scope: $scope,
                    actor: self::ACTOR_CURATOR,
                    metricIdentifier: $metricIdentifier,
                    testIdentifiers: ['FsF-R1-01M-1'],
                    aggregation: 'cumulative',
                    requiresTestDetails: true,
                    priority: 10,
                    text: 'Select the specific Resource Type in Resource Information and save the digital resource.',
                ),
                $this->distributionRule(
                    scope: $scope,
                    metricIdentifier: $metricIdentifier,
                    testIdentifiers: ['FsF-R1-01M-2'],
                    aggregation: 'cumulative',
                    requiresTestDetails: true,
                    priority: 40,
                    context: $context,
                    followUpKey: 'resource-data-content-descriptors',
                    noDownloadText: 'Add a public Download URL, then use Size and Format Suggestions to expose its digital file type and size.',
                    configuredDownloadText: 'Use Size and Format Suggestions to expose any missing digital file type and size for the configured download.',
                ),
            ],
            'igsn|FsF-R1-01M' => [
                $this->rule(
                    actionKey: 'igsn-sample-type-export',
                    scope: $scope,
                    applicability: 'partial',
                    actor: self::ACTOR_ADMINISTRATOR,
                    metricIdentifier: $metricIdentifier,
                    testIdentifiers: ['FsF-R1-01M-1'],
                    aggregation: 'cumulative',
                    requiresTestDetails: true,
                    priority: 10,
                    text: 'Expose the physical sample type through standard machine-readable metadata.',
                ),
            ],
            'resource|FsF-R1.1-01M' => [
                $this->rule(
                    actionKey: 'resource-usage-licence',
                    scope: $scope,
                    actor: self::ACTOR_CURATOR,
                    metricIdentifier: $metricIdentifier,
                    testIdentifiers: ['FsF-R1.1-01M-1'],
                    aggregation: 'cumulative',
                    requiresTestDetails: false,
                    priority: 20,
                    text: 'Select an explicit licence in Licences and Rights and save the digital resource so ERNIE republishes it to DataCite.',
                ),
            ],
            'igsn|FsF-R1.1-01M' => [
                $this->rule(
                    actionKey: 'igsn-usage-licence-export',
                    scope: $scope,
                    actor: self::ACTOR_ADMINISTRATOR,
                    metricIdentifier: $metricIdentifier,
                    testIdentifiers: ['FsF-R1.1-01M-1'],
                    aggregation: 'cumulative',
                    requiresTestDetails: false,
                    priority: 20,
                    text: 'Expose the physical sample\'s licence or reuse conditions through DataCite rightsList.',
                ),
            ],
            'resource|FsF-R1.2-01M' => [
                $this->rule(
                    actionKey: 'resource-provenance-elements',
                    scope: $scope,
                    actor: self::ACTOR_CURATOR,
                    metricIdentifier: $metricIdentifier,
                    testIdentifiers: ['FsF-R1.2-01M-1'],
                    aggregation: 'alternative',
                    requiresTestDetails: true,
                    priority: 30,
                    text: 'Complete any missing creation dates, contributor roles, or qualified source and version relations in ERNIE.',
                ),
                $this->rule(
                    actionKey: 'resource-formal-provenance-export',
                    scope: $scope,
                    actor: self::ACTOR_ADMINISTRATOR,
                    metricIdentifier: $metricIdentifier,
                    testIdentifiers: ['FsF-R1.2-01M-2'],
                    aggregation: 'alternative',
                    requiresTestDetails: true,
                    priority: 35,
                    text: 'Expose the digital resource\'s provenance with a recognised PROV or PAV namespace in ERNIE\'s structured metadata.',
                ),
            ],
            'igsn|FsF-R1.2-01M' => [
                $this->rule(
                    actionKey: 'igsn-provenance-elements-export',
                    scope: $scope,
                    actor: self::ACTOR_ADMINISTRATOR,
                    metricIdentifier: $metricIdentifier,
                    testIdentifiers: ['FsF-R1.2-01M-1'],
                    aggregation: 'alternative',
                    requiresTestDetails: true,
                    priority: 30,
                    text: 'Expose the physical sample\'s collection dates, responsible contributors, and qualified source relations as machine-readable provenance metadata.',
                ),
                $this->rule(
                    actionKey: 'igsn-formal-provenance-export',
                    scope: $scope,
                    actor: self::ACTOR_ADMINISTRATOR,
                    metricIdentifier: $metricIdentifier,
                    testIdentifiers: ['FsF-R1.2-01M-2'],
                    aggregation: 'alternative',
                    requiresTestDetails: true,
                    priority: 35,
                    text: 'Expose the physical sample\'s provenance with a recognised PROV or PAV namespace in ERNIE\'s structured metadata.',
                ),
            ],
            'resource|FsF-R1.3-01M' => [
                $this->rule(
                    actionKey: 'resource-community-metadata-standard',
                    scope: $scope,
                    actor: self::ACTOR_ADMINISTRATOR,
                    metricIdentifier: $metricIdentifier,
                    testIdentifiers: ['FsF-R1.3-01M-1', 'FsF-R1.3-01M-3'],
                    aggregation: 'alternative',
                    requiresTestDetails: false,
                    priority: 50,
                    text: 'Expose the digital resource metadata through a recognised community metadata schema and namespace.',
                ),
            ],
            'igsn|FsF-R1.3-01M' => [
                $this->rule(
                    actionKey: 'igsn-community-metadata-standard',
                    scope: $scope,
                    actor: self::ACTOR_ADMINISTRATOR,
                    metricIdentifier: $metricIdentifier,
                    testIdentifiers: ['FsF-R1.3-01M-1', 'FsF-R1.3-01M-3'],
                    aggregation: 'alternative',
                    requiresTestDetails: false,
                    priority: 50,
                    text: 'Expose the physical-sample metadata through a recognised IGSN or sample-community schema and namespace.',
                ),
            ],
            'resource|FsF-R1.3-02D' => [
                $this->distributionRule(
                    scope: $scope,
                    metricIdentifier: $metricIdentifier,
                    testIdentifiers: ['FsF-R1.3-02D-1'],
                    aggregation: 'alternative',
                    requiresTestDetails: false,
                    priority: 60,
                    context: $context,
                    followUpKey: 'resource-community-file-format',
                    noDownloadText: 'Add a public Download URL for digital content in an open or scientific file format.',
                    configuredDownloadText: 'Point the Download URL to an open or scientific file format and accept the detected format in Size and Format Suggestions.',
                ),
            ],
            default => [],
        };
    }

    /**
     * @param  list<string>  $testIdentifiers
     * @param  'cumulative'|'alternative'  $aggregation
     * @return array{
     *     actionKey: string,
     *     version: string,
     *     scope: 'resource',
     *     applicability: 'full',
     *     actor: 'administrator',
     *     metricIdentifier: string,
     *     testIdentifiers: list<string>,
     *     aggregation: 'cumulative'|'alternative',
     *     requiresTestDetails: false,
     *     priority: int,
     *     text: string
     * }
     *
     * @phpstan-return TipRule
     */
    private function resourceIdentifierRule(
        string $metricIdentifier,
        array $testIdentifiers,
        string $aggregation,
        FairImprovementContext $context,
    ): array {
        return $this->rule(
            actionKey: 'resource-persistent-identifier',
            scope: FairImprovementOpportunityResolver::SCOPE_RESOURCE,
            actor: self::ACTOR_ADMINISTRATOR,
            metricIdentifier: $metricIdentifier,
            testIdentifiers: $testIdentifiers,
            aggregation: $aggregation,
            requiresTestDetails: false,
            priority: 10,
            text: $context->hasDoi
                ? 'Verify and correct the DOI registration or target so it resolves to the published ERNIE landing page.'
                : 'Register a DOI for the digital resource and point it to the published ERNIE landing page.',
        );
    }

    /**
     * @param  list<string>  $testIdentifiers
     * @param  'cumulative'|'alternative'  $aggregation
     * @return array{
     *     actionKey: string,
     *     version: string,
     *     scope: 'igsn',
     *     applicability: 'full',
     *     actor: 'curator'|'administrator',
     *     metricIdentifier: string,
     *     testIdentifiers: list<string>,
     *     aggregation: 'cumulative'|'alternative',
     *     requiresTestDetails: false,
     *     priority: int,
     *     text: string
     * }
     *
     * @phpstan-return TipRule
     */
    private function igsnIdentifierRule(
        string $metricIdentifier,
        array $testIdentifiers,
        string $aggregation,
        FairImprovementContext $context,
    ): array {
        return $this->rule(
            actionKey: 'igsn-persistent-identifier',
            scope: FairImprovementOpportunityResolver::SCOPE_IGSN,
            actor: $context->igsnRegistered ? self::ACTOR_ADMINISTRATOR : self::ACTOR_CURATOR,
            metricIdentifier: $metricIdentifier,
            testIdentifiers: $testIdentifiers,
            aggregation: $aggregation,
            requiresTestDetails: false,
            priority: 10,
            text: $context->igsnRegistered
                ? 'Verify and correct the IGSN registration or resolver target so it resolves to the published ERNIE sample landing page.'
                : 'Register the IGSN with DataCite and point it to a published ERNIE sample landing page so the identifier remains persistent and resolvable.',
        );
    }

    /**
     * @phpstan-param  'resource'|'igsn'  $scope
     *
     * @return array{
     *     actionKey: string,
     *     version: string,
     *     scope: 'resource'|'igsn',
     *     applicability: 'full',
     *     actor: 'curator'|'administrator',
     *     metricIdentifier: string,
     *     testIdentifiers: list<string>,
     *     aggregation: 'cumulative',
     *     requiresTestDetails: false,
     *     priority: int,
     *     text: string
     * }
     *
     * @phpstan-return TipRule
     */
    private function searchableMetadataRule(
        string $scope,
        string $metricIdentifier,
        FairImprovementContext $context,
    ): array {
        if ($scope === FairImprovementOpportunityResolver::SCOPE_IGSN) {
            if (! $context->landingPageExists || ! $context->landingPagePublished) {
                return $this->rule(
                    actionKey: 'igsn-searchable-landing-page',
                    scope: $scope,
                    actor: self::ACTOR_CURATOR,
                    metricIdentifier: $metricIdentifier,
                    testIdentifiers: ['FsF-F4-01M-1'],
                    aggregation: 'cumulative',
                    requiresTestDetails: false,
                    priority: 50,
                    text: 'Publish the ERNIE sample landing page so its structured metadata can be indexed by search engines.',
                );
            }

            return $this->rule(
                actionKey: 'igsn-searchable-metadata-export',
                scope: $scope,
                actor: self::ACTOR_ADMINISTRATOR,
                metricIdentifier: $metricIdentifier,
                testIdentifiers: ['FsF-F4-01M-1'],
                aggregation: 'cumulative',
                requiresTestDetails: false,
                priority: 50,
                text: $context->landingPageIsInternal
                    ? 'Correct the published ERNIE sample landing page\'s IGSN JSON-LD export so search engines can index it.'
                    : 'Configure the published external sample landing page to expose crawlable structured IGSN metadata.',
            );
        }

        if (! $context->landingPageExists || ! $context->landingPagePublished) {
            return $this->rule(
                actionKey: 'resource-searchable-landing-page',
                scope: $scope,
                actor: self::ACTOR_CURATOR,
                metricIdentifier: $metricIdentifier,
                testIdentifiers: ['FsF-F4-01M-1'],
                aggregation: 'cumulative',
                requiresTestDetails: false,
                priority: 50,
                text: 'Use an ERNIE landing-page template and keep the page published so search-engine-readable Schema.org metadata is embedded.',
            );
        }

        return $this->rule(
            actionKey: 'resource-searchable-metadata-export',
            scope: $scope,
            actor: self::ACTOR_ADMINISTRATOR,
            metricIdentifier: $metricIdentifier,
            testIdentifiers: ['FsF-F4-01M-1'],
            aggregation: 'cumulative',
            requiresTestDetails: false,
            priority: 50,
            text: $context->landingPageIsInternal
                ? 'Make the published ERNIE landing page\'s Schema.org metadata crawlable in the initial server response.'
                : 'Configure the published external landing page to expose crawlable Schema.org metadata for the digital resource.',
        );
    }

    /**
     * @phpstan-param  'resource'|'igsn'  $scope
     *
     * @return array{
     *     actionKey: string,
     *     version: string,
     *     scope: 'resource'|'igsn',
     *     applicability: 'full'|'partial',
     *     actor: 'curator'|'administrator',
     *     metricIdentifier: string,
     *     testIdentifiers: list<string>,
     *     aggregation: 'cumulative',
     *     requiresTestDetails: true,
     *     priority: int,
     *     text: string
     * }
     *
     * @phpstan-return TipRule
     */
    private function retrievableMetadataRule(
        string $scope,
        string $metricIdentifier,
        FairImprovementContext $context,
    ): array {
        if ($scope === FairImprovementOpportunityResolver::SCOPE_IGSN) {
            $curatorAction = ! $context->landingPagePublished || ! $context->igsnRegistered;

            return $this->rule(
                actionKey: $curatorAction
                    ? 'igsn-retrievable-metadata'
                    : 'igsn-retrievable-metadata-endpoint',
                scope: $scope,
                applicability: 'partial',
                actor: $curatorAction ? self::ACTOR_CURATOR : self::ACTOR_ADMINISTRATOR,
                metricIdentifier: $metricIdentifier,
                testIdentifiers: ['FsF-A1-02MD-1'],
                aggregation: 'cumulative',
                requiresTestDetails: true,
                priority: 20,
                text: $curatorAction
                    ? 'Publish an ERNIE sample landing page and update the DataCite registration so the IGSN resolves to retrievable sample metadata.'
                    : 'Verify and correct the IGSN resolver or published sample metadata response so the metadata is retrievable by its identifier.',
            );
        }

        $curatorAction = ! $context->landingPagePublished;

        return $this->rule(
            actionKey: $curatorAction
                ? 'resource-retrievable-metadata'
                : 'resource-retrievable-metadata-endpoint',
            scope: $scope,
            actor: $curatorAction ? self::ACTOR_CURATOR : self::ACTOR_ADMINISTRATOR,
            metricIdentifier: $metricIdentifier,
            testIdentifiers: ['FsF-A1-02MD-1'],
            aggregation: 'cumulative',
            requiresTestDetails: true,
            priority: 20,
            text: $curatorAction
                ? 'Publish the ERNIE landing page and update the DOI registration so the digital resource\'s metadata is retrievable by its identifier.'
                : 'Verify and correct the DOI resolver, landing-page endpoint, or metadata response so the published digital resource metadata is retrievable.',
        );
    }

    /**
     * @phpstan-param  'resource'|'igsn'  $scope
     *
     * @return array{
     *     actionKey: string,
     *     version: string,
     *     scope: 'resource'|'igsn',
     *     applicability: 'full'|'partial',
     *     actor: 'curator'|'administrator',
     *     metricIdentifier: string,
     *     testIdentifiers: list<string>,
     *     aggregation: 'cumulative',
     *     requiresTestDetails: true,
     *     priority: int,
     *     text: string
     * }
     *
     * @phpstan-return TipRule
     */
    private function metadataProtocolRule(
        string $scope,
        string $metricIdentifier,
        string $testIdentifier,
        FairImprovementContext $context,
    ): array {
        $isIgsn = $scope === FairImprovementOpportunityResolver::SCOPE_IGSN;
        $needsCuratorAction = ! $context->landingPageExists || ! $context->landingPagePublished;

        if ($needsCuratorAction) {
            return $this->rule(
                actionKey: $isIgsn ? 'igsn-metadata-https' : 'resource-metadata-https',
                scope: $scope,
                applicability: $isIgsn ? 'partial' : 'full',
                actor: self::ACTOR_CURATOR,
                metricIdentifier: $metricIdentifier,
                testIdentifiers: [$testIdentifier],
                aggregation: 'cumulative',
                requiresTestDetails: true,
                priority: 40,
                text: $isIgsn
                    ? 'Use an HTTPS landing-page target for this IGSN so its metadata is available through a standard, authentication-capable web protocol.'
                    : 'Use an HTTPS landing-page target for the digital resource so its metadata is available through a standard, authentication-capable web protocol.',
            );
        }

        return $this->rule(
            actionKey: $isIgsn ? 'igsn-metadata-https-endpoint' : 'resource-metadata-https-endpoint',
            scope: $scope,
            applicability: $isIgsn ? 'partial' : 'full',
            actor: self::ACTOR_ADMINISTRATOR,
            metricIdentifier: $metricIdentifier,
            testIdentifiers: [$testIdentifier],
            aggregation: 'cumulative',
            requiresTestDetails: true,
            priority: 40,
            text: $context->landingPageUsesHttps
                ? ($isIgsn
                    ? 'Verify and correct the published IGSN metadata endpoint so its standard, authentication-capable HTTPS protocol is machine-readable.'
                    : 'Verify and correct the published digital-resource metadata endpoint so its standard, authentication-capable HTTPS protocol is machine-readable.')
                : ($isIgsn
                    ? 'Configure the published IGSN landing-page target to use an authentication-capable HTTPS protocol.'
                    : 'Configure the published digital-resource landing-page target to use an authentication-capable HTTPS protocol.'),
        );
    }

    /**
     * @phpstan-param  'resource'|'igsn'  $scope
     *
     * @return array{
     *     actionKey: string,
     *     version: string,
     *     scope: 'resource'|'igsn',
     *     applicability: 'full',
     *     actor: 'curator'|'administrator',
     *     metricIdentifier: string,
     *     testIdentifiers: list<string>,
     *     aggregation: 'alternative',
     *     requiresTestDetails: false,
     *     priority: int,
     *     text: string
     * }
     *
     * @phpstan-return TipRule
     */
    private function formalMetadataRule(
        string $scope,
        string $metricIdentifier,
        FairImprovementContext $context,
    ): array {
        $isIgsn = $scope === FairImprovementOpportunityResolver::SCOPE_IGSN;
        $curatorAction = ! $context->landingPageExists || ! $context->landingPagePublished;

        if ($curatorAction) {
            return $this->rule(
                actionKey: $isIgsn ? 'igsn-formal-landing-page' : 'resource-formal-landing-page',
                scope: $scope,
                actor: self::ACTOR_CURATOR,
                metricIdentifier: $metricIdentifier,
                testIdentifiers: ['FsF-I1-01M-1', 'FsF-I1-01M-2'],
                aggregation: 'alternative',
                requiresTestDetails: false,
                priority: 10,
                text: $isIgsn
                    ? 'Publish the ERNIE sample landing page so it exposes parsable structured metadata.'
                    : 'Publish the ERNIE landing page so it exposes parsable Schema.org JSON-LD.',
            );
        }

        return $this->rule(
            actionKey: $isIgsn ? 'igsn-formal-metadata-export' : 'resource-formal-metadata-export',
            scope: $scope,
            actor: self::ACTOR_ADMINISTRATOR,
            metricIdentifier: $metricIdentifier,
            testIdentifiers: ['FsF-I1-01M-1', 'FsF-I1-01M-2'],
            aggregation: 'alternative',
            requiresTestDetails: false,
            priority: 10,
            text: $isIgsn
                ? 'Correct the published sample landing page so its IGSN-specific structured metadata is parsable in the harvested response.'
                : 'Correct the published landing page so its Schema.org JSON-LD is parsable in the harvested response.',
        );
    }

    /**
     * @param  list<string>  $testIdentifiers
     * @param  'cumulative'|'alternative'  $aggregation
     * @return array{
     *     actionKey: string,
     *     version: string,
     *     scope: 'resource',
     *     applicability: 'full',
     *     actor: 'curator'|'administrator',
     *     metricIdentifier: string,
     *     testIdentifiers: list<string>,
     *     aggregation: 'cumulative'|'alternative',
     *     requiresTestDetails: bool,
     *     priority: int,
     *     text: string
     * }
     *
     * @phpstan-return TipRule
     */
    private function distributionRule(
        string $scope,
        string $metricIdentifier,
        array $testIdentifiers,
        string $aggregation,
        bool $requiresTestDetails,
        int $priority,
        FairImprovementContext $context,
        string $followUpKey,
        string $noDownloadText,
        string $configuredDownloadText,
    ): array {
        if (! $context->machineReadableDistributionVerified) {
            return $this->rule(
                actionKey: 'resource-machine-readable-distribution',
                scope: FairImprovementOpportunityResolver::SCOPE_RESOURCE,
                actor: self::ACTOR_ADMINISTRATOR,
                metricIdentifier: $metricIdentifier,
                testIdentifiers: $testIdentifiers,
                aggregation: $aggregation,
                requiresTestDetails: $requiresTestDetails,
                priority: $priority,
                text: 'Expose the configured download URL as a machine-readable data distribution in ERNIE so F-UJI can identify and retrieve the digital resource.',
            );
        }

        return $this->rule(
            actionKey: $followUpKey,
            scope: FairImprovementOpportunityResolver::SCOPE_RESOURCE,
            actor: self::ACTOR_CURATOR,
            metricIdentifier: $metricIdentifier,
            testIdentifiers: $testIdentifiers,
            aggregation: $aggregation,
            requiresTestDetails: $requiresTestDetails,
            priority: $priority,
            text: $context->hasConfiguredDownloads ? $configuredDownloadText : $noDownloadText,
        );
    }

    /**
     * @param  'resource'|'igsn'  $scope
     * @param  'full'|'partial'  $applicability
     * @param  'curator'|'administrator'  $actor
     * @param  list<string>  $testIdentifiers
     * @param  'cumulative'|'alternative'  $aggregation
     * @return array{
     *     actionKey: string,
     *     version: string,
     *     scope: 'resource'|'igsn',
     *     applicability: 'full'|'partial',
     *     actor: 'curator'|'administrator',
     *     metricIdentifier: string,
     *     testIdentifiers: list<string>,
     *     aggregation: 'cumulative'|'alternative',
     *     requiresTestDetails: bool,
     *     priority: int,
     *     text: string
     * }
     */
    private function rule(
        string $actionKey,
        string $scope,
        string $actor,
        string $metricIdentifier,
        array $testIdentifiers,
        string $aggregation,
        bool $requiresTestDetails,
        int $priority,
        string $text,
        string $applicability = 'full',
    ): array {
        return [
            'actionKey' => $actionKey,
            'version' => self::VERSION,
            'scope' => $scope,
            'applicability' => $applicability,
            'actor' => $actor,
            'metricIdentifier' => $metricIdentifier,
            'testIdentifiers' => $testIdentifiers,
            'aggregation' => $aggregation,
            'requiresTestDetails' => $requiresTestDetails,
            'priority' => $priority,
            'text' => $text,
        ];
    }
}
