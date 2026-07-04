<?php

declare(strict_types=1);

namespace App\Services\SubjectEnrichment;

use App\Support\PortalSubjectNormalizer;
use App\Support\SubjectBreadcrumbPath;

/**
 * Matches subject rows against first-release vocabularies and suppresses unsafe candidates.
 */
final readonly class SubjectEnrichmentMatcher
{
    public const string FREE_KEYWORD_TRANSFER_WARNING = 'free_keyword_can_be_transferred_to_thesaurus_keyword';

    public const string FREE_KEYWORD_TRANSFER_MESSAGE = 'This Free Keyword could be transferred into a Thesaurus Keyword if you accept this suggestion.';

    public function __construct(
        private SubjectVocabularyLookupService $lookup,
    ) {}

    public function match(SubjectEnrichmentMatchInput $input): SubjectEnrichmentMatchResult
    {
        return $input->isControlled
            ? $this->matchControlled($input)
            : $this->matchFreeText($input);
    }

    private function matchControlled(SubjectEnrichmentMatchInput $input): SubjectEnrichmentMatchResult
    {
        $scheme = $this->lookup->normalizeSupportedScheme($input->subjectScheme);
        if ($scheme === null) {
            return SubjectEnrichmentMatchResult::suppressed(['unsupported_scheme']);
        }

        if (! $this->lookup->isSchemeAvailable($scheme)) {
            return SubjectEnrichmentMatchResult::suppressed(['missing_local_vocabulary_cache']);
        }

        $strategies = [
            ['exact_value_uri', ['value_uri'], $this->lookup->findById($scheme, $input->valueUri)],
            ['exact_notation', ['classification_code'], $this->lookup->findByNotation($scheme, $input->classificationCode)],
            ['exact_breadcrumb_path', ['breadcrumb_path'], $this->lookup->findExactPath($scheme, $input->breadcrumbPath)],
            ['exact_breadcrumb_path', ['value'], $this->lookup->findExactPath($scheme, $input->value)],
            ['exact_legacy_breadcrumb_path', ['breadcrumb_path', 'value'], $this->lookup->findUniqueLegacyPath(
                $scheme,
                $input->breadcrumbPath ?? $input->value,
            )],
            ['unique_leaf_label', ['value'], $this->lookup->findUniqueLeafInScheme($scheme, $input->value)],
        ];

        foreach ($strategies as [$strategy, $matchedFields, $matchSet]) {
            if ($matchSet->isEmpty()) {
                continue;
            }

            if (! $matchSet->isUnique()) {
                return SubjectEnrichmentMatchResult::suppressed(
                    reasons: ['multiple_candidate_matches'],
                    candidateCount: $matchSet->count(),
                    candidateIds: $matchSet->candidateIds(),
                    pathNormalizationApplied: $matchSet->pathNormalizationApplied,
                );
            }

            $concept = $matchSet->sole();
            if ($concept === null) {
                continue;
            }

            if (! $this->hasUsefulControlledUpdate($input, $concept)) {
                return SubjectEnrichmentMatchResult::suppressed(
                    reasons: ['complete_controlled_subject_metadata'],
                    candidateCount: 1,
                    candidateIds: [$concept->id],
                    pathNormalizationApplied: $matchSet->pathNormalizationApplied,
                );
            }

            return SubjectEnrichmentMatchResult::matched(
                concept: $concept,
                matchingStrategy: $strategy,
                matchedFields: $matchedFields,
                pathNormalizationApplied: $matchSet->pathNormalizationApplied,
            );
        }

        return SubjectEnrichmentMatchResult::suppressed(['no_candidate_match']);
    }

    private function matchFreeText(SubjectEnrichmentMatchInput $input): SubjectEnrichmentMatchResult
    {
        $value = PortalSubjectNormalizer::normalizeControlledSubjectValue($input->value);
        if ($value === null) {
            return SubjectEnrichmentMatchResult::suppressed(['empty_subject_value']);
        }

        if ($this->looksLikeUri($value)) {
            $uriMatch = $this->lookup->findGlobalById($value);
            $result = $this->resultForFreeMatchSet($uriMatch, 'stable_concept_uri', ['value']);
            if ($result !== null) {
                return $result;
            }
        }

        if (SubjectBreadcrumbPath::hasHierarchy($value)) {
            $schemePrefixedResult = $this->matchSchemePrefixedFreePath($value);
            if ($schemePrefixedResult !== null) {
                return $schemePrefixedResult;
            }

            $pathMatch = $this->lookup->findGlobalExactPath($value);
            $result = $this->resultForFreeMatchSet($pathMatch, 'global_exact_path', ['value'], includeTransferWarning: true);
            if ($result !== null) {
                return $result;
            }
        }

        $labelMatch = $this->lookup->findGlobalExactLabel($value);
        $result = $this->resultForFreeMatchSet($labelMatch, 'global_exact_label', ['value'], includeTransferWarning: true);
        if ($result !== null) {
            return $result;
        }

        return SubjectEnrichmentMatchResult::suppressed(['no_candidate_match']);
    }

    private function matchSchemePrefixedFreePath(string $value): ?SubjectEnrichmentMatchResult
    {
        $segments = SubjectBreadcrumbPath::segments($value);
        if ($segments === []) {
            return null;
        }

        $scheme = $this->lookup->normalizeSupportedScheme($segments[0]);
        if ($scheme === null) {
            return null;
        }

        if (! $this->lookup->isSchemeAvailable($scheme)) {
            return SubjectEnrichmentMatchResult::suppressed(['missing_local_vocabulary_cache']);
        }

        $exactMatch = $this->lookup->findExactPath($scheme, $value);
        $result = $this->resultForFreeMatchSet($exactMatch, 'recognized_scheme_prefixed_path', ['value']);
        if ($result !== null) {
            return $result;
        }

        $legacyMatch = $this->lookup->findUniqueLegacyPath($scheme, $value);

        return $this->resultForFreeMatchSet($legacyMatch, 'recognized_scheme_prefixed_legacy_path', ['value']);
    }

    /**
     * @param  list<string>  $matchedFields
     */
    private function resultForFreeMatchSet(
        SubjectVocabularyMatchSet $matchSet,
        string $strategy,
        array $matchedFields,
        bool $includeTransferWarning = false,
    ): ?SubjectEnrichmentMatchResult {
        if ($matchSet->isEmpty()) {
            return null;
        }

        if (! $matchSet->isUnique()) {
            return SubjectEnrichmentMatchResult::suppressed(
                reasons: ['free_text_label_not_globally_unique'],
                candidateCount: $matchSet->count(),
                candidateIds: $matchSet->candidateIds(),
                pathNormalizationApplied: $matchSet->pathNormalizationApplied,
            );
        }

        $concept = $matchSet->sole();
        if ($concept === null) {
            return null;
        }

        return SubjectEnrichmentMatchResult::matched(
            concept: $concept,
            matchingStrategy: $strategy,
            matchedFields: $matchedFields,
            warnings: $includeTransferWarning ? [self::FREE_KEYWORD_TRANSFER_WARNING] : [],
            warningMessages: $includeTransferWarning ? [
                self::FREE_KEYWORD_TRANSFER_WARNING => self::FREE_KEYWORD_TRANSFER_MESSAGE,
            ] : [],
            pathNormalizationApplied: $matchSet->pathNormalizationApplied,
        );
    }

    private function hasUsefulControlledUpdate(SubjectEnrichmentMatchInput $input, SubjectVocabularyConcept $concept): bool
    {
        if ($input->subjectScheme !== $concept->scheme) {
            return true;
        }

        if ($input->schemeUri !== $concept->schemeUri) {
            return true;
        }

        $valueUri = $concept->valueUri();
        if ($valueUri !== null && $input->valueUri !== $valueUri) {
            return true;
        }

        if ($concept->classificationCode !== null && $input->classificationCode !== $concept->classificationCode) {
            return true;
        }

        if ($input->breadcrumbPath !== $concept->path) {
            return true;
        }

        return $input->language !== $concept->language;
    }

    private function looksLikeUri(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_URL) !== false;
    }
}
