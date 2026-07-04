<?php

declare(strict_types=1);

namespace App\Services\SubjectEnrichment;

use App\Models\Subject;
use App\Support\PortalSubjectNormalizer;
use Closure;

/**
 * Coordinates subject metadata enrichment discovery for the generic assistant table.
 */
final readonly class SubjectEnrichmentDiscoveryService
{
    public const string ASSISTANT_ID = 'subject-metadata-enrichment';

    public const string TARGET_TYPE = 'subject';

    public function __construct(
        private SubjectEnrichmentMatchInputProvider $inputProvider,
        private SubjectEnrichmentMatcher $matcher,
    ) {}

    /**
     * @param  Closure(int, string, int, string, string, float|null, array<string, mixed>|null): bool  $storeSuggestion
     * @param  Closure(string): void  $onProgress
     */
    public function discover(Closure $storeSuggestion, Closure $onProgress): int
    {
        $inputs = $this->inputProvider->pendingInputs();
        $inputCount = $inputs->count();

        $onProgress("Checking {$inputCount} subject keyword(s) against local subject vocabulary caches.");

        if ($inputCount === 0) {
            $onProgress('No eligible subject keywords found.');

            return 0;
        }

        $stored = 0;
        $suppressed = 0;

        foreach ($inputs as $input) {
            $result = $this->matcher->match($input);

            if ($result->status !== 'matched' || $result->concept === null || $result->matchingStrategy === null) {
                $suppressed++;
                $onProgress(sprintf(
                    'Suppressed subject %d: %s.',
                    $input->targetId,
                    implode(', ', $result->suppressionReasons),
                ));

                continue;
            }

            if ($this->resourceAlreadyHasControlledConcept($input, $result->concept)) {
                $suppressed++;
                $onProgress(sprintf(
                    'Suppressed subject %d: resource already has this controlled subject concept.',
                    $input->targetId,
                ));

                continue;
            }

            $metadata = $this->metadata($input, $result);
            $proposed = $metadata['proposed'];
            $suggestedValue = $this->suggestedValue($proposed);

            if ($suggestedValue === null) {
                $suppressed++;
                $onProgress(sprintf('Suppressed subject %d: no stable suggested value.', $input->targetId));

                continue;
            }

            $wasStored = $storeSuggestion(
                $input->resourceId,
                self::TARGET_TYPE,
                $input->targetId,
                $suggestedValue,
                $this->suggestedLabel($result->concept),
                1.0,
                $metadata,
            );

            if ($wasStored) {
                $stored++;
            }
        }

        $onProgress("Stored {$stored} subject enrichment suggestion(s); suppressed {$suppressed} subject(s).");

        return $stored;
    }

    /**
     * @return array<string, mixed>
     */
    private function metadata(SubjectEnrichmentMatchInput $input, SubjectEnrichmentMatchResult $result): array
    {
        $concept = $result->concept;
        if ($concept === null || $result->matchingStrategy === null) {
            throw new \InvalidArgumentException('Matched subject enrichment metadata requires a concept and strategy.');
        }

        $proposed = $this->proposedPayload($input, $concept);

        return [
            'contract_version' => '1.0',
            'issue' => 813,
            'current' => $input->currentPayload(),
            'proposed' => $proposed,
            'vocabulary' => $this->vocabularyPayload($concept),
            'match' => [
                'strategy' => $result->matchingStrategy,
                'input' => $this->matchInput($input, $result->matchedFields),
                'normalized_input' => $this->normalizedMatchInput($input, $result->matchedFields),
                'matched_fields' => $result->matchedFields,
                'candidate_count' => $result->candidateCount,
                'suppression_reason' => null,
                'path_normalization_applied' => $result->pathNormalizationApplied,
            ],
            'provenance' => $concept->source->provenance(
                matchingStrategy: $result->matchingStrategy,
                pathNormalizationApplied: $result->pathNormalizationApplied,
            ),
            'confidence' => [
                'level' => 'high',
                'score' => 1.0,
                'evidence' => array_values(array_filter([
                    $input->isControlled ? 'supported_scheme' : 'globally_unique_free_keyword_match',
                    $result->matchingStrategy,
                    'single_candidate',
                    'source_cache_recorded',
                ])),
            ],
            'ambiguity' => [
                'status' => $result->warnings === [] ? 'none' : 'warning',
                'candidate_count' => $result->candidateCount,
                'candidate_ids' => $result->candidateIds,
                'notes' => [],
                'warnings' => $result->warnings,
                'warning_messages' => $result->warningMessages,
            ],
            'acceptance' => [
                'updates' => array_keys($proposed['updates']),
                'preconditions' => [
                    'target subject still exists',
                    'current subject fields still match metadata.current',
                    'proposed scheme remains in first-release scope',
                    'matching strategy still resolves exactly one candidate',
                ],
                'stale_if' => [
                    'subject value changed',
                    'subject scheme changed',
                    'source cache was refreshed and candidate no longer resolves uniquely',
                ],
                'implementation_issue' => 814,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function proposedPayload(SubjectEnrichmentMatchInput $input, SubjectVocabularyConcept $concept): array
    {
        $valueUri = $concept->valueUri();
        $updates = [];

        $this->addUpdate($updates, 'subject_scheme', $input->subjectScheme, $concept->scheme);
        $this->addUpdate($updates, 'scheme_uri', $input->schemeUri, $concept->schemeUri);
        $this->addUpdate($updates, 'value_uri', $input->valueUri, $valueUri);
        $this->addUpdate($updates, 'classification_code', $input->classificationCode, $concept->classificationCode);
        $this->addUpdate($updates, 'breadcrumb_path', $input->breadcrumbPath, $concept->path);
        $this->addUpdate($updates, 'language', $input->language, $concept->language);

        return [
            'subject_scheme' => $concept->scheme,
            'scheme_uri' => $concept->schemeUri,
            'value_uri' => $valueUri,
            'classification_code' => $concept->classificationCode,
            'breadcrumb_path' => $concept->path,
            'label' => $concept->label,
            'language' => $concept->language,
            'updates' => $updates,
            'preserve' => [
                'value',
                'resource_id',
            ],
            'concept' => $concept->toVocabularyPayload(),
        ];
    }

    /**
     * @param  array<string, mixed>  $updates
     */
    private function addUpdate(array &$updates, string $field, ?string $current, ?string $proposed): void
    {
        if ($proposed === null || $proposed === '') {
            return;
        }

        if ($current !== $proposed) {
            $updates[$field] = $proposed;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function vocabularyPayload(SubjectVocabularyConcept $concept): array
    {
        return array_filter([
            'scheme' => $concept->scheme,
            'scheme_uri' => $concept->schemeUri,
            'source' => $concept->source->source,
            'source_registry_url' => $concept->source->sourceRegistryUrl,
            'local_cache_file' => $concept->source->localCacheFile,
            'local_cache_updated_at' => $concept->source->localCacheUpdatedAt,
            'version' => $concept->source->version,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @param  list<string>  $matchedFields
     */
    private function matchInput(SubjectEnrichmentMatchInput $input, array $matchedFields): ?string
    {
        foreach ($matchedFields as $field) {
            $value = match ($field) {
                'value_uri' => $input->valueUri,
                'classification_code' => $input->classificationCode,
                'breadcrumb_path' => $input->breadcrumbPath,
                'value' => $input->value,
                default => null,
            };

            $filledValue = $this->filledString($value);
            if ($filledValue !== null) {
                return $filledValue;
            }
        }

        return $this->filledString($input->breadcrumbPath) ?? $this->filledString($input->value);
    }

    /**
     * @param  list<string>  $matchedFields
     */
    private function normalizedMatchInput(SubjectEnrichmentMatchInput $input, array $matchedFields): ?string
    {
        $matchInput = $this->matchInput($input, $matchedFields);
        if ($matchInput === null) {
            return null;
        }

        return mb_strtolower(PortalSubjectNormalizer::normalizeControlledSubjectValue($matchInput) ?? $matchInput);
    }

    /**
     * @param  array<string, mixed>  $proposed
     */
    private function suggestedValue(array $proposed): ?string
    {
        return $this->filledString($proposed['value_uri'] ?? null)
            ?? $this->filledString($proposed['classification_code'] ?? null)
            ?? $this->filledString($proposed['scheme_uri'] ?? null);
    }

    private function suggestedLabel(SubjectVocabularyConcept $concept): string
    {
        return sprintf('Complete subject metadata for "%s" from %s', $concept->label, $concept->scheme);
    }

    private function filledString(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function resourceAlreadyHasControlledConcept(
        SubjectEnrichmentMatchInput $input,
        SubjectVocabularyConcept $concept,
    ): bool {
        $valueUri = $concept->valueUri();
        $classificationCode = $concept->classificationCode;

        if ($valueUri === null && $classificationCode === null) {
            return false;
        }

        $otherSubjects = Subject::query()
            ->where('resource_id', $input->resourceId)
            ->whereKeyNot($input->targetId)
            ->get();

        foreach ($otherSubjects as $otherSubject) {
            $otherInput = $this->inputProvider->inputFor($otherSubject);
            if (! $otherInput instanceof SubjectEnrichmentMatchInput || ! $otherInput->isControlled) {
                continue;
            }

            if (! $this->sameConceptScheme($otherInput, $concept)) {
                continue;
            }

            if ($valueUri !== null && $otherInput->valueUri === $valueUri) {
                return true;
            }

            if ($classificationCode !== null && $otherInput->classificationCode === $classificationCode) {
                return true;
            }
        }

        return false;
    }

    private function sameConceptScheme(
        SubjectEnrichmentMatchInput $otherInput,
        SubjectVocabularyConcept $concept,
    ): bool {
        return $otherInput->subjectScheme === $concept->scheme
            || $otherInput->normalizedSubjectScheme === $concept->scheme
            || $otherInput->schemeUri === $concept->schemeUri;
    }
}
