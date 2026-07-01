<?php

declare(strict_types=1);

namespace App\Services\CrossrefFunderRor;

use Closure;
use Illuminate\Support\Arr;

/**
 * Coordinates Crossref Funder ID to ROR discovery for funding references.
 */
final readonly class CrossrefFunderRorDiscoveryService
{
    public const string ASSISTANT_ID = 'crossref-funder-ror-suggestion';

    public const string TARGET_TYPE = 'funding_reference';

    private CrossrefFunderRorMappingSource $mappingSource;

    public function __construct(
        private CrossrefFunderRorMatchInputProvider $inputProvider,
        ?CrossrefFunderRorMappingSource $mappingSource = null,
        private CrossrefFunderRorIdentifierNormalizer $normalizer = new CrossrefFunderRorIdentifierNormalizer,
    ) {
        $this->mappingSource = $mappingSource ?? app(CrossrefFunderRorFundrefIndexMappingSource::class);
    }

    /**
     * @param  Closure(int, string, int, string, string, float|null, array<string, mixed>|null): bool  $storeSuggestion
     * @param  Closure(string): void  $onProgress
     */
    public function discover(Closure $storeSuggestion, Closure $onProgress): int
    {
        $inputs = $this->inputProvider->pendingInputs();
        $inputCount = $inputs->count();

        $onProgress("Checking {$inputCount} Crossref Funder ID funding reference(s) against the local ROR FundRef index.");

        if ($inputCount === 0) {
            $onProgress('No eligible Crossref Funder ID funding references found.');

            return 0;
        }

        $stored = 0;
        $suppressed = 0;

        foreach ($inputs as $input) {
            $result = $this->match($input);

            if ($result['status'] !== 'matched') {
                $suppressed++;
                $onProgress(sprintf(
                    'Suppressed funding_reference %d: %s.',
                    $input->targetId,
                    implode(', ', $result['reasons']),
                ));

                continue;
            }

            /** @var array<string, mixed> $metadata */
            $metadata = $result['metadata'];
            $rorId = (string) $metadata['proposed']['funder_identifier'];
            $label = sprintf('%s -> %s', (string) $metadata['proposed']['ror_display_name'], $rorId);

            $wasStored = $storeSuggestion(
                $input->resourceId,
                self::TARGET_TYPE,
                $input->targetId,
                $rorId,
                $label,
                1.0,
                $metadata,
            );

            if ($wasStored) {
                $stored++;
            }
        }

        $onProgress("Stored {$stored} Crossref-to-ROR suggestion(s); suppressed {$suppressed} mapping(s).");

        return $stored;
    }

    /**
     * @return array{status: 'matched', metadata: array<string, mixed>}|array{status: 'suppressed', reasons: list<string>}
     */
    private function match(CrossrefFunderRorMatchInput $input): array
    {
        $candidates = $this->candidatesFor($input->normalizedCrossrefFunderId);

        if ($candidates === []) {
            return [
                'status' => 'suppressed',
                'reasons' => ['no_exact_ror_fundref_match'],
            ];
        }

        $eligible = [];
        $suppressionReasons = [];

        foreach ($candidates as $candidate) {
            $evaluation = $this->evaluateCandidate($candidate, $input);

            if ($evaluation['eligible']) {
                $eligible[] = $evaluation['candidate'];

                continue;
            }

            foreach ($evaluation['reasons'] as $reason) {
                $suppressionReasons[$reason] = true;
            }
        }

        if (count($eligible) > 1) {
            return [
                'status' => 'suppressed',
                'reasons' => ['multiple_active_ror_matches'],
            ];
        }

        if ($eligible === []) {
            $reasons = array_keys($suppressionReasons);

            return [
                'status' => 'suppressed',
                'reasons' => $reasons !== [] ? $reasons : ['no_exact_ror_fundref_match'],
            ];
        }

        return [
            'status' => 'matched',
            'metadata' => $this->metadata($input, $eligible[0]),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function candidatesFor(string $normalizedFundrefId): array
    {
        return $this->mappingSource->candidatesForCrossrefFunderId($normalizedFundrefId);
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return array{eligible: true, candidate: array<string, mixed>, reasons: list<string>}|array{eligible: false, reasons: list<string>}
     */
    private function evaluateCandidate(array $candidate, CrossrefFunderRorMatchInput $input): array
    {
        $reasons = [];
        $matchedExternalId = $this->matchedExternalId($candidate, $input->normalizedCrossrefFunderId);

        if ($matchedExternalId === null) {
            $reasons[] = 'no_exact_ror_fundref_match';
        }

        $rorId = $this->normalizer->canonicalRorIdentifier($candidate['ror_id'] ?? $candidate['id'] ?? null);

        if ($rorId === null) {
            $reasons[] = 'ror_candidate_missing_valid_id';
        }

        $status = $this->stringValue($candidate['ror_status'] ?? $candidate['status'] ?? null) ?? '';

        if ($status !== 'active') {
            $reasons[] = 'only_inactive_or_withdrawn_ror_matches';
        }

        $types = $this->stringList($candidate['ror_types'] ?? $candidate['types'] ?? []);

        if (! in_array('funder', $types, true)) {
            $reasons[] = 'ror_candidate_not_funder_type';
        }

        $source = is_array($candidate['source'] ?? null) ? $candidate['source'] : [];

        if ($source === [] || $this->stringValue($source['source_retrieved_at'] ?? null) === null) {
            $reasons[] = 'ror_candidate_missing_provenance';
        }

        if ($reasons !== []) {
            return [
                'eligible' => false,
                'reasons' => array_values(array_unique($reasons)),
            ];
        }

        $candidate['ror_id'] = $rorId;
        $candidate['ror_status'] = $status;
        $candidate['ror_types'] = $types;
        $candidate['ror_display_name'] = $this->displayName($candidate);
        $candidate['matched_external_id'] = $matchedExternalId;
        $candidate['source'] = $source;

        return [
            'eligible' => true,
            'candidate' => $candidate,
            'reasons' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return array{type: string, value: string, matched_in: string, preferred: string|null}|null
     */
    private function matchedExternalId(array $candidate, string $fundrefId): ?array
    {
        $fundref = Arr::get($candidate, 'external_ids.fundref');

        if (! is_array($fundref)) {
            return null;
        }

        $all = $fundref['all'] ?? [];

        if (! is_array($all)) {
            return null;
        }

        $values = array_values(array_filter(
            array_map(fn (mixed $value): ?string => $this->stringValue($value), $all),
            fn (?string $value): bool => $value !== null,
        ));

        if (! in_array($fundrefId, $values, true)) {
            return null;
        }

        return [
            'type' => 'fundref',
            'value' => $fundrefId,
            'matched_in' => 'external_ids[type=fundref].all',
            'preferred' => $this->stringValue($fundref['preferred'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>
     */
    private function metadata(CrossrefFunderRorMatchInput $input, array $candidate): array
    {
        $warnings = $this->warnings($input, $candidate);

        return [
            'contract_version' => '1.0',
            'issue' => 784,
            'current' => $input->currentPayload(),
            'proposed' => [
                'funder_identifier' => $candidate['ror_id'],
                'funder_identifier_type' => 'ROR',
                'scheme_uri' => CrossrefFunderRorIdentifierNormalizer::ROR_SCHEME_URI,
                'ror_id' => $candidate['ror_id'],
                'ror_display_name' => $candidate['ror_display_name'],
                'ror_status' => $candidate['ror_status'],
                'ror_types' => $candidate['ror_types'],
                'ror_record_last_modified' => $this->stringValue($candidate['ror_record_last_modified'] ?? null),
                'matched_external_id' => $candidate['matched_external_id'],
            ],
            'provenance' => $candidate['source'],
            'confidence' => [
                'level' => 'high',
                'score' => 1.0,
                'evidence' => [
                    'exact_fundref_external_id_match',
                    'single_active_ror_candidate',
                    'candidate_has_valid_ror_id',
                    'source_snapshot_recorded',
                ],
            ],
            'ambiguity' => [
                'status' => $warnings === [] ? 'none' : 'warning',
                'candidate_count' => 1,
                'notes' => [],
                'warnings' => $warnings,
            ],
            'acceptance' => [
                'updates' => [
                    'funder_identifier' => $candidate['ror_id'],
                    'funder_identifier_type' => 'ROR',
                    'scheme_uri' => CrossrefFunderRorIdentifierNormalizer::ROR_SCHEME_URI,
                ],
                'preserve' => [
                    'funder_name',
                    'award_number',
                    'award_uri',
                    'award_title',
                ],
                'preconditions' => [
                    'target funding reference still exists',
                    'target still has Crossref Funder ID type',
                    'target normalized Crossref Funder ID still matches current.normalized_crossref_funder_id',
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return list<string>
     */
    private function warnings(CrossrefFunderRorMatchInput $input, array $candidate): array
    {
        $warnings = [];
        $localName = mb_strtolower(trim($input->funderName));
        $rorNames = array_map('mb_strtolower', $this->candidateNames($candidate));

        if ($localName !== '' && ! in_array($localName, $rorNames, true)) {
            $warnings[] = 'local_name_not_found_in_ror_names';
        }

        if ($localName !== '' && $localName !== mb_strtolower((string) $candidate['ror_display_name'])) {
            $warnings[] = 'ror_display_name_differs_from_local_name';
        }

        return array_values(array_unique($warnings));
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return list<string>
     */
    private function candidateNames(array $candidate): array
    {
        $names = [$this->displayName($candidate)];

        foreach (['names', 'aliases', 'labels'] as $key) {
            if (! is_array($candidate[$key] ?? null)) {
                continue;
            }

            foreach ($candidate[$key] as $entry) {
                if (is_string($entry) && trim($entry) !== '') {
                    $names[] = trim($entry);

                    continue;
                }

                if (is_array($entry)) {
                    $value = $this->stringValue($entry['value'] ?? $entry['label'] ?? null);

                    if ($value !== null) {
                        $names[] = $value;
                    }
                }
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @param  array<string, mixed>  $candidate
     */
    private function displayName(array $candidate): string
    {
        return $this->stringValue($candidate['ror_display_name'] ?? $candidate['display_name'] ?? $candidate['name'] ?? null)
            ?? 'Unknown ROR organization';
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(fn (mixed $item): ?string => $this->stringValue($item), $value),
            fn (?string $item): bool => $item !== null,
        ));
    }

    private function stringValue(mixed $value): ?string
    {
        if ($value === null || is_array($value) || is_object($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
