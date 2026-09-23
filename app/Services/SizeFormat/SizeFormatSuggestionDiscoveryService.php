<?php

declare(strict_types=1);

namespace App\Services\SizeFormat;

use App\Models\AssistantSuggestion;
use App\Models\LandingPageLink;
use App\Models\Resource;
use App\Services\SizeFormatFileProbeService;
use App\Support\SizeFormatFileRoleClassifier;
use Closure;
use Illuminate\Database\Eloquent\Builder;

final class SizeFormatSuggestionDiscoveryService
{
    public const ASSISTANT_ID = 'size-format-suggestion';

    private const int CHUNK_SIZE = 50;

    /** @var array<string, int> */
    private array $lastReport = [];

    public function __construct(
        private readonly SizeFormatFileProbeService $probeService,
        private readonly SizeFormatSourceResolverService $sourceResolver,
        private readonly SizeFormatFileRoleClassifier $roleClassifier,
        private readonly DigitalContentSizeService $digitalContentSizeService,
    ) {}

    /**
     * @param  Closure(int, string, int, string, string, float|null, array<string, mixed>|null): bool  $storeSuggestion
     * @param  Closure(string): void  $onProgress
     */
    public function discover(string $assistantId, Closure $storeSuggestion, Closure $onProgress): int
    {
        $count = 0;
        $processed = 0;
        $resourcesWithSuggestions = 0;
        $failedResources = 0;
        $staleRemoved = $this->removeSuggestionsForIneligibleResources($assistantId);
        $query = $this->candidateQuery();
        $total = (clone $query)->count();

        $query
            ->with([
                'formats:id,resource_id,value',
                'sizes:id,resource_id,numeric_value,unit,type',
                'landingPage.links' => fn ($query) => $query->orderBy('position'),
            ])
            ->orderBy('id')
            ->chunkById(self::CHUNK_SIZE, function ($resources) use (
                &$count,
                &$processed,
                &$resourcesWithSuggestions,
                &$failedResources,
                &$staleRemoved,
                $total,
                $assistantId,
                $storeSuggestion,
                $onProgress,
            ): void {
                /** @var iterable<int, Resource> $resources */
                foreach ($resources as $resource) {
                    $processed++;
                    $onProgress("Checking resource {$processed} of {$total}");

                    $result = $this->discoverForResource($assistantId, $resource, $storeSuggestion);
                    $count += $result['created'];
                    $staleRemoved += $result['stale_removed'];
                    $resourcesWithSuggestions += $result['pending'] > 0 ? 1 : 0;
                    $failedResources += $result['complete'] ? 0 : 1;
                }
            });

        $this->lastReport = [
            'candidate_resources' => $total,
            'checked_resources' => $processed,
            'resources_with_suggestions' => $resourcesWithSuggestions,
            'new_suggestions' => $count,
            'stale_suggestions_removed' => $staleRemoved,
            'incomplete_or_failed_resources' => $failedResources,
        ];

        $onProgress(sprintf(
            'Checked %d resource(s); %d with suggestions; %d new; %d stale removed; %d incomplete or failed.',
            $processed,
            $resourcesWithSuggestions,
            $count,
            $staleRemoved,
            $failedResources,
        ));

        return $count;
    }

    /** @return array<string, int> */
    public function lastReport(): array
    {
        return $this->lastReport;
    }

