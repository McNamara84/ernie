<?php

declare(strict_types=1);

namespace Modules\Assistants\SizeFormatSuggestion;

use App\Models\AssistantSuggestion;
use App\Models\Resource;
use App\Services\Assistance\GenericTableAssistant;
use App\Services\SizeFormatFileProbeService;
use Closure;

class Assistant extends GenericTableAssistant
{
    public function __construct(
        private readonly SizeFormatFileProbeService $probeService,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function getManifestPath(): string
    {
        return __DIR__ . '/manifest.json';
    }

    #[\Override]
    protected function discover(Closure $onProgress): int
    {
        $stored = 0;

        Resource::query()
            ->whereNotNull('doi')
            ->select(['id', 'doi'])
            ->eachById(function (Resource $resource) use (&$stored, $onProgress): void {
                $onProgress("Checking {$resource->doi}");

                $evidence = array_values(array_filter(
                    $this->probeService->extractAndProbe('https://doi.org/' . ltrim($resource->doi, '/')),
                    static fn (array $result): bool => ($result['probe_method'] ?? 'SKIP') !== 'SKIP'
                        && ! empty($result['raw_evidence']['files']),
                ));

                if ($evidence === []) {
                    return;
                }

                $formats = [];
                $sizes = [];

                foreach ($evidence as $result) {
                    foreach ($result['raw_evidence']['files'] as $file) {
                        if (! empty($file['format'])) {
                            $formats[] = $file['format'];
                        }

                        if (! empty($file['file-size'])) {
                            $sizes[] = $file['file-size'];
                        }
                    }
                }

                $formats = array_values(array_unique($formats));
                $sizes = array_values(array_unique($sizes));
                $value = hash('sha256', json_encode($evidence, JSON_THROW_ON_ERROR));
                $label = sprintf(
                    '%d file(s): %s',
                    array_sum(array_map(
                        static fn (array $result): int => count($result['raw_evidence']['files']),
                        $evidence,
                    )),
                    $formats === [] ? 'unknown format' : implode(', ', $formats),
                );

                $stored += (int) $this->storeSuggestion(
                    resourceId: $resource->id,
                    targetType: 'resource',
                    targetId: $resource->id,
                    suggestedValue: $value,
                    suggestedLabel: $label,
                    metadata: [
                        'formats' => $formats,
                        'sizes' => $sizes,
                        'evidence' => $evidence,
                    ],
                );
            });

        return $stored;
    }

    #[\Override]
    protected function applyAccepted(AssistantSuggestion $suggestion): array
    {
        return [
            'success' => false,
            'message' => 'Size and format suggestions require curator review before they can be applied.',
        ];
    }
}
