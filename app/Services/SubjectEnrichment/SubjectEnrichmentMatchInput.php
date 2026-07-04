<?php

declare(strict_types=1);

namespace App\Services\SubjectEnrichment;

/**
 * One subject row that can be checked for DataCite subject metadata enrichment.
 */
final readonly class SubjectEnrichmentMatchInput
{
    public function __construct(
        public int $resourceId,
        public string $targetType,
        public int $targetId,
        public string $value,
        public ?string $subjectScheme,
        public ?string $normalizedSubjectScheme,
        public ?string $schemeUri,
        public ?string $valueUri,
        public ?string $classificationCode,
        public ?string $breadcrumbPath,
        public string $language,
        public bool $isControlled,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function currentPayload(): array
    {
        return [
            'subject_id' => $this->targetId,
            'resource_id' => $this->resourceId,
            'value' => $this->value,
            'subject_scheme' => $this->subjectScheme,
            'normalized_subject_scheme' => $this->normalizedSubjectScheme,
            'scheme_uri' => $this->schemeUri,
            'value_uri' => $this->valueUri,
            'classification_code' => $this->classificationCode,
            'breadcrumb_path' => $this->breadcrumbPath,
            'language' => $this->language,
            'is_controlled' => $this->isControlled,
        ];
    }
}
