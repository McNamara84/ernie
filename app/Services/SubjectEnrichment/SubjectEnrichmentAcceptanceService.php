<?php

declare(strict_types=1);

namespace App\Services\SubjectEnrichment;

use App\Models\AssistantSuggestion;
use App\Models\Subject;
use Illuminate\Support\Facades\DB;

/**
 * Applies accepted subject metadata enrichment suggestions after strict revalidation.
 */
final readonly class SubjectEnrichmentAcceptanceService
{
    /** @var array<string, true> */
    private const ALLOWED_UPDATE_FIELDS = [
        'subject_scheme' => true,
        'scheme_uri' => true,
        'value_uri' => true,
        'classification_code' => true,
        'breadcrumb_path' => true,
        'language' => true,
    ];

    public function __construct(
        private SubjectEnrichmentMatchInputProvider $inputProvider,
        private SubjectEnrichmentMatcher $matcher,
        private SubjectVocabularyLookupService $lookup,
    ) {}

    /**
     * @return array{success: bool, message: string}
     */
    public function accept(AssistantSuggestion $suggestion): array
    {
        $validation = $this->validatedPayload($suggestion);

        if ($validation['success'] === false) {
            return [
                'success' => false,
                'message' => $validation['message'],
            ];
        }

        return DB::transaction(function () use ($suggestion, $validation): array {
            /** @var Subject|null $subject */
            $subject = Subject::query()
                ->whereKey($suggestion->target_id)
                ->where('resource_id', $suggestion->resource_id)
                ->lockForUpdate()
                ->first();

            if (! $subject instanceof Subject) {
                return $this->failure('Subject metadata suggestion is stale because the subject no longer exists.');
            }

            $input = $this->inputProvider->inputFor($subject);
            if (! $input instanceof SubjectEnrichmentMatchInput) {
                return $this->failure('Subject metadata suggestion is stale because the subject is no longer eligible for enrichment.');
            }

            /** @var array<string, mixed> $current */
            $current = $validation['current'];
            $staleMessage = $this->staleMessage($input, $current);
            if ($staleMessage !== null) {
                return $this->failure($staleMessage);
            }

            /** @var array<string, mixed> $proposed */
            $proposed = $validation['proposed'];
            /** @var array<string, string> $updates */
            $updates = $validation['updates'];
            /** @var string $scheme */
            $scheme = $validation['scheme'];
            /** @var string $strategy */
            $strategy = $validation['strategy'];

            $revalidationMessage = $this->revalidationFailureMessage($input, $proposed, $updates, $scheme, $strategy);
            if ($revalidationMessage !== null) {
                return $this->failure($revalidationMessage);
            }

            $duplicateMessage = $this->duplicateFailureMessage($subject, $proposed, $scheme);
            if ($duplicateMessage !== null) {
                return $this->failure($duplicateMessage);
            }

            $subject->forceFill($updates);

            if ($subject->isDirty()) {
                $subject->save();
            }

            AssistantSuggestion::query()
                ->where('assistant_id', SubjectEnrichmentDiscoveryService::ASSISTANT_ID)
                ->where('target_type', SubjectEnrichmentDiscoveryService::TARGET_TYPE)
                ->where('target_id', $subject->id)
                ->where('id', '!=', $suggestion->id)
                ->delete();

            return [
                'success' => true,
                'message' => 'Subject metadata enrichment applied.',
            ];
        });
    }

    /**
     * @return array{success: false, message: string}|array{success: true, current: array<string, mixed>, proposed: array<string, mixed>, updates: array<string, string>, scheme: string, strategy: string}
     */
    private function validatedPayload(AssistantSuggestion $suggestion): array
    {
        if ($suggestion->assistant_id !== SubjectEnrichmentDiscoveryService::ASSISTANT_ID) {
            return $this->invalid('This subject metadata suggestion belongs to a different assistant.');
        }

        if ($suggestion->target_type !== SubjectEnrichmentDiscoveryService::TARGET_TYPE) {
            return $this->invalid('This subject metadata suggestion targets an unsupported entity type.');
        }

        $metadata = is_array($suggestion->metadata) ? $suggestion->metadata : [];
        $current = is_array($metadata['current'] ?? null) ? $metadata['current'] : [];
        $proposed = is_array($metadata['proposed'] ?? null) ? $metadata['proposed'] : [];
        $confidence = is_array($metadata['confidence'] ?? null) ? $metadata['confidence'] : [];
        $match = is_array($metadata['match'] ?? null) ? $metadata['match'] : [];
        $ambiguity = is_array($metadata['ambiguity'] ?? null) ? $metadata['ambiguity'] : [];
        $acceptance = is_array($metadata['acceptance'] ?? null) ? $metadata['acceptance'] : [];
        $updates = is_array($proposed['updates'] ?? null) ? $proposed['updates'] : [];

        if ((int) ($current['subject_id'] ?? 0) !== $suggestion->target_id) {
            return $this->invalid('This subject metadata suggestion does not match its target subject.');
        }

        if ((int) ($current['resource_id'] ?? 0) !== $suggestion->resource_id) {
            return $this->invalid('This subject metadata suggestion does not match its target resource.');
        }

        if ($this->filledString($confidence['level'] ?? null) !== 'high') {
            return $this->invalid('Only high-confidence subject metadata suggestions can be accepted.');
        }

        if ((int) ($match['candidate_count'] ?? 0) !== 1) {
            return $this->invalid('Only uniquely resolved subject metadata suggestions can be accepted.');
        }

        if ($this->filledString($ambiguity['status'] ?? null) === 'suppressed') {
            return $this->invalid('Suppressed subject metadata suggestions cannot be accepted.');
        }

        $scheme = $this->lookup->normalizeSupportedScheme($this->filledString($proposed['subject_scheme'] ?? null));
        if ($scheme === null) {
            return $this->invalid('This subject metadata suggestion proposes an unsupported subject scheme.');
        }

        if ($this->filledString($proposed['subject_scheme'] ?? null) !== $scheme) {
            return $this->invalid('This subject metadata suggestion does not propose the canonical subject scheme.');
        }

        $canonicalSchemeUri = $this->lookup->canonicalSchemeUri($scheme);
        if ($canonicalSchemeUri === null || $this->filledString($proposed['scheme_uri'] ?? null) !== $canonicalSchemeUri) {
            return $this->invalid('This subject metadata suggestion does not match the canonical subject scheme URI.');
        }

        $strategy = $this->filledString($match['strategy'] ?? null);
        if ($strategy === null) {
            return $this->invalid('This subject metadata suggestion is missing its matching strategy.');
        }

        $normalizedUpdates = $this->normalizedUpdates($updates);
        if ($normalizedUpdates === []) {
            return $this->invalid('This subject metadata suggestion does not contain any allowed subject field updates.');
        }

        $acceptanceUpdates = $this->stringList($acceptance['updates'] ?? null);
        if ($acceptanceUpdates === [] || $this->sorted($acceptanceUpdates) !== $this->sorted(array_keys($normalizedUpdates))) {
            return $this->invalid('This subject metadata suggestion has inconsistent acceptance update metadata.');
        }

        $suggestedValue = $this->suggestedValue($proposed);
        if ($suggestedValue === null || $suggestedValue !== $suggestion->suggested_value) {
            return $this->invalid('The suggestion value and proposed subject metadata do not match.');
        }

        return [
            'success' => true,
            'current' => $current,
            'proposed' => $proposed,
            'updates' => $normalizedUpdates,
            'scheme' => $scheme,
            'strategy' => $strategy,
        ];
    }

    /**
     * @param  array<string, mixed>  $updates
     * @return array<string, string>
     */
    private function normalizedUpdates(array $updates): array
    {
        $normalized = [];

        foreach ($updates as $field => $value) {
            if (! isset(self::ALLOWED_UPDATE_FIELDS[$field])) {
                return [];
            }

            $filledValue = $this->filledString($value);
            if ($filledValue === null) {
                return [];
            }

            $normalized[$field] = $filledValue;
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $current
     */
    private function staleMessage(SubjectEnrichmentMatchInput $input, array $current): ?string
    {
        $actual = $input->currentPayload();

        if ((int) ($current['resource_id'] ?? 0) !== $input->resourceId) {
            return 'Subject metadata suggestion is stale because the subject resource changed.';
        }

        if (! $this->sameOptionalString($current['value'] ?? null, $actual['value'] ?? null)) {
            return 'Subject metadata suggestion is stale because the subject value changed.';
        }

        if (! $this->sameOptionalString($current['subject_scheme'] ?? null, $actual['subject_scheme'] ?? null)) {
            return 'Subject metadata suggestion is stale because the subject scheme changed.';
        }

        foreach ([
            'scheme_uri',
            'value_uri',
            'classification_code',
            'breadcrumb_path',
            'language',
            'normalized_subject_scheme',
        ] as $field) {
            if (! $this->sameOptionalString($current[$field] ?? null, $actual[$field] ?? null)) {
                return 'Subject metadata suggestion is stale because the current subject metadata changed.';
            }
        }

        if (array_key_exists('is_controlled', $current) && (bool) $current['is_controlled'] !== $input->isControlled) {
            return 'Subject metadata suggestion is stale because the current subject metadata changed.';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $proposed
     * @param  array<string, string>  $updates
     */
    private function revalidationFailureMessage(
        SubjectEnrichmentMatchInput $input,
        array $proposed,
        array $updates,
        string $scheme,
        string $strategy,
    ): ?string {
        if (! $this->lookup->isSchemeAvailable($scheme)) {
            return 'Subject metadata suggestion is stale because the local vocabulary cache is unavailable.';
        }

        $result = $this->matcher->match($input);
        if ($result->status !== 'matched' || $result->concept === null || $result->candidateCount !== 1) {
            return 'Subject metadata suggestion is stale because the matching strategy no longer resolves exactly one candidate.';
        }

        if ($result->matchingStrategy !== $strategy) {
            return 'Subject metadata suggestion is stale because the matching strategy changed.';
        }

        $concept = $result->concept;
        $expected = [
            'subject_scheme' => $concept->scheme,
            'scheme_uri' => $concept->schemeUri,
            'value_uri' => $concept->valueUri(),
            'classification_code' => $concept->classificationCode,
            'breadcrumb_path' => $concept->path,
            'language' => $concept->language,
        ];

        foreach ($expected as $field => $expectedValue) {
            if ($this->filledString($proposed[$field] ?? null) !== $this->filledString($expectedValue)) {
                return 'Subject metadata suggestion is stale because the proposed subject metadata no longer matches the vocabulary.';
            }
        }

        foreach ($updates as $field => $value) {
            if (! array_key_exists($field, $expected) || $this->filledString($expected[$field]) !== $value) {
                return 'Subject metadata suggestion is stale because the proposed updates no longer match the vocabulary.';
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $proposed
     */
    private function duplicateFailureMessage(Subject $subject, array $proposed, string $scheme): ?string
    {
        $proposedSchemeUri = $this->filledString($proposed['scheme_uri'] ?? null);
        $proposedValueUri = $this->filledString($proposed['value_uri'] ?? null);
        $proposedClassificationCode = $this->filledString($proposed['classification_code'] ?? null);

        if ($proposedValueUri === null && $proposedClassificationCode === null) {
            return null;
        }

        $otherSubjects = Subject::query()
            ->where('resource_id', $subject->resource_id)
            ->whereKeyNot($subject->id)
            ->lockForUpdate()
            ->get();

        foreach ($otherSubjects as $otherSubject) {
            $otherInput = $this->inputProvider->inputFor($otherSubject);
            if (! $otherInput instanceof SubjectEnrichmentMatchInput || ! $otherInput->isControlled) {
                continue;
            }

            $otherScheme = $this->lookup->normalizeSupportedScheme($otherInput->subjectScheme);
            $sameScheme = $otherScheme === $scheme || (
                $proposedSchemeUri !== null && $otherInput->schemeUri === $proposedSchemeUri
            );

            if (! $sameScheme) {
                continue;
            }

            if ($proposedValueUri !== null && $otherInput->valueUri === $proposedValueUri) {
                return 'Subject metadata suggestion was not applied because the resource already has this controlled subject concept.';
            }

            if ($proposedClassificationCode !== null && $otherInput->classificationCode === $proposedClassificationCode) {
                return 'Subject metadata suggestion was not applied because the resource already has this controlled subject concept.';
            }
        }

        return null;
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

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $strings = [];
        foreach ($value as $item) {
            $string = $this->filledString($item);
            if ($string !== null) {
                $strings[] = $string;
            }
        }

        return array_values(array_unique($strings));
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function sorted(array $values): array
    {
        sort($values);

        return $values;
    }

    private function sameOptionalString(mixed $expected, mixed $actual): bool
    {
        return $this->filledString($expected) === $this->filledString($actual);
    }

    private function filledString(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @return array{success: false, message: string}
     */
    private function invalid(string $message): array
    {
        return [
            'success' => false,
            'message' => $message,
        ];
    }

    /**
     * @return array{success: false, message: string}
     */
    private function failure(string $message): array
    {
        return [
            'success' => false,
            'message' => $message,
        ];
    }
}