    /**
     * @param  Closure(int, string, int, string, string, float|null, array<string, mixed>|null): bool  $storeSuggestion
     * @return array{created: int, stale_removed: int, pending: int, complete: bool}
     */
    private function discoverForResource(string $assistantId, Resource $resource, Closure $storeSuggestion): array
    {
        $sources = $this->sourceResolver->resolve($resource);
        $formatCandidates = [];
        $sizeCandidates = [];
        $allProbesComplete = true;
        $primarySourceCount = 0;

        foreach ($sources as $source) {
            if ($source['kind'] === 'additional_download_link') {
                $sourceRole = $this->roleClassifier->classify($source['url'], $source['label']);

                if ($sourceRole['role'] !== SizeFormatFileRoleClassifier::PRIMARY_DATA) {
                    continue;
                }
            }

            $primarySourceCount++;

            $probeResult = $this->probeService->probeDownloadUrl($source['url']);

            if (($probeResult['probe_method'] ?? null) === 'SKIP') {
                $allProbesComplete = false;

                continue;
            }

            if (($probeResult['probe_complete'] ?? true) === false) {
                $allProbesComplete = false;
            }

            $suggestions = $this->probeService->buildSuggestions([$probeResult]);

            foreach ($suggestions as $suggestion) {
                $type = (string) ($suggestion['type'] ?? '');
                $value = trim((string) ($suggestion['inferred_value'] ?? ''));

                if ($value === '') {
                    continue;
                }

                $metadata = [
                    ...$suggestion,
                    'source' => $source,
                ];

                if ($type === 'format') {
                    $normalized = SizeFormatFormatNormalizerService::normalize($value);

                    if ($normalized !== '') {
                        $metadata['inferred_value'] = $normalized;
                        $formatCandidates[$normalized] = $metadata;
                    }

                    continue;
                }

                if ($type !== 'size' || ($probeResult['probe_complete'] ?? true) === false) {
                    continue;
                }

                $evidence = is_array($suggestion['evidence'] ?? null) ? $suggestion['evidence'] : [];
                $bytes = $evidence['total_bytes'] ?? $evidence['uncompressed_bytes'] ?? $evidence['content_length'] ?? null;

                if (! is_numeric($bytes) || (float) $bytes < 0 || (float) $bytes > PHP_INT_MAX) {
                    continue;
                }

                $byteCount = (int) round((float) $bytes);
                $semantics = ($evidence['size_semantics'] ?? null) === 'uncompressed_primary_data'
                    ? 'uncompressed_primary_data'
                    : 'primary_data';
                $typeLabel = $semantics === 'uncompressed_primary_data'
                    ? 'Uncompressed Primary Data Size'
                    : 'Primary Data Size';
                $value = $byteCount.' '.$typeLabel.' [bytes]';
                $metadata['suggestion_kind'] = 'size_addition';
                $metadata['inferred_value'] = $value;
                $metadata['proposed_size'] = [
                    'numeric_value' => (string) $byteCount,
                    'unit' => 'bytes',
                    'type' => $typeLabel,
                    'bytes' => $byteCount,
                    'semantics' => $semantics,
                ];
                $metadata['parsed_size'] = [
                    'numeric_value' => (string) $byteCount,
                    'unit' => 'bytes',
                    'type' => $typeLabel,
                ];
                $sizeCandidates[$value] = $metadata;
            }
        }

        $existingFormats = [];

        foreach ($resource->formats as $format) {
            $normalized = SizeFormatFormatNormalizerService::normalize((string) $format->value);

            if ($normalized !== '') {
                $existingFormats[$normalized] = true;
            }
        }

        $desired = ['format' => [], 'size' => []];
        $created = 0;

        foreach ($formatCandidates as $value => $metadata) {
            if (isset($existingFormats[$value])) {
                continue;
            }

            $metadata['suggestion_kind'] = 'format_addition';
            $desired['format'][] = $value;
            $created += $storeSuggestion(
                $resource->id,
                'format',
                $resource->id,
                $value,
                'FORMAT: '.$value,
                $this->confidenceToScore($metadata['confidence'] ?? null),
                $metadata,
            ) ? 1 : 0;
        }

        $sizeComplete = $allProbesComplete;

        if ($primarySourceCount > 1) {
            $sizeCandidates = [];
        }

        if (count($sizeCandidates) > 1) {
            $sizeCandidates = [];
            $sizeComplete = false;
        }

        foreach ($sizeCandidates as $value => $metadata) {
            $comparison = $this->compareExistingSizes($resource, (int) $metadata['proposed_size']['bytes']);

            if ($comparison['same']) {
                continue;
            }

            if ($comparison['current'] !== []) {
                $metadata['suggestion_kind'] = 'size_conflict';
                $metadata['current_sizes'] = $comparison['current'];
            }

            $desired['size'][] = $value;
            $created += $storeSuggestion(
                $resource->id,
                'size',
                $resource->id,
                $value,
                'SIZE: '.$value,
                $this->confidenceToScore($metadata['confidence'] ?? null),
                $metadata,
            ) ? 1 : 0;
        }

        $staleRemoved = 0;

        if ($allProbesComplete) {
            $staleRemoved += $this->reconcile($assistantId, $resource->id, 'format', $desired['format']);
        }

        if ($sizeComplete) {
            $staleRemoved += $this->reconcile($assistantId, $resource->id, 'size', $desired['size']);
        }

        $pending = AssistantSuggestion::query()
            ->where('assistant_id', $assistantId)
            ->where('resource_id', $resource->id)
            ->count();

        return [
            'created' => $created,
            'stale_removed' => $staleRemoved,
            'pending' => $pending,
            'complete' => $allProbesComplete && $sizeComplete,
        ];
    }

    /** @return Builder<Resource> */
    private function candidateQuery(): Builder
    {
        return Resource::query()
            ->whereDoesntHave('igsnMetadata')
            ->whereDoesntHave('resourceType', fn (Builder $query): Builder => $query->where('slug', 'physical-object'))
            ->whereHas('landingPage', function (Builder $query): void {
                $query
                    ->where('template', '!=', 'external')
                    ->where('downloads_unavailable', false)
                    ->where(function (Builder $query): void {
                        $query
                            ->where(function (Builder $query): void {
                                $query->whereNotNull('ftp_url')->where('ftp_url', '!=', '');
                            })
                            ->orWhereHas('links', fn (Builder $query): Builder => $query
                                ->where('kind', LandingPageLink::KIND_DOWNLOAD)
                                ->where('url', '!=', ''));
                    });
            });
    }

    /**
     * @return array{same: bool, current: list<array{id: int, value: string, bytes: string}>}
     */
    private function compareExistingSizes(Resource $resource, int $proposedBytes): array
    {
        $current = [];
        $same = false;

        foreach ($resource->sizes as $size) {
            $bytes = $this->digitalContentSizeService->forResource($size, $resource);

            if ($bytes === null) {
                continue;
            }

            $current[] = [
                'id' => $size->id,
                'value' => $size->export_string,
                'bytes' => $bytes,
            ];
            $same = $same || $bytes === (string) $proposedBytes;
        }

        return ['same' => $same, 'current' => $current];
    }

    /** @param list<string> $desiredValues */
    private function reconcile(string $assistantId, int $resourceId, string $targetType, array $desiredValues): int
    {
        $query = AssistantSuggestion::query()
            ->where('assistant_id', $assistantId)
            ->where('resource_id', $resourceId)
            ->where('target_type', $targetType);

        if ($desiredValues !== []) {
            $query->whereNotIn('suggested_value', $desiredValues);
        }

        return $query->delete();
    }

    private function removeSuggestionsForIneligibleResources(string $assistantId): int
    {
        $eligibleResourceIds = $this->candidateQuery()->select('resources.id');

        return AssistantSuggestion::query()
            ->where('assistant_id', $assistantId)
            ->whereNotIn('resource_id', $eligibleResourceIds)
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
