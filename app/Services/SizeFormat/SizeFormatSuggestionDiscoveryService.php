<?php

declare(strict_types=1);

namespace App\Services\SizeFormat;

use App\Models\AssistantSuggestion;
use App\Models\Resource;
use App\Services\SizeFormatFileProbeService;
use Closure;
use Illuminate\Database\Eloquent\Builder;

final class SizeFormatSuggestionDiscoveryService
{
    private const int CHUNK_SIZE = 50;

    public function __construct(
        private readonly SizeFormatFileProbeService $probeService,
        private readonly SizeFormatSizeParserService $sizeParser,
    ) {}

    /**
     * @param  Closure(int, string, int, string, string, float|null, array<string, mixed>|null): bool  $storeSuggestion
     * @param  Closure(string): void  $onProgress
     */
    public function discover(string $assistantId, Closure $storeSuggestion, Closure $onProgress): int
    {
        $count = 0;
        $processed = 0;
        $query = $this->candidateQuery();
        $total = (clone $query)->count();

        $query
            ->withExists(['formats', 'sizes'])
            ->orderBy('id')
            ->chunkById(self::CHUNK_SIZE, function ($resources) use (&$count, &$processed, $total, $assistantId, $storeSuggestion, $onProgress): void {
                /** @var iterable<int, Resource> $resources */
                foreach ($resources as $resource) {
                    $processed++;
                    $onProgress("Checking resource {$processed} of {$total}");

                    $count += $this->discoverForResource($assistantId, $resource, $storeSuggestion);
                }
            });

        return $count;
    }

    /**
     * @param  Closure(int, string, int, string, string, float|null, array<string, mixed>|null): bool  $storeSuggestion
     */
    private function discoverForResource(string $assistantId, Resource $resource, Closure $storeSuggestion): int
    {
        $storedCount = 0;
        $suggestions = $this->lookupSizeFormats($resource);
        $hasFormats = (bool) $resource->getAttribute('formats_exists');
        $hasSizes = (bool) $resource->getAttribute('sizes_exists');

        foreach ($suggestions as $suggestion) {
            $type = (string) ($suggestion['type'] ?? '');

            if ($type === 'format' && $hasFormats) {
                continue;
            }

            if ($type === 'size' && $hasSizes) {
                continue;
            }

            if (! in_array($type, ['format', 'size'], true)) {
                continue;
            }

            $suggestedValue = (string) ($suggestion['inferred_value'] ?? '');
            if ($suggestedValue === '') {
                continue;
            }

            $metadata = $suggestion;

            if ($type === 'size') {
                $metadata['parsed_size'] = $this->sizeParser->parse($suggestedValue);
                $this->deleteOutdatedSizeSuggestions($assistantId, $resource, $suggestedValue);
            }

            $stored = $storeSuggestion(
                $resource->id,
                $type,
                $resource->id,
                $suggestedValue,
                strtoupper($type).': '.$suggestedValue,
                $this->confidenceToScore($suggestion['confidence'] ?? null),
                $metadata,
            );

            if ($stored) {
                $storedCount++;
            }
        }

        return $storedCount;
    }

    /** @return Builder<Resource> */
    private function candidateQuery(): Builder
    {
        /** @var Builder<Resource> $query */
        $query = Resource::query()
            ->whereNotNull('doi')
            ->whereDoesntHave('igsnMetadata')
            ->whereDoesntHave('resourceType', fn (Builder $query): Builder => $query->where('slug', 'physical-object'))
            ->where(function (Builder $query): void {
                $query->whereDoesntHave('formats')
                    ->orWhereDoesntHave('sizes');
            });

        return $query;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function lookupSizeFormats(Resource $resource): array
    {
        $doi = trim((string) $resource->doi);

        if ($doi === '') {
            return [];
        }

        $results = $this->probeService->extractAndProbe('https://doi.org/'.$doi);

        return $this->probeService->buildSuggestions($results);
    }

    private function deleteOutdatedSizeSuggestions(string $assistantId, Resource $resource, string $suggestedValue): void
    {
        AssistantSuggestion::where('assistant_id', $assistantId)
            ->where('target_type', 'size')
            ->where('target_id', $resource->id)
            ->where('suggested_value', '!=', $suggestedValue)
            ->delete();
    }

    private function confidenceToScore(mixed $confidence): ?float
    {
        return match ($confidence) {
            'high' => 0.95,
            'medium' => 0.65,
            'low' => 0.35,
            default => null,
        };
    }
}
