<?php

declare(strict_types=1);

namespace Modules\Assistants\SizeFormatSuggestion;

use App\Models\AssistantSuggestion;
use App\Models\Format;
use App\Models\Resource;
use App\Models\Size;
use App\Services\Assistance\GenericTableAssistant;
use App\Services\SizeFormatFileProbeService;
use Closure;

class Assistant extends GenericTableAssistant
{
    public function __construct(
        private readonly SizeFormatFileProbeService $service,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function getManifestPath(): string
    {
        return __DIR__.'/manifest.json';
    }

    /**
     * Discover size and format suggestions for resources.
     *
     * @param  Closure(string): void  $onProgress
     */
    #[\Override]
    protected function discover(Closure $onProgress): int
    {
        $count = 0;

        // iterate over resources that have no format or size
        $resources = \App\Models\Resource::whereNotNull('doi')
            ->where(function ($query) {
                $query->whereDoesntHave('formats')->orWhereDoesntHave('sizes');

            })
            ->get();

        foreach ($resources as $index => $resource) {
            $onProgress('Checking resource '.($index + 1).' of '.$resources->count());

            // Call Size/Format probing logic here
            $suggestedSizeFormats = $this->lookupSizeFormats($resource);

            foreach ($suggestedSizeFormats as $suggestion) {
                if ($suggestion['type'] === 'format' && $resource->formats()->exists()) {
                    continue;
                }
                if ($suggestion['type'] === 'size' && $resource->sizes()->exists()) {
                    continue;
                }

                $metadata = $suggestion;

                if ($suggestion['type'] === 'size') {
                    $metadata['parsed_size'] = $this->parseSizeValue((string) $suggestion['inferred_value']);

                    // A resource has one aggregate file size. Remove totals from
                    // earlier discovery runs before storing the latest result.
                    AssistantSuggestion::where('assistant_id', $this->getId())
                        ->where('target_type', 'size')
                        ->where('target_id', $resource->id)
                        ->where('suggested_value', '!=', (string) $suggestion['inferred_value'])
                        ->delete();
                }

                $stored = $this->storeSuggestion(
                    resourceId: $resource->id,
                    targetType: (string) $suggestion['type'],
                    targetId: $resource->id,
                    suggestedValue: (string) $suggestion['inferred_value'],
                    suggestedLabel: strtoupper((string) $suggestion['type']).': '.(string) $suggestion['inferred_value'],
                    similarityScore: null,
                    metadata: $metadata,
                );

                if ($stored) {
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * Apply the suggestion when a curator clicks "Accept".
     *
     * @return array{success: bool, message: string}
     */
    #[\Override]
    protected function applyAccepted(AssistantSuggestion $suggestion): array
    {
        if ($suggestion->target_type === 'format') {
            Format::firstOrCreate([
                'resource_id' => $suggestion->resource_id,
                'value' => $suggestion->suggested_value,
            ]);

            return [
                'success' => true,
                'message' => "Format '{$suggestion->suggested_value}' applied.",
            ];
        }

        if ($suggestion->target_type === 'size') {
            $parsedSize = $suggestion->metadata['parsed_size']
                ?? $this->parseSizeValue($suggestion->suggested_value);

            Size::firstOrCreate([
                'resource_id' => $suggestion->resource_id,
                'numeric_value' => $parsedSize['numeric_value'],
                'unit' => $parsedSize['unit'],
                'type' => $parsedSize['type'],
            ]);

            return [
                'success' => true,
                'message' => "Size '{$suggestion->suggested_value}' applied.",
            ];
        }

        return [
            'success' => false,
            'message' => 'Unknown suggestion type.',
        ];
    }

    private function lookupSizeFormats(\App\Models\Resource $resource): array
    {
        $results = $this->service->extractAndProbe(
            'https://doi.org/'.$resource->doi
        );

        return $this->service->buildSuggestions($results);
    }

    /**
     * @return array{numeric_value: string|null, unit: string|null, type: null}
     */
    private function parseSizeValue(string $value): array
    {
        if (preg_match('/^\s*([0-9.]+)\s*([A-Za-z]+)?\s*$/', $value, $matches)) {
            return [
                'numeric_value' => $matches[1],
                'unit' => $matches[2] ?? null,
                'type' => null,
            ];
        }

        return [
            'numeric_value' => null,
            'unit' => null,
            'type' => null,
        ];
    }
}
