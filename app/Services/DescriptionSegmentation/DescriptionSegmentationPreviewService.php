<?php

declare(strict_types=1);

namespace App\Services\DescriptionSegmentation;

use App\Models\Description;
use App\Support\DescriptionSegmentation\DescriptionSegmentationPolicy;

final readonly class DescriptionSegmentationPreviewService
{
    public const string CONTRACT_VERSION = '1.0';

    public const int IMPLEMENTATION_ISSUE = 816;

    public function __construct(
        private DescriptionSegmentationPolicy $policy,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function buildForDescription(Description $description): ?array
    {
        $sourceType = $description->relationLoaded('descriptionType')
            ? $description->descriptionType->slug
            : null;

        if ($sourceType === null || ! $this->policy->isSourceTypeSupported($sourceType)) {
            return null;
        }

        $sourceText = (string) $description->value;
        $normalizedText = $this->normalizeText($sourceText);

        if ($this->textLength($normalizedText) < DescriptionSegmentationPolicy::MINIMUM_SOURCE_LENGTH) {
            return null;
        }

        $sections = $this->sections($normalizedText);

        if ($sections === []) {
            return null;
        }

        $preview = $this->previewFromSections($normalizedText, $sections, $description->language);

        if ($preview === null) {
            return null;
        }

        $segmentTypes = array_values(array_unique(array_map(
            static fn (array $segment): string => (string) $segment['description_type'],
            $preview['segments'],
        )));

        return [
            'contract_version' => self::CONTRACT_VERSION,
            'issue' => self::IMPLEMENTATION_ISSUE,
            'policy_version' => $this->policy->policyVersion(),
            'current' => [
                'description_id' => $description->id,
                'resource_id' => $description->resource_id,
                'description_type' => DescriptionSegmentationPolicy::SOURCE_TYPE,
                'value' => $sourceText,
                'value_hash' => $this->hashText($sourceText),
                'language' => $description->language,
            ],
            'proposed' => [
                'remaining_abstract' => $preview['remaining_abstract'],
                'segments' => $preview['segments'],
                'target_types' => $segmentTypes,
            ],
            'confidence' => [
                'level' => $this->overallConfidence($preview['segments']),
                'score' => $this->overallScore($preview['segments']),
                'evidence' => $this->uniqueStrings(array_merge(...array_map(
                    static fn (array $segment): array => $segment['evidence_types'],
                    $preview['segments'],
                ))),
            ],
            'acceptance' => [
                'updates' => [
                    'source_description' => 'replace_abstract_value',
                    'new_descriptions' => $segmentTypes,
                ],
                'preconditions' => [
                    'source description still exists',
                    'source description type is still Abstract',
                    'source description text still matches the reviewed preview hash',
                    'target description types still exist',
                ],
                'stale_if' => [
                    'source Abstract text changed',
                    'source description type changed',
                    'target DescriptionType seed data changed',
                ],
            ],
        ];
    }

    /**
     * @return list<array{start: int, content_start: int, end: int, target_type: string, label: string, evidence_types: list<string>}>
     */
    private function sections(string $text): array
    {
        $sections = array_merge(
            $this->headingSections($text),
            $this->fileInventorySections($text),
        );

        usort($sections, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);

        $filtered = [];
        $lastEnd = -1;

        foreach ($sections as $section) {
            if ($section['start'] < $lastEnd) {
                continue;
            }

            $filtered[] = $section;
            $lastEnd = $section['end'];
        }

        return $filtered;
    }

    /**
     * @return list<array{start: int, content_start: int, end: int, target_type: string, label: string, evidence_types: list<string>}>
     */
    private function headingSections(string $text): array
    {
        $headingPattern = $this->headingPattern();

        if (! preg_match_all($headingPattern, $text, $matches, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        $headings = [];

        foreach ($matches[0] as $index => $match) {
            $label = trim((string) $matches['label'][$index][0]);
            $targetType = $this->targetTypeForHeading($label);

            if ($targetType === null) {
                continue;
            }

            $start = (int) $match[1];
            $contentStart = $start + strlen((string) $match[0]);

            $headings[] = [
                'start' => $start,
                'content_start' => $contentStart,
                'target_type' => $targetType,
                'label' => $label,
                'evidence_types' => $this->evidenceTypesForHeading($label, $targetType),
            ];
        }

        $sections = [];
        $count = count($headings);

        for ($i = 0; $i < $count; $i++) {
            $end = $headings[$i + 1]['start'] ?? strlen($text);

            $sections[] = [
                ...$headings[$i],
                'end' => $end,
            ];
        }

        return $sections;
    }

    private function headingPattern(): string
    {
        $labels = [
            'methods?',
            'methodology',
            'sampling',
            'sample(?:\s+collection|\s+preparation)?',
            'data\s+processing',
            'processing(?:\s+workflow)?',
            'analysis',
            'analytical\s+methods?',
            'computational\s+methods?',
            'technical(?:\s+information|\s+info|\s+details)?',
            'data\s+formats?',
            'file\s+formats?',
            'formats?',
            'software',
            'instruments?',
            'instrumentation',
            'version(?:\s+history|\s+notes?)?',
            'changelog',
            'grid',
            'resolution',
            'parameters?',
            'coordinate\s+system',
            'table\s+of\s+contents',
            'contents?',
            'data\s+contents?',
            'files?',
            'file\s+inventory',
            'data\s+files?',
            'dataset\s+contents?',
            'included\s+files?',
            'list\s+of\s+files?',
            'series\s+information',
            'data\s+series',
            'release\s+series',
            'version\s+series',
            'collection\s+series',
        ];

        return '/(?m)(?:^|\n)[ \t]*(?:#{1,6}[ \t]*)?(?<label>'.implode('|', $labels).')[ \t]*(?::|-|–)[ \t]*/iu';
    }

    private function targetTypeForHeading(string $label): ?string
    {
        $normalized = mb_strtolower(trim($label));

        return match (true) {
            (bool) preg_match('/\bseries\b/u', $normalized) => DescriptionSegmentationPolicy::TARGET_SERIES_INFORMATION,
            (bool) preg_match('/\b(methods?|methodology|sampling|sample|processing|analysis|analytical|computational)\b/u', $normalized) => DescriptionSegmentationPolicy::TARGET_METHODS,
            (bool) preg_match('/\b(technical|formats?|software|instruments?|instrumentation|version|changelog|grid|resolution|parameters?|coordinate)\b/u', $normalized) => DescriptionSegmentationPolicy::TARGET_TECHNICAL_INFO,
            (bool) preg_match('/\b(table of contents|contents|files?|file inventory|data files|dataset contents|included files|list of files)\b/u', $normalized) => DescriptionSegmentationPolicy::TARGET_TABLE_OF_CONTENTS,
            default => null,
        };
    }

    /**
     * @return list<string>
     */
    private function evidenceTypesForHeading(string $label, string $targetType): array
    {
        $normalized = mb_strtolower($label);
        $types = [DescriptionSegmentationPolicy::EVIDENCE_HEADING];

        if ($targetType === DescriptionSegmentationPolicy::TARGET_TABLE_OF_CONTENTS || str_contains($normalized, 'file')) {
            $types[] = DescriptionSegmentationPolicy::EVIDENCE_FILE_INVENTORY;
        }

        if (str_contains($normalized, 'version') || str_contains($normalized, 'changelog')) {
            $types[] = DescriptionSegmentationPolicy::EVIDENCE_VERSION_BLOCK;
        }

        return $this->uniqueStrings($types);
    }

    /**
     * @return list<array{start: int, content_start: int, end: int, target_type: string, label: string, evidence_types: list<string>}>
     */
    private function fileInventorySections(string $text): array
    {
        $lines = preg_split('/(\n)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE);

        if (! is_array($lines)) {
            return [];
        }

        $blocks = [];
        $offset = 0;
        $current = null;

        foreach ($lines as $part) {
            if ($part === "\n") {
                $offset++;

                continue;
            }

            $line = (string) $part;
            $lineStart = $offset;
            $lineEnd = $offset + strlen($line);
            $offset = $lineEnd;

            if ($this->looksLikeFileInventoryLine($line)) {
                if ($current === null) {
                    $current = [
                        'start' => $lineStart,
                        'end' => $lineEnd,
                        'count' => 1,
                    ];
                } else {
                    $current['end'] = $lineEnd;
                    $current['count']++;
                }

                continue;
            }

            if ($current !== null) {
                if ($current['count'] >= 3) {
                    $blocks[] = $current;
                }

                $current = null;
            }
        }

        if ($current !== null && $current['count'] >= 3) {
            $blocks[] = $current;
        }

        return array_map(static fn (array $block): array => [
            'start' => $block['start'],
            'content_start' => $block['start'],
            'end' => $block['end'],
            'target_type' => DescriptionSegmentationPolicy::TARGET_TABLE_OF_CONTENTS,
            'label' => 'File inventory',
            'evidence_types' => [
                DescriptionSegmentationPolicy::EVIDENCE_LIST_STRUCTURE,
                DescriptionSegmentationPolicy::EVIDENCE_FILE_INVENTORY,
            ],
        ], $blocks);
    }

    private function looksLikeFileInventoryLine(string $line): bool
    {
        $trimmed = trim($line);

        if ($trimmed === '') {
            return false;
        }

        if (! preg_match('/^(?:[-*]|\d+[.)])\s+/u', $trimmed)) {
            return false;
        }

        return (bool) preg_match('/\.(?:csv|txt|nc|zip|pdf|xml|json|kml|kmz|grd|tif|tiff|dat|xlsx?|h5|hdf5?)\b|file|folder|directory/iu', $trimmed);
    }

    /**
     * @param  list<array{start: int, content_start: int, end: int, target_type: string, label: string, evidence_types: list<string>}>  $sections
     * @return array{remaining_abstract: string, segments: list<array<string, mixed>>}|null
     */
    private function previewFromSections(string $text, array $sections, ?string $language): ?array
    {
        $remainingParts = [];
        $segments = [];
        $cursor = 0;

        foreach ($sections as $section) {
            if ($section['start'] > $cursor) {
                $remainingParts[] = substr($text, $cursor, $section['start'] - $cursor);
            }

            $rawSegment = substr($text, $section['content_start'], $section['end'] - $section['content_start']);
            $segmentText = $this->cleanSegment($rawSegment);
            $evidenceTypes = $section['evidence_types'];
            $targetType = $section['target_type'];

            if (
                $this->textLength($segmentText) < DescriptionSegmentationPolicy::MINIMUM_SEGMENT_LENGTH
                || ! $this->policy->canSuggest(DescriptionSegmentationPolicy::SOURCE_TYPE, $targetType, $evidenceTypes)
            ) {
                $remainingParts[] = substr($text, $section['start'], $section['end'] - $section['start']);
            } else {
                $this->appendSegment($segments, [
                    'description_type' => $targetType,
                    'value' => $segmentText,
                    'language' => $language,
                    'confidence' => $this->policy->confidenceLevelForTarget($targetType),
                    'confidence_score' => $this->confidenceScore($this->policy->confidenceLevelForTarget($targetType)),
                    'evidence_label' => $section['label'],
                    'evidence_types' => $evidenceTypes,
                    'source_ranges' => [
                        [
                            'start' => $section['start'],
                            'end' => $section['end'],
                        ],
                    ],
                ]);
            }

            $cursor = $section['end'];
        }

        if ($cursor < strlen($text)) {
            $remainingParts[] = substr($text, $cursor);
        }

        $remainingAbstract = $this->cleanSegment(implode("\n\n", array_filter(
            array_map(fn (string $part): string => $this->cleanSegment($part), $remainingParts),
            static fn (string $part): bool => $part !== '',
        )));

        if ($segments === [] || $this->textLength($remainingAbstract) < DescriptionSegmentationPolicy::MINIMUM_REMAINING_ABSTRACT_LENGTH) {
            return null;
        }

        return [
            'remaining_abstract' => $remainingAbstract,
            'segments' => $segments,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $segments
     * @param  array<string, mixed>  $segment
     */
    private function appendSegment(array &$segments, array $segment): void
    {
        foreach ($segments as &$existing) {
            if ($existing['description_type'] !== $segment['description_type']) {
                continue;
            }

            $existing['value'] = $this->cleanSegment((string) $existing['value']."\n\n".(string) $segment['value']);
            $existing['evidence_types'] = $this->uniqueStrings(array_merge(
                is_array($existing['evidence_types']) ? $existing['evidence_types'] : [],
                is_array($segment['evidence_types']) ? $segment['evidence_types'] : [],
            ));
            $existing['source_ranges'] = array_merge(
                is_array($existing['source_ranges']) ? $existing['source_ranges'] : [],
                is_array($segment['source_ranges']) ? $segment['source_ranges'] : [],
            );

            return;
        }

        $segments[] = $segment;
    }

    private function cleanSegment(string $text): string
    {
        $text = $this->normalizeText($text);
        $text = preg_replace('/[ \t]+$/m', '', $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }

    private function normalizeText(string $text): string
    {
        return str_replace(["\r\n", "\r"], "\n", $text);
    }

    private function textLength(string $text): int
    {
        return mb_strlen(trim($text));
    }

    /**
     * @param  list<array<string, mixed>>  $segments
     */
    private function overallConfidence(array $segments): string
    {
        foreach ($segments as $segment) {
            if (($segment['confidence'] ?? null) === DescriptionSegmentationPolicy::CONFIDENCE_LOW) {
                return DescriptionSegmentationPolicy::CONFIDENCE_LOW;
            }
        }

        return DescriptionSegmentationPolicy::CONFIDENCE_MEDIUM;
    }

    /**
     * @param  list<array<string, mixed>>  $segments
     */
    private function overallScore(array $segments): float
    {
        $scores = array_filter(
            array_map(static fn (array $segment): mixed => $segment['confidence_score'] ?? null, $segments),
            static fn (mixed $score): bool => is_float($score) || is_int($score),
        );

        if ($scores === []) {
            return 0.0;
        }

        return (float) min($scores);
    }

    private function confidenceScore(?string $confidence): float
    {
        return match ($confidence) {
            DescriptionSegmentationPolicy::CONFIDENCE_MEDIUM => 0.65,
            DescriptionSegmentationPolicy::CONFIDENCE_LOW => 0.35,
            default => 0.0,
        };
    }

    /**
     * @param  array<int|string, mixed>  $values
     * @return list<string>
     */
    private function uniqueStrings(array $values): array
    {
        return array_values(array_unique(array_filter(
            array_map(static fn (mixed $value): string => trim((string) $value), $values),
            static fn (string $value): bool => $value !== '',
        )));
    }

    private function hashText(string $text): string
    {
        return hash('sha256', $text);
    }
}
