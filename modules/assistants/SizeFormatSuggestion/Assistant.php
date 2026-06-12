<?php

declare(strict_types=1);

namespace Modules\Assistants\SizeFormatSuggestion;

use App\Services\SizeFormatFileProbeService;
use App\Models\AssistantSuggestion;
use App\Services\Assistance\GenericTableAssistant;
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
        return __DIR__ . '/manifest.json';
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
        $resources = \App\Models\Resource::whereDoesntHave('formats')->orWhereDoesntHave('sizes')->get();

        foreach ($resources as $index => $resource) {
            $onProgress("Checking resource " . ($index + 1) . " of " . $resources->count());

            //Call Size/Format probing logic here
            $suggestedSizeFormats = $this->lookupSizeFormats($resource);

            foreach ($suggestedSizeFormats as $suggestion) {
                if ($suggestion['type'] === 'format' && $resource->formats()->exists()) {
                    continue;
                }
                if ($suggestion['type'] === 'size' && $resource->sizes()->exists()) {
                    continue;
                }

                $stored = $this->storeSuggestion(
                    resourceId: $resource->id,
                    targetType: 'resource',
                    targetId: $resource->id,
                    suggestedValue: (string) $suggestion['inferred_value'],
                    suggestedLabel: strtoupper((string) $suggestion['type']) . ': ' . (string) $suggestion['inferred_value'],
                    similarityScore: null,
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
        // TODO: Create the Format or Size record for the resource

        return [
            'success' => true,
            'message' => "Suggestion '{$suggestion->suggested_label}' applied.",
        ];
    }

    private function lookupSizeFormats(\App\Models\Resource $resource): array
    {
        $results = $this->service->extractAndProbe(
            'https://doi.org/' . $resource->doi
        );
        return $this->service->buildSuggestions($results);
    }
}
