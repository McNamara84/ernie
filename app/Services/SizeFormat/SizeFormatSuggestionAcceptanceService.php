<?php

declare(strict_types=1);

namespace App\Services\SizeFormat;

use App\Models\AssistantSuggestion;
use App\Models\Format;
use App\Models\Resource;
use App\Models\Size;
use App\Services\DataCiteSyncService;
use Illuminate\Support\Facades\DB;

final class SizeFormatSuggestionAcceptanceService
{
    public function __construct(
        private readonly SizeFormatSizeParserService $sizeParser,
        private readonly DigitalContentSizeService $digitalContentSizeService,
        private readonly DataCiteSyncService $dataCiteSyncService,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function accept(AssistantSuggestion $suggestion, array $input = [], bool $syncDataCite = true): array
    {
        $result = DB::transaction(function () use ($suggestion, $input): array {
            $resource = Resource::query()->lockForUpdate()->find($suggestion->resource_id);

            if (! $resource instanceof Resource || $suggestion->target_id !== $resource->id) {
                return [
                    'success' => false,
                    'message' => 'The target resource no longer exists.',
                ];
            }

            if ($suggestion->target_type === 'format') {
                return $this->acceptFormat($suggestion, $resource);
            }

            if ($suggestion->target_type === 'size') {
                return $this->acceptSize($suggestion, $resource, $input);
            }

            return [
                'success' => false,
                'message' => 'Unknown suggestion type.',
            ];
        });

        if (($result['success'] ?? false) !== true || ! isset($result['resource_id'])) {
            return $result;
        }

        if (! $syncDataCite) {
            return [
                ...$result,
                'datacite_sync_deferred' => true,
            ];
        }

        $resource = Resource::query()->find((int) $result['resource_id']);

        if (! $resource instanceof Resource) {
            return $result;
        }

        $syncResult = $this->dataCiteSyncService->syncIfRegistered($resource);

        return [
            ...$result,
            'datacite_sync' => $syncResult->toArray(),
            'datacite_sync_retry_url' => $syncResult->hasFailed()
                ? route('assistance.datacite-sync.retry', ['resource' => $resource->id])
                : null,
            'synced_dois' => $syncResult->attempted && $syncResult->success && $syncResult->doi !== null
                ? [$syncResult->doi]
                : [],
        ];
    }

    /** @return array<string, mixed> */
    private function acceptFormat(AssistantSuggestion $suggestion, Resource $resource): array
    {
        $formatValue = SizeFormatFormatNormalizerService::normalize($suggestion->suggested_value);

        if ($formatValue === '') {
            return ['success' => false, 'message' => 'The suggested format is empty.'];
        }

        $exists = Format::query()
            ->where('resource_id', $resource->id)
            ->lockForUpdate()
            ->get()
            ->contains(fn (Format $format): bool => SizeFormatFormatNormalizerService::normalize((string) $format->value) === $formatValue);

        if (! $exists) {
            Format::query()->create([
                'resource_id' => $resource->id,
                'value' => $formatValue,
            ]);
        }

        return [
            'success' => true,
            'message' => "Format '{$formatValue}' applied.",
            'resource_id' => $resource->id,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function acceptSize(AssistantSuggestion $suggestion, Resource $resource, array $input): array
    {
        $metadata = is_array($suggestion->metadata) ? $suggestion->metadata : [];
        $storedParsedSize = $metadata['proposed_size'] ?? $metadata['parsed_size'] ?? null;
        $parsedSize = is_array($storedParsedSize)
            ? [
                'numeric_value' => $storedParsedSize['numeric_value'] ?? null,
                'unit' => $storedParsedSize['unit'] ?? null,
                'type' => $storedParsedSize['type'] ?? null,
            ]
            : $this->sizeParser->parse($suggestion->suggested_value);

        if (! is_string($parsedSize['numeric_value'] ?? null) || trim($parsedSize['numeric_value']) === '') {
            return ['success' => false, 'message' => 'The suggested size is not valid.'];
        }

        if (($metadata['suggestion_kind'] ?? null) === 'size_conflict') {
            if (($input['size_conflict_resolution'] ?? null) !== 'replace') {
                return [
                    'success' => false,
                    'message' => 'Choose “Replace existing digital size” before accepting this conflict.',
                ];
            }

            $storedSnapshot = $this->storedSizeSnapshot($metadata['current_sizes'] ?? null);
            $lockedSizes = Size::query()
                ->where('resource_id', $resource->id)
                ->lockForUpdate()
                ->get();
            $currentSnapshot = [];

            foreach ($lockedSizes as $currentSize) {
                $bytes = $this->digitalContentSizeService->forResource($currentSize, $resource);

                if ($bytes !== null) {
                    $currentSnapshot[] = [
                        'id' => $currentSize->id,
                        'bytes' => $bytes,
                    ];
                }
            }

            usort($currentSnapshot, static fn (array $left, array $right): int => $left['id'] <=> $right['id']);

            if ($storedSnapshot === null || $storedSnapshot !== $currentSnapshot) {
                return [
                    'success' => false,
                    'message' => 'The existing size metadata changed. Run discovery again.',
                ];
            }

            Size::query()
                ->where('resource_id', $resource->id)
                ->whereIn('id', array_column($currentSnapshot, 'id'))
                ->delete();
        }

        $size = Size::query()->firstOrCreate([
            'resource_id' => $resource->id,
            'numeric_value' => $parsedSize['numeric_value'],
            'unit' => $parsedSize['unit'],
            'type' => $parsedSize['type'],
        ]);

        return [
            'success' => true,
            'message' => "Size '{$size->export_string}' applied.",
            'resource_id' => $resource->id,
        ];
    }

    /**
     * @return list<array{id: int, bytes: string}>|null
     */
    private function storedSizeSnapshot(mixed $storedCurrentSizes): ?array
    {
        if (! is_array($storedCurrentSizes)) {
            return null;
        }

        $snapshot = [];

        foreach ($storedCurrentSizes as $storedCurrentSize) {
            if (! is_array($storedCurrentSize)) {
                return null;
            }

            $id = $storedCurrentSize['id'] ?? null;
            $bytes = trim((string) ($storedCurrentSize['bytes'] ?? ''));

            if ((! is_int($id) && ! ctype_digit((string) $id)) || ! ctype_digit($bytes)) {
                return null;
            }

            $snapshot[] = [
                'id' => (int) $id,
                'bytes' => ltrim($bytes, '0') ?: '0',
            ];
        }

        usort($snapshot, static fn (array $left, array $right): int => $left['id'] <=> $right['id']);

        return $snapshot;
    }
}
