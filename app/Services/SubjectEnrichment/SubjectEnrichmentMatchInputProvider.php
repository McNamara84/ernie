<?php

declare(strict_types=1);

namespace App\Services\SubjectEnrichment;

use App\Models\Subject;
use App\Support\PortalSubjectNormalizer;
use Illuminate\Support\Collection;

/**
 * Reads subject rows that are eligible for subject metadata enrichment discovery.
 */
final class SubjectEnrichmentMatchInputProvider
{
    private const string TARGET_TYPE = 'subject';

    /**
     * @return Collection<int, SubjectEnrichmentMatchInput>
     */
    public function pendingInputs(): Collection
    {
        $rows = Subject::query()
            ->whereNotNull('value')
            ->where('value', '!=', '')
            ->whereHas('resource')
            ->orderBy('id')
            ->get();

        return $rows
            ->map(fn (Subject $subject): ?SubjectEnrichmentMatchInput => $this->toInput($subject))
            ->filter()
            ->values();
    }

    private function toInput(Subject $subject): ?SubjectEnrichmentMatchInput
    {
        $value = $this->filledString($subject->value);
        if ($value === null) {
            return null;
        }

        $subjectScheme = $this->filledString($subject->subject_scheme);
        $normalizedSubjectScheme = PortalSubjectNormalizer::normalizeScheme($subjectScheme);
        $language = $this->filledString($subject->getAttribute('language')) ?? 'en';

        return new SubjectEnrichmentMatchInput(
            resourceId: $subject->resource_id,
            targetType: self::TARGET_TYPE,
            targetId: $subject->id,
            value: $value,
            subjectScheme: $subjectScheme,
            normalizedSubjectScheme: $normalizedSubjectScheme,
            schemeUri: $this->filledString($subject->scheme_uri),
            valueUri: $this->filledString($subject->value_uri),
            classificationCode: $this->filledString($subject->classification_code),
            breadcrumbPath: PortalSubjectNormalizer::normalizeControlledSubjectValue($subject->breadcrumb_path),
            language: $language,
            isControlled: $subject->isControlled(),
        );
    }

    private function filledString(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
