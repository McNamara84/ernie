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

    public function transformCollection(mixed $paginator): mixed
    {
        if (method_exists($paginator, 'through')) {
            return $paginator->through(fn ($suggestion) => $this->transformItem($suggestion));
        }

        if (is_iterable($paginator)) {
            $items = [];
            foreach ($paginator as $suggestion) {
                if ($suggestion instanceof AssistantSuggestion) {
                    $items[] = $this->transformItem($suggestion);
                }
            }
            return $items;
        }

        return $paginator;
    }

    public function transformItem(mixed $suggestion): array
    {
        if (! $suggestion instanceof AssistantSuggestion) {
            return is_array($suggestion) ? $suggestion : [];
        }

        $metadata = is_array($suggestion->metadata) ? $suggestion->metadata : [];

        $method = (string) ($metadata['probe_method'] ?? 'UNKNOWN');
        $sourceUrl = (string) ($metadata['source_url'] ?? '#');
        $confidence = (string) ($metadata['confidence'] ?? 'low');
        
        $timestamp = method_exists($suggestion, 'getAttribute') && $suggestion->getAttribute('discovered_at')
            ? $suggestion->discovered_at?->toIso8601String()
            : now()->toIso8601String();

        return [
            'id' => $suggestion->id,
            'target_type' => $suggestion->target_type,
            'suggested_value' => $suggestion->suggested_value,
            'suggested_label' => $suggestion->suggested_label,
            'resource_doi' => $suggestion->resource?->doi ?? null,
            'resource_title' => $suggestion->resource?->title ?? null,
            
            'metadata' => array_merge($metadata, [
                'source_url' => $sourceUrl,
                'probe_method' => $method,
                'confidence' => $confidence,
            ]), 

            'explanation' => $this->explainer->resolve($confidence, $method),
            'link_label' => $this->resolveSourceLinkLabel($sourceUrl),
            'technical_meta' => [
                'Probing Route' => $method,
                'Timestamp' => $timestamp,
            ],
        ];
    }

    private function resolveSourceLinkLabel(string $url): string
    {
        if (str_contains($url, 'dataservices.gfz-potsdam.de')) {
            return 'Open GFZ Data Services Listing';
        }

        return 'Open Origin Download Source';
    }
}