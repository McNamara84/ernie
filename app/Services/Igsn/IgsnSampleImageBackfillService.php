<?php

declare(strict_types=1);

namespace App\Services\Igsn;

use App\Models\Resource;
use App\Services\LegacyIgsnPortalService;
use App\Support\IgsnIdentifier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class IgsnSampleImageBackfillService
{
    public function __construct(
        private readonly LegacyIgsnPortalService $legacyPortal,
        private readonly IgsnDifMetadataExtractor $extractor,
        private readonly IgsnSampleImageUrlService $urlService,
        private readonly IgsnSampleImageStorageService $storageService,
    ) {}

    /**
     * @param  list<string>  $dois
     * @return array<string, mixed>
     */
    public function run(
        bool $apply = false,
        int $afterId = 0,
        int $limit = 0,
        int $chunk = 100,
        array $dois = [],
        bool $force = false,
    ): array {
        $chunk = max(1, min(100, $chunk));
        $limit = max(0, $limit);
        $cursor = max(0, $afterId);
        $doiFilter = $this->normalizeDoiFilter($dois);
        $stats = [
            'scanned' => 0,
            'would_store' => 0,
            'stored' => 0,
            'would_link_external' => 0,
            'linked_external' => 0,
            'unavailable' => 0,
            'unchanged' => 0,
            'no_image' => 0,
            'invalid_placeholder' => 0,
            'missing_dif' => 0,
            'unsupported_source' => 0,
            'failed' => 0,
            'records' => [],
        ];

        if ($dois !== [] && $doiFilter === []) {
            return $stats;
        }

        while ($limit === 0 || $stats['scanned'] < $limit) {
            $batchSize = $limit === 0 ? $chunk : min($chunk, $limit - $stats['scanned']);
            $resources = Resource::query()
                ->with(['igsnMetadata', 'landingPage'])
                ->whereHas('igsnMetadata')
                ->where('id', '>', $cursor)
                ->when($doiFilter !== [], fn (Builder $query): Builder => $query->whereIn('doi', $doiFilter))
                ->orderBy('id')
                ->limit($batchSize)
                ->get();

            if ($resources->isEmpty()) {
                break;
            }

            $cursor = (int) $resources->last()->id;
            $byHandle = [];
            foreach ($resources as $resource) {
                $stats['scanned']++;
                $handle = is_string($resource->doi) ? IgsnIdentifier::handleFromDoi($resource->doi) : null;
                if ($handle === null) {
                    $this->addRecord($stats, $resource, '', 'failed', 'Invalid IGSN DOI.');

                    continue;
                }
                $byHandle[$handle] = $resource;
            }

            if ($byHandle === []) {
                continue;
            }

            try {
                $documents = $this->legacyPortal->difForHandles(array_keys($byHandle));
            } catch (Throwable $exception) {
                Log::warning('IGSN sample image backfill batch failed', [
                    'handles' => array_keys($byHandle),
                    'error_class' => $exception::class,
                ]);
                foreach ($byHandle as $handle => $resource) {
                    $this->addRecord($stats, $resource, $handle, 'failed', 'Legacy DIF request failed.');
                }

                continue;
            }

            foreach ($byHandle as $handle => $resource) {
                $difXml = $documents[$handle] ?? null;
                if (! is_string($difXml)) {
                    $this->addRecord($stats, $resource, $handle, 'missing_dif', 'No DIF metadata returned.');

                    continue;
                }

                try {
                    $fields = $this->extractor->extractImageFields($difXml);
                    if ($fields === null) {
                        $this->addRecord($stats, $resource, $handle, 'failed', 'DIF metadata does not contain a readable sample.');

                        continue;
                    }

                    $fileName = trim((string) ($fields['file_name'] ?? ''));
                    if (in_array(strtoupper($fileName), ['NN', 'N/A', 'NA'], true)) {
                        $this->addRecord($stats, $resource, $handle, 'invalid_placeholder', 'Legacy image placeholder ignored.');

                        continue;
                    }

                    $resolved = $this->urlService->resolve($fields['base_url'], $fields['file_name']);
                    if ($resolved['status'] === IgsnSampleImageUrlService::STATUS_MISSING) {
                        $this->addRecord($stats, $resource, $handle, 'no_image', 'No image metadata present.');

                        continue;
                    }
                    if ($resolved['status'] === IgsnSampleImageUrlService::STATUS_UNSUPPORTED) {
                        $this->addRecord($stats, $resource, $handle, 'unsupported_source', (string) $resolved['reason']);

                        continue;
                    }

                    $this->processResolved($stats, $resource, $handle, $resolved, $apply, $force);
                } catch (Throwable $exception) {
                    $this->addRecord($stats, $resource, $handle, 'failed', $exception->getMessage());
                    Log::warning('IGSN sample image backfill record failed', [
                        'resource_id' => $resource->id,
                        'handle' => $handle,
                        'error_class' => $exception::class,
                    ]);
                }
            }
        }

        return $stats;
    }

    /**
     * @param  array<string, mixed>  $stats
     * @param  array{status: string, source_url: string|null, external_url: string|null, reason: string|null}  $resolved
     */
    private function processResolved(array &$stats, Resource $resource, string $handle, array $resolved, bool $apply, bool $force): void
    {
        $metadata = $resource->igsnMetadata;
        if ($metadata === null) {
            $this->addRecord($stats, $resource, $handle, 'failed', 'Resource has no IGSN metadata.');

            return;
        }

        $sourceChanged = $metadata->sample_image_source_url !== $resolved['source_url'];

        if ($resolved['status'] === IgsnSampleImageUrlService::STATUS_EXTERNAL) {
            $changed = $sourceChanged
                || $metadata->sample_image_external_url !== $resolved['external_url']
                || $metadata->sample_image_storage_path !== null;
            if (! $changed && ! $force) {
                $this->addRecord($stats, $resource, $handle, 'unchanged', 'External image URL is already current.');

                return;
            }
            if (! $apply) {
                $this->addRecord($stats, $resource, $handle, 'would_link_external', (string) $resolved['external_url']);

                return;
            }
        } else {
            $stored = is_string($metadata->sample_image_storage_path)
                && $metadata->sample_image_storage_path !== ''
                && Storage::disk((string) config('igsn_images.disk', 'public'))->exists($metadata->sample_image_storage_path);
            if (! $sourceChanged && $stored && ! $force) {
                $this->addRecord($stats, $resource, $handle, 'unchanged', 'Managed image is already stored.');

                return;
            }
            if (! $apply) {
                $this->addRecord($stats, $resource, $handle, 'would_store', (string) $resolved['source_url']);

                return;
            }
        }

        $previousDescriptor = [
            'sample_image_source_url' => $metadata->sample_image_source_url,
            'sample_image_external_url' => $metadata->sample_image_external_url,
            'sample_image_storage_path' => $metadata->sample_image_storage_path,
            'sample_image_mime_type' => $metadata->sample_image_mime_type,
            'sample_image_size' => $metadata->sample_image_size,
        ];

        $metadata->sample_image_source_url = $resolved['source_url'];
        if ($resolved['status'] === IgsnSampleImageUrlService::STATUS_EXTERNAL) {
            $metadata->sample_image_external_url = $resolved['external_url'];
        }
        $metadata->save();

        $result = $this->storageService->sync($metadata, $force || $sourceChanged);
        if (! in_array($result['status'], ['stored', 'external', 'unavailable', 'unchanged'], true)) {
            $metadata->forceFill($previousDescriptor)->save();
        }

        $status = match ($result['status']) {
            'stored' => 'stored',
            'external' => 'linked_external',
            'unavailable' => 'unavailable',
            'unchanged' => 'unchanged',
            'unsupported' => 'unsupported_source',
            default => 'failed',
        };
        $this->addRecord($stats, $resource, $handle, $status, $result['message']);
    }

    /** @param list<string> $dois
     * @return list<string>
     */
    private function normalizeDoiFilter(array $dois): array
    {
        $normalized = [];
        foreach ($dois as $doi) {
            $value = IgsnIdentifier::normalizeInputToDoi($doi);
            if ($value !== null) {
                $normalized[$value] = true;
            }
        }

        return array_keys($normalized);
    }

    /** @param array<string, mixed> $stats */
    private function addRecord(array &$stats, Resource $resource, string $handle, string $status, string $message): void
    {
        $stats[$status]++;
        $stats['records'][] = [
            'resource_id' => (int) $resource->id,
            'doi' => (string) $resource->doi,
            'handle' => $handle,
            'status' => $status,
            'message' => $message,
        ];
    }
}
