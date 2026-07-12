<?php

declare(strict_types=1);

namespace Modules\Assistants\SizeFormatSuggestion;

use App\Models\AssistantSuggestion;

class SizeFormatDataTransformer
{
    private readonly SizeFormatConfidenceExplainer $explainer;

    public function __construct()
    {
        $this->explainer = new SizeFormatConfidenceExplainer();
    }

    /**
     * Transforms a paginated collection of suggestions into the enriched frontend structure.
     */
    public function transformCollection(mixed $paginator): mixed
    {
        // Safe check if the paginator supports Laravel's through method to maintain pagination metadata
        if (method_exists($paginator, 'through')) {
            return $paginator->through(fn ($suggestion) => $this->transformItem($suggestion));
        }

        if (is_iterable($paginator)) {
            $items = [];
            foreach ($paginator as $suggestion) {
                $items[] = $this->transformItem($suggestion);
            }
            return $items;
        }

        return $paginator;
    }

    /**
     * Maps a single suggestion model into a descriptive tracking array.
     */
    public function transformItem(AssistantSuggestion $suggestion): array
    {
        // Fallback structures if the legacy metadata fields are empty
        $metadata = is_array($suggestion->metadata) ? $suggestion->metadata : [];

        $method = (string) ($metadata['probe_method'] ?? 'UNKNOWN');
        $sourceUrl = (string) ($metadata['source_url'] ?? '#');
        $confidence = (string) ($metadata['confidence'] ?? 'low');

        return [
            'id' => $suggestion->id,
            'type' => $suggestion->target_type,
            'suggested_value' => $suggestion->suggested_value,
            'suggested_label' => $suggestion->suggested_label,
            'doi' => $suggestion->resource?->doi,
            'confidence' => $confidence,
            'probe_method' => $method,
            'explanation' => $this->explainer->resolve($confidence, $method),
            'source_url' => $sourceUrl,
            'link_label' => $this->resolveSourceLinkLabel($sourceUrl),
            'technical_meta' => [
                'Probing Route' => $method,
                'Timestamp' => $suggestion->discovered_at?->toIso8601String(),
            ],
        ];
    }

    /**
     * Resolves the proper anchor text for the destination download target.
     */
    private function resolveSourceLinkLabel(string $url): string
    {
        if (str_contains($url, 'dataservices.gfz-potsdam.de')) {
            return 'Open GFZ Data Services Listing';
        }

        return 'Open Origin Download Source';
    }
}