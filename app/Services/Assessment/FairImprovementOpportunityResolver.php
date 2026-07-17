<?php

declare(strict_types=1);

namespace App\Services\Assessment;

/**
 * Resolves the largest raw FAIR gap and its highest-impact verified actions.
 */
final class FairImprovementOpportunityResolver
{
    public const SCOPE_RESOURCE = 'resource';

    public const SCOPE_IGSN = 'igsn';

    public const NO_VERIFIED_ACTION_MESSAGE = 'ERNIE has no verified score-improving action to recommend for this FAIR category yet.';

    public const IGSN_DATASET_ONLY_MESSAGE = 'F-UJI\'s largest gap here concerns checks for downloadable digital data. Those checks do not apply to a physical sample, so ERNIE has no sample-metadata change to recommend for this category.';

    public const IGSN_SCOPE_NOTE = 'F-UJI also counts digital-data checks in this dimension. ERNIE does not present those checks as actions for a physical sample.';

    public const REASSESSMENT_MESSAGE = 'Run the assessment again to refresh FAIR improvement guidance after the recent ERNIE changes.';

    private const COMPLETE_MESSAGE = 'No FAIR improvement gap was found.';

    private const INVALID_PAYLOAD_MESSAGE = 'Run the assessment again to calculate FAIR improvement opportunities.';

    private const INVALID_IGSN_SCOPE_MESSAGE = 'FAIR improvement guidance is unavailable because this entry has no IGSN sample metadata.';

    private const COMPARISON_TOLERANCE = 0.000000001;

    /** @var list<'F'|'A'|'I'|'R'> */
    private const DIMENSIONS = ['F', 'A', 'I', 'R'];

    /** @var array<'F'|'A'|'I'|'R', string> */
    private const DIMENSION_LABELS = [
        'F' => 'Findability',
        'A' => 'Accessibility',
        'I' => 'Interoperability',
        'R' => 'Reusability',
    ];

