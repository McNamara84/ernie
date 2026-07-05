<?php

declare(strict_types=1);

namespace App\Services\DescriptionSegmentation;

use App\Models\Description;
use App\Support\DescriptionSegmentation\DescriptionSegmentationPolicy;
use Closure;
use Illuminate\Database\Eloquent\Builder;

final readonly class DescriptionSegmentationDiscoveryService
{
    public const string ASSISTANT_ID = 'description-segmentation';

    public const string TARGET_TYPE = 'description';

    private const int CHUNK_SIZE = 50;

    public function __construct(
        private DescriptionSegmentationPreviewService $previewBuilder,
    ) {}

    /**
     * @param  Closure(int, string, int, string, string, float|null, array<string, mixed>|null): bool  $storeSuggestion
     * @param  Closure(string): void  $onProgress
     */
    public function discover(Closure $storeSuggestion, Closure $onProgress): int
    {
        $stored = 0;
        $processed = 0;
        $suppressed = 0;
        $query = $this->candidateQuery();
        $total = (clone $query)->count();

        if ($total === 0) {
            $onProgress('No eligible Abstract descriptions found.');

            return 0;
        }

        $query
            ->with(['descriptionType', 'resource.titles.titleType'])
            ->orderBy('id')
            ->chunkById(self::CHUNK_SIZE, function ($descriptions) use (&$stored, &$processed, &$suppressed, $total, $storeSuggestion, $onProgress): void {
                /** @var iterable<int, Description> $descriptions */
                foreach ($descriptions as $description) {
                    $processed++;
                    $onProgress("Checking Abstract description {$processed} of {$total}.");

                    $metadata = $this->previewBuilder->buildForDescription($description);

                    if ($metadata === null) {
                        $suppressed++;

                        continue;
                    }

                    $suggestedValue = $this->suggestedValue($description, $metadata);

                    if ($storeSuggestion(
                        $description->resource_id,
                        self::TARGET_TYPE,
                        $description->id,
                        $suggestedValue,
                        $this->suggestedLabel($metadata),
                        $this->confidenceScore($metadata),
                        $metadata,
                    )) {
                        $stored++;
                    }
                }
            });

        $onProgress("Stored {$stored} description segmentation suggestion(s); suppressed {$suppressed} Abstract description(s).");

        return $stored;
    }

    /** @return Builder<Description> */
    private function candidateQuery(): Builder
    {
        /** @var Builder<Description> $query */
        $query = Description::query()
            ->whereHas('descriptionType', fn (Builder $query): Builder => $query->where('slug', DescriptionSegmentationPolicy::SOURCE_TYPE));

        return $query;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function suggestedLabel(array $metadata): string
    {
        $proposed = is_array($metadata['proposed'] ?? null) ? $metadata['proposed'] : [];
        $targetTypes = is_array($proposed['target_types'] ?? null) ? $proposed['target_types'] : [];
        $labels = array_map(static fn (mixed $type): string => (string) $type, $targetTypes);

        if ($labels === []) {
            return 'Split Abstract into more specific Description Types';
        }

        return 'Split Abstract into '.implode(', ', $labels);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function confidenceScore(array $metadata): ?float
    {
        $confidence = is_array($metadata['confidence'] ?? null) ? $metadata['confidence'] : [];
        $score = $confidence['score'] ?? null;

        return is_float($score) || is_int($score) ? (float) $score : null;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function suggestedValue(Description $description, array $metadata): string
    {
        $current = is_array($metadata['current'] ?? null) ? $metadata['current'] : [];
        $proposed = is_array($metadata['proposed'] ?? null) ? $metadata['proposed'] : [];
        $signature = [
            'description_id' => $description->id,
            'value_hash' => $current['value_hash'] ?? null,
            'proposed' => $proposed,
            'policy_version' => $metadata['policy_version'] ?? null,
        ];

        return 'description-segmentation:'.hash('sha256', json_encode($signature, JSON_THROW_ON_ERROR));
    }
}
