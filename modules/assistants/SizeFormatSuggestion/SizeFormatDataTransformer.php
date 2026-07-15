<?php

declare(strict_types=1);

namespace Modules\Assistants\SizeFormatSuggestion;

use App\Models\AssistantSuggestion;
use Illuminate\Database\Eloquent\Model;

class SizeFormatDataTransformer
{
    private readonly SizeFormatConfidenceExplainer $explainer;

    public function __construct()
    {
        $this->explainer = new SizeFormatConfidenceExplainer();
    }

    public function transformCollection(mixed $paginator): mixed
    {
        if (is_object($paginator) && method_exists($paginator, 'through')) {
            return $paginator->through(fn (AssistantSuggestion $suggestion) => $this->transformItem($suggestion));
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

    public function transformItem(AssistantSuggestion $suggestion): array
    {
        $metadata = is_array($suggestion->metadata) ? $suggestion->metadata : [];

        $method = (string) ($metadata['probe_method'] ?? 'UNKNOWN');
        $sourceUrl = (string) ($metadata['source_url'] ?? '#');
        $confidence = (string) ($metadata['confidence'] ?? 'low');
        
        $resource = $suggestion->getAttribute('resource');
        $resourceDoi = $resource instanceof Model ? $resource->getAttribute('doi') : null;
        $resourceTitle = $resource instanceof Model ? $resource->getAttribute('title') : null;
        
        $discoveredAt = $suggestion->getAttribute('discovered_at');
        $timestamp = is_object($discoveredAt) && method_exists($discoveredAt, 'toIso8601String')
            ? $discoveredAt->toIso8601String()
            : now()->toIso8601String();

        return [
            // Core legacy fields explicitly requested by Daniel to prevent frontend regression
            'id' => $suggestion->id,
            'assistant_id' => $suggestion->assistant_id,
            'resource_id' => $suggestion->resource_id,
            'target_id' => $suggestion->target_id,
            'target_type' => $suggestion->target_type,
            'suggested_value' => $suggestion->suggested_value,
            'suggested_label' => $suggestion->suggested_label,
            'similarity_score' => $suggestion->similarity_score ?? 0,
            'discovered_at' => $timestamp,
            'resource_doi' => $resourceDoi,
            'resource_title' => $resourceTitle,
            
            // Legacy metadata layout structure
            'metadata' => array_merge($metadata, [
                'source_url' => $sourceUrl,
                'probe_method' => $method,
                'confidence' => $confidence,
            ]), 

            // Extended Traceability datasets for #935
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