    public function __construct(
        private readonly FairImprovementTipCatalog $catalog,
    ) {}

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array{
     *     status: 'available',
     *     dimension: 'F'|'A'|'I'|'R',
     *     dimensionLabel: 'Findability'|'Accessibility'|'Interoperability'|'Reusability',
     *     missingPoints: float,
     *     totalPoints: float,
     *     potentialFairGain: float,
     *     severity: 'low'|'medium'|'high'|'very-high',
     *     requiresReassessment: bool,
     *     suggestions: list<array{key: string, actor: 'curator'|'administrator', text: string}>,
     *     guidanceMessage?: string,
     *     scopeNote?: string
     * }|array{
     *     status: 'complete',
     *     message: 'No FAIR improvement gap was found.'
     * }|array{
     *     status: 'unavailable',
     *     reason: 'invalid-payload'|'invalid-scope',
     *     message: string
     * }
     */
    public function resolve(
        ?array $payload,
        string $scope,
        FairImprovementContext $context,
    ): array {
        $invalidScope = $this->invalidScopeResult($scope, $context);

        if ($invalidScope !== null) {
            return $invalidScope;
        }

        $summary = $this->normalizeSummary($payload);

        if ($summary === null) {
            return $this->unavailable(
                reason: 'invalid-payload',
                message: self::INVALID_PAYLOAD_MESSAGE,
            );
        }

        $largestGap = max($summary['gaps']);

        if ($largestGap <= self::COMPARISON_TOLERANCE) {
            return [
                'status' => 'complete',
                'message' => self::COMPLETE_MESSAGE,
            ];
        }

        assert($payload !== null);

        $results = $this->metricResults($payload);
        $version = $this->catalog->normalizeVersion($payload['metric_version'] ?? null);
        $tiedDimensions = array_values(array_filter(
            self::DIMENSIONS,
            fn (string $dimension): bool => abs($summary['gaps'][$dimension] - $largestGap)
                <= self::COMPARISON_TOLERANCE,
        ));

        $rankedByDimension = [];

        foreach ($tiedDimensions as $dimension) {
            $rankedByDimension[$dimension] = $version === null
                ? []
                : $this->rankedActions(
                    results: $results,
                    version: $version,
                    scope: $scope,
                    dimension: $dimension,
                    dimensionGap: $summary['gaps'][$dimension],
                    context: $context,
                );
        }

        $winner = $this->selectDimension($tiedDimensions, $rankedByDimension);
        $missingPoints = $summary['gaps'][$winner];
        $potentialFairGain = $missingPoints / $summary['fairTotal'] * 100;

        $available = [
            'status' => 'available',
            'dimension' => $winner,
            'dimensionLabel' => self::DIMENSION_LABELS[$winner],
            'missingPoints' => round($missingPoints, 2),
            'totalPoints' => round($summary['totals'][$winner], 2),
            'potentialFairGain' => round($potentialFairGain, 2),
            'severity' => $this->severity($potentialFairGain),
            'requiresReassessment' => false,
            'suggestions' => [],
        ];

        if ($context->requiresReassessment()) {
            $available['requiresReassessment'] = true;
            $available['guidanceMessage'] = self::REASSESSMENT_MESSAGE;

            return $available;
        }

        $ranked = $rankedByDimension[$winner] ?? [];
        $suggestions = array_map(
            fn (array $action): array => [
                'key' => $action['key'],
                'actor' => $action['actor'],
                'text' => $action['text'],
            ],
            array_slice($ranked, 0, 3),
        );
        $available['suggestions'] = $suggestions;

        $inapplicableIgsnGap = $scope === self::SCOPE_IGSN
            ? $this->inapplicableIgsnGap($results, $winner, $missingPoints)
            : 0.0;

        if ($suggestions === []) {
            $available['guidanceMessage'] = $inapplicableIgsnGap > self::COMPARISON_TOLERANCE
                ? self::IGSN_DATASET_ONLY_MESSAGE
                : self::NO_VERIFIED_ACTION_MESSAGE;
        } elseif ($inapplicableIgsnGap > self::COMPARISON_TOLERANCE) {
            $available['scopeNote'] = self::IGSN_SCOPE_NOTE;
        }

        return $available;
    }

    /**
     * @return array{
     *     status: 'unavailable',
     *     reason: 'invalid-payload'|'invalid-scope',
     *     message: string
     * }|null
     */
    private function invalidScopeResult(
        string $scope,
        FairImprovementContext $context,
    ): ?array {
        if (! in_array($scope, [self::SCOPE_RESOURCE, self::SCOPE_IGSN], true)) {
            return $this->unavailable(
                reason: 'invalid-scope',
                message: 'FAIR improvement guidance is unavailable for this assessment scope.',
            );
        }

        if (! $context->isValidForScope($scope)) {
            return $this->unavailable(
                reason: 'invalid-scope',
                message: $scope === self::SCOPE_IGSN && ! $context->hasIgsnMetadata
                    ? self::INVALID_IGSN_SCOPE_MESSAGE
                    : 'FAIR improvement guidance is unavailable because its ERNIE context is inconsistent.',
            );
        }

        return null;
    }

    /**
     * @param  'invalid-payload'|'invalid-scope'  $reason
     * @return array{
     *     status: 'unavailable',
     *     reason: 'invalid-payload'|'invalid-scope',
     *     message: string
     * }
     */
    private function unavailable(string $reason, string $message): array
    {
        return [
            'status' => 'unavailable',
            'reason' => $reason,
            'message' => $message,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array{
     *     totals: array{F: float, A: float, I: float, R: float},
     *     gaps: array{F: float, A: float, I: float, R: float},
     *     fairTotal: float
     * }|null
     */
    private function normalizeSummary(?array $payload): ?array
    {
        if ($payload === null || ! isset($payload['summary']) || ! is_array($payload['summary'])) {
            return null;
        }

        $scoreTotals = $payload['summary']['score_total'] ?? null;
        $scoreEarned = $payload['summary']['score_earned'] ?? null;

        if (! is_array($scoreTotals) || ! is_array($scoreEarned)) {
            return null;
        }

        $totals = [];
        $gaps = [];

        foreach ([...self::DIMENSIONS, 'FAIR'] as $dimension) {
            if (
                ! array_key_exists($dimension, $scoreTotals)
                || ! array_key_exists($dimension, $scoreEarned)
            ) {
                return null;
            }

            $total = $this->finiteNumber($scoreTotals[$dimension]);
            $earned = $this->finiteNumber($scoreEarned[$dimension]);

            if ($total === null || $earned === null || $total <= 0) {
                return null;
            }

            $clampedEarned = max(0.0, min($total, $earned));

            if ($dimension !== 'FAIR') {
                $totals[$dimension] = $total;
                $gaps[$dimension] = max(0.0, $total - $clampedEarned);
            }
        }

        /** @var array{F: float, A: float, I: float, R: float} $totals */
        /** @var array{F: float, A: float, I: float, R: float} $gaps */
        return [
            'totals' => $totals,
            'gaps' => $gaps,
            'fairTotal' => (float) $scoreTotals['FAIR'],
        ];
    }

    private function finiteNumber(mixed $value): ?float
    {
        if (is_string($value)) {
            $value = trim($value);

            if ($value === '' || ! is_numeric($value)) {
                return null;
            }
        } elseif (! is_int($value) && ! is_float($value)) {
            return null;
        }

        $number = (float) $value;

        return is_finite($number) ? $number : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function metricResults(array $payload): array
    {
        $results = $payload['results'] ?? null;

        if (! is_array($results)) {
            return [];
        }

        return array_values(array_filter($results, static fn (mixed $result): bool => is_array($result)));
    }

    /**
     * @param  list<'F'|'A'|'I'|'R'>  $tiedDimensions
     * @param  array<'F'|'A'|'I'|'R', list<array{
     *     key: string,
     *     actor: 'curator'|'administrator',
     *     text: string,
     *     gain: float,
     *     priority: int
     * }>>  $rankedByDimension
     * @return 'F'|'A'|'I'|'R'
     */
    private function selectDimension(array $tiedDimensions, array $rankedByDimension): string
    {
        $winner = $tiedDimensions[0];
        $winnerGain = $rankedByDimension[$winner][0]['gain'] ?? 0.0;

        foreach (array_slice($tiedDimensions, 1) as $dimension) {
            $candidateGain = $rankedByDimension[$dimension][0]['gain'] ?? 0.0;

            if ($candidateGain > $winnerGain + self::COMPARISON_TOLERANCE) {
                $winner = $dimension;
                $winnerGain = $candidateGain;
            }
        }

        return $winner;
    }

    /**
     * @param  list<array<string, mixed>>  $results
     * @param  'F'|'A'|'I'|'R'  $dimension
     * @return list<array{
     *     key: string,
     *     actor: 'curator'|'administrator',
     *     text: string,
     *     gain: float,
     *     priority: int
     * }>
     */
    private function rankedActions(
        array $results,
        string $version,
        string $scope,
        string $dimension,
        float $dimensionGap,
        FairImprovementContext $context,
    ): array {
        /**
         * @var array<string, array{
         *     key: string,
         *     actor: 'curator'|'administrator',
         *     text: string,
         *     priority: int,
         *     sources: array<string, float>
         * }> $groups
         */
        $groups = [];

        foreach ($results as $metric) {
            $metricIdentifier = $metric['metric_identifier'] ?? null;

            if (
                ! is_string($metricIdentifier)
                || $this->metricDimension($metricIdentifier) !== $dimension
            ) {
                continue;
            }

            $metricGap = $this->scoreGap($metric['score'] ?? null);

            if ($metricGap === null || $metricGap <= self::COMPARISON_TOLERANCE) {
                continue;
            }

            $testMap = $this->testMap($metric);
            $rules = $this->catalog->rulesFor($version, $scope, $metricIdentifier, $context);

            foreach ($rules as $rule) {
                $candidate = $this->candidateFromRule($rule, $metricGap, $testMap);

                if ($candidate === null) {
                    continue;
                }

                $key = $candidate['key'];

                if (! isset($groups[$key])) {
                    $groups[$key] = [
                        'key' => $key,
                        'actor' => $candidate['actor'],
                        'text' => $candidate['text'],
                        'priority' => $candidate['priority'],
                        'sources' => [],
                    ];
                } elseif (
                    $candidate['priority'] < $groups[$key]['priority']
                    || (
                        $candidate['priority'] === $groups[$key]['priority']
                        && strcmp($candidate['text'], $groups[$key]['text']) < 0
                    )
                ) {
                    $groups[$key]['actor'] = $candidate['actor'];
                    $groups[$key]['text'] = $candidate['text'];
                    $groups[$key]['priority'] = $candidate['priority'];
                }

                $sourceGain = $groups[$key]['sources'][$candidate['sourceKey']] ?? 0.0;
                $groups[$key]['sources'][$candidate['sourceKey']] = max($sourceGain, $candidate['gain']);
            }
        }

        $ranked = [];

        foreach ($groups as $group) {
            $ranked[] = [
                'key' => $group['key'],
                'actor' => $group['actor'],
                'text' => $group['text'],
                'gain' => min($dimensionGap, array_sum($group['sources'])),
                'priority' => $group['priority'],
            ];
        }

        usort($ranked, static function (array $left, array $right): int {
            $gainComparison = $right['gain'] <=> $left['gain'];

            if ($gainComparison !== 0) {
                return $gainComparison;
            }

            $priorityComparison = $left['priority'] <=> $right['priority'];

            return $priorityComparison !== 0
                ? $priorityComparison
                : strcmp($left['key'], $right['key']);
        });

        return $ranked;
    }

    /**
     * @param  array{
     *     actionKey: string,
     *     actor: 'curator'|'administrator',
     *     text: string,
     *     metricIdentifier: string,
     *     testIdentifiers: list<string>,
     *     aggregation: 'cumulative'|'alternative',
     *     requiresTestDetails: bool,
     *     priority: int
     * }  $rule
     * @param  array<string, array<string, mixed>>  $testMap
     * @return array{
     *     key: string,
     *     actor: 'curator'|'administrator',
     *     text: string,
     *     gain: float,
     *     priority: int,
     *     sourceKey: string
     * }|null
     */
    private function candidateFromRule(array $rule, float $metricGap, array $testMap): ?array
    {
        $testGaps = [];
        $matchedTestIdentifiers = [];
        $hasMatchingTest = false;
        $hasValidTestScore = false;

        foreach ($rule['testIdentifiers'] as $testIdentifier) {
            if (! isset($testMap[$testIdentifier])) {
                continue;
            }

            $hasMatchingTest = true;
            $gap = $this->testScoreGap($testMap[$testIdentifier]);

            if ($gap === null) {
                continue;
            }

            $hasValidTestScore = true;

            if ($gap <= self::COMPARISON_TOLERANCE) {
                continue;
            }

            $testGaps[] = $gap;
            $matchedTestIdentifiers[] = $testIdentifier;
        }

        if ($testGaps !== []) {
            $gain = $rule['aggregation'] === 'alternative'
                ? max($testGaps)
                : array_sum($testGaps);
            $sourceKey = $rule['metricIdentifier'].':'.implode(',', $matchedTestIdentifiers);
        } elseif (
            $rule['requiresTestDetails']
            || $hasMatchingTest
            || $hasValidTestScore
        ) {
            return null;
        } else {
            $gain = $metricGap;
            $sourceKey = $rule['metricIdentifier'].':metric';
        }

        $gain = min($metricGap, $gain);

        return [
            'key' => $rule['actionKey'],
            'actor' => $rule['actor'],
            'text' => $rule['text'],
            'gain' => $gain,
            'priority' => $rule['priority'],
            'sourceKey' => $sourceKey,
        ];
    }

    /**
     * @param  array<string, mixed>  $metric
     * @return array<string, array<string, mixed>>
     */
    private function testMap(array $metric): array
    {
        $tests = $metric['metric_tests'] ?? null;

        if (! is_array($tests)) {
            return [];
        }

        $map = [];

        foreach ($tests as $key => $test) {
            if (! is_array($test)) {
                continue;
            }

            $identifier = is_string($key) && $key !== ''
                ? $key
                : ($test['metric_test_identifier'] ?? null);

            if (is_string($identifier) && $identifier !== '') {
                $map[$identifier] = $test;
            }
        }

        return $map;
    }

    private function scoreGap(mixed $score): ?float
    {
        if (! is_array($score)) {
            return null;
        }

        return $this->earnedTotalGap(
            $score['earned'] ?? null,
            $score['total'] ?? null,
        );
    }

    /**
     * @param  array<string, mixed>  $test
     */
    private function testScoreGap(array $test): ?float
    {
        $score = $test['metric_test_score'] ?? null;

        if (! is_array($score) && array_key_exists('metric_test_score_max', $test)) {
            return $this->earnedTotalGap($score, $test['metric_test_score_max']);
        }

        return $this->scoreGap($score ?? $test['score'] ?? null);
    }

    private function earnedTotalGap(mixed $earnedValue, mixed $totalValue): ?float
    {
        $earned = $this->finiteNumber($earnedValue);
        $total = $this->finiteNumber($totalValue);

        if ($earned === null || $total === null || $total < 0) {
            return null;
        }

        if ($total <= self::COMPARISON_TOLERANCE) {
            return 0.0;
        }

        return max(0.0, $total - max(0.0, min($total, $earned)));
    }

    /**
     * @return 'F'|'A'|'I'|'R'|null
     */
    private function metricDimension(string $metricIdentifier): ?string
    {
        foreach (self::DIMENSIONS as $dimension) {
            if (str_starts_with($metricIdentifier, 'FsF-'.$dimension)) {
                return $dimension;
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $results
     * @param  'F'|'A'|'I'|'R'  $dimension
     */
    private function inapplicableIgsnGap(
        array $results,
        string $dimension,
        float $dimensionGap,
    ): float {
        $resultByMetric = [];

        foreach ($results as $result) {
            $metricIdentifier = $result['metric_identifier'] ?? null;

            if (is_string($metricIdentifier)) {
                $resultByMetric[$metricIdentifier] = $result;
            }
        }

        $gaps = [];

        foreach (FairImprovementTipCatalog::NON_APPLICABLE_IGSN_TESTS as $testIdentifier => $definition) {
            if ($definition['dimension'] !== $dimension) {
                continue;
            }

            $metric = $resultByMetric[$definition['metricIdentifier']] ?? null;

            if (! is_array($metric)) {
                continue;
            }

            $metricGap = $this->scoreGap($metric['score'] ?? null);

            if ($metricGap === null || $metricGap <= self::COMPARISON_TOLERANCE) {
                continue;
            }

            $test = $this->testMap($metric)[$testIdentifier] ?? null;
            $testGap = is_array($test) ? $this->testScoreGap($test) : null;

            if ($testGap !== null && $testGap > self::COMPARISON_TOLERANCE) {
                $gaps[$testIdentifier] = min($metricGap, $testGap);

                continue;
            }

            if (
                $test === null
                && in_array($definition['metricIdentifier'], ['FsF-F3-01M', 'FsF-R1.3-02D'], true)
            ) {
                $gaps[$testIdentifier] = min($metricGap, $definition['points']);
            }
        }

        return min($dimensionGap, array_sum($gaps));
    }

    /**
     * @return 'low'|'medium'|'high'|'very-high'
     */
    private function severity(float $potentialFairGain): string
    {
        if ($potentialFairGain >= 15) {
            return 'very-high';
        }

        if ($potentialFairGain >= 10) {
            return 'high';
        }

        if ($potentialFairGain >= 5) {
            return 'medium';
        }

        return 'low';
    }
}
