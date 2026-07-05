<?php

declare(strict_types=1);

namespace App\Services\DescriptionSegmentation;

final class DescriptionSegmentationPolicy
{
    public const string POLICY_VERSION = 'issue-815-v1';

    public const string SOURCE_TYPE = 'Abstract';

    public const string TARGET_METHODS = 'Methods';

    public const string TARGET_TECHNICAL_INFO = 'TechnicalInfo';

    public const string TARGET_TABLE_OF_CONTENTS = 'TableOfContents';

    public const string TARGET_SERIES_INFORMATION = 'SeriesInformation';

    public const string EXCLUDED_OTHER = 'Other';

    public const string CONFIDENCE_MEDIUM = 'medium';

    public const string CONFIDENCE_LOW = 'low';

    public const string EVIDENCE_HEADING = 'heading';

    public const string EVIDENCE_LABELLED_SECTION = 'labelled_section';

    public const string EVIDENCE_LIST_STRUCTURE = 'list_structure';

    public const string EVIDENCE_PARAGRAPH_BOUNDARY = 'paragraph_boundary';

    public const string EVIDENCE_FILE_INVENTORY = 'file_inventory';

    public const string EVIDENCE_VERSION_BLOCK = 'version_block';

    public const string EVIDENCE_KEYWORD_MATCH = 'keyword_match';

    public const int MINIMUM_SOURCE_LENGTH = 600;

    public const int MINIMUM_SEGMENT_LENGTH = 80;

    public const int MINIMUM_REMAINING_ABSTRACT_LENGTH = 120;

    /** @var array<string, string> */
    private const array TYPE_ALIASES = [
        'abstract' => self::SOURCE_TYPE,
        'methods' => self::TARGET_METHODS,
        'method' => self::TARGET_METHODS,
        'seriesinformation' => self::TARGET_SERIES_INFORMATION,
        'tableofcontents' => self::TARGET_TABLE_OF_CONTENTS,
        'technicalinfo' => self::TARGET_TECHNICAL_INFO,
        'technicalinformation' => self::TARGET_TECHNICAL_INFO,
        'other' => self::EXCLUDED_OTHER,
    ];

    /** @var list<string> */
    private const array SUPPORTED_TARGET_SLUGS = [
        self::TARGET_METHODS,
        self::TARGET_TECHNICAL_INFO,
        self::TARGET_TABLE_OF_CONTENTS,
        self::TARGET_SERIES_INFORMATION,
    ];

    /** @var list<string> */
    private const array LOW_CONFIDENCE_TARGET_SLUGS = [
        self::TARGET_SERIES_INFORMATION,
    ];

    /** @var list<string> */
    private const array EXCLUDED_TARGET_SLUGS = [
        self::SOURCE_TYPE,
        self::EXCLUDED_OTHER,
    ];

    /** @var list<string> */
    private const array STRUCTURAL_EVIDENCE_TYPES = [
        self::EVIDENCE_HEADING,
        self::EVIDENCE_LABELLED_SECTION,
        self::EVIDENCE_LIST_STRUCTURE,
        self::EVIDENCE_PARAGRAPH_BOUNDARY,
        self::EVIDENCE_FILE_INVENTORY,
        self::EVIDENCE_VERSION_BLOCK,
    ];

    /** @var list<string> */
    private const array NON_STRUCTURAL_EVIDENCE_TYPES = [
        self::EVIDENCE_KEYWORD_MATCH,
    ];

    public function policyVersion(): string
    {
        return self::POLICY_VERSION;
    }

    /** @return list<string> */
    public function supportedTargetSlugs(): array
    {
        return self::SUPPORTED_TARGET_SLUGS;
    }

    /** @return list<string> */
    public function lowConfidenceTargetSlugs(): array
    {
        return self::LOW_CONFIDENCE_TARGET_SLUGS;
    }

    /** @return list<string> */
    public function excludedTargetSlugs(): array
    {
        return self::EXCLUDED_TARGET_SLUGS;
    }

