<?php

declare(strict_types=1);

namespace Modules\assistants\SizeFormatSuggestion;

use App\Models\AssistantSuggestion;
use Illuminate\Database\Eloquent\Model;

class SizeFormatDataTransformer
{
    private readonly SizeFormatConfidenceExplainer $explainer;

    public function __construct()
    {
        $this->explainer = new SizeFormatConfidenceExplainer();
    }

    /**
     * Transform a paginated collection of suggestions.
     */
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

    /**
     * Transform a single item into logically grouped visual datasets for the UI.
     * This fulfills Task 3: "Logical grouping and visual separation".
     */
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
            // GROUP 1: Primary Identification (Core Legacy Fields)
            'id' => $suggestion->id,
            'assistant_id' => $suggestion->assistant_id,
            'resource_id' => $suggestion->resource_id,
            'target_id' => $suggestion->target_id,
            'target_type' => $suggestion->target_type,
            'suggested_value' => $suggestion->suggested_value,
            'suggested_label' => $suggestion->suggested_label,
            'similarity_score' => $suggestion->similarity_score ?? 0,
            
            // GROUP 2: Visual Curation Support (Grouped helper metadata)
            'curation_support' => [
                'explanation' => $this->explainer->resolve($confidence, $method),
                'source_url' => $sourceUrl,
                'link_label' => $this->resolveSourceLinkLabel($sourceUrl),
                'resource_doi' => $resourceDoi,
                'resource_title' => $resourceTitle,
            ],

            // GROUP 3: Technical Traceability Dataset (Visually separated system info)
            'technical_meta' => [
                'Probing Route' => $method,
                'Confidence Rating' => ucfirst($confidence),
                'Discovered At' => $timestamp,
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