    /** @return list<string> */
    public function structuralEvidenceTypes(): array
    {
        return self::STRUCTURAL_EVIDENCE_TYPES;
    }

    /** @return list<string> */
    public function nonStructuralEvidenceTypes(): array
    {
        return self::NON_STRUCTURAL_EVIDENCE_TYPES;
    }

    public function canonicalTypeSlug(string $slug): ?string
    {
        $key = $this->normaliseIdentifier($slug);

        return self::TYPE_ALIASES[$key] ?? null;
    }

    public function isSourceTypeSupported(string $slug): bool
    {
        return $this->canonicalTypeSlug($slug) === self::SOURCE_TYPE;
    }

    public function isSupportedTarget(string $slug): bool
    {
        $canonical = $this->canonicalTypeSlug($slug);

        return $canonical !== null && in_array($canonical, self::SUPPORTED_TARGET_SLUGS, true);
    }

    public function isLowConfidenceTarget(string $slug): bool
    {
        $canonical = $this->canonicalTypeSlug($slug);

        return $canonical !== null && in_array($canonical, self::LOW_CONFIDENCE_TARGET_SLUGS, true);
    }

    public function isExcludedTarget(string $slug): bool
    {
        $canonical = $this->canonicalTypeSlug($slug);

        return $canonical !== null && in_array($canonical, self::EXCLUDED_TARGET_SLUGS, true);
    }

    public function confidenceLevelForTarget(string $slug): ?string
    {
        if (! $this->isSupportedTarget($slug)) {
            return null;
        }

        return $this->isLowConfidenceTarget($slug)
            ? self::CONFIDENCE_LOW
            : self::CONFIDENCE_MEDIUM;
    }

    public function requiresStructuralEvidence(): bool
    {
        return true;
    }

    /**
     * @param  list<string>  $evidenceTypes
     */
    public function hasStructuralEvidence(array $evidenceTypes): bool
    {
        foreach ($evidenceTypes as $evidenceType) {
            if ($this->isStructuralEvidence($evidenceType)) {
                return true;
            }
        }

        return false;
    }

    public function isStructuralEvidence(string $evidenceType): bool
    {
        return in_array($this->canonicalEvidenceType($evidenceType), self::STRUCTURAL_EVIDENCE_TYPES, true);
    }

    /**
     * @param  list<string>  $evidenceTypes
     */
    public function canSuggest(string $sourceTypeSlug, string $targetTypeSlug, array $evidenceTypes): bool
    {
        return $this->suppressionReasons($sourceTypeSlug, $targetTypeSlug, $evidenceTypes) === [];
    }

    /**
     * @param  list<string>  $evidenceTypes
     * @return list<string>
     */
    public function suppressionReasons(string $sourceTypeSlug, string $targetTypeSlug, array $evidenceTypes): array
    {
        $reasons = [];

        if (! $this->isSourceTypeSupported($sourceTypeSlug)) {
            $reasons[] = 'source_type_not_abstract';
        }

        if ($this->isExcludedTarget($targetTypeSlug)) {
            $reasons[] = 'target_type_excluded';
        } elseif (! $this->isSupportedTarget($targetTypeSlug)) {
            $reasons[] = 'target_type_not_supported';
        }

        if (! $this->hasStructuralEvidence($evidenceTypes)) {
            $reasons[] = 'structural_evidence_required';
        }

        return array_values(array_unique($reasons));
    }

    /** @return array{source: int, segment: int, remaining_abstract: int} */
    public function minimumTextLengths(): array
    {
        return [
            'source' => self::MINIMUM_SOURCE_LENGTH,
            'segment' => self::MINIMUM_SEGMENT_LENGTH,
            'remaining_abstract' => self::MINIMUM_REMAINING_ABSTRACT_LENGTH,
        ];
    }

    private function canonicalEvidenceType(string $evidenceType): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower(trim($evidenceType))), '_');
    }

    private function normaliseIdentifier(string $value): string
    {
        return (string) preg_replace('/[^a-z0-9]+/', '', strtolower(trim($value)));
    }
}
