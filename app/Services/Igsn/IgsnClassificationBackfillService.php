<?php

declare(strict_types=1);

namespace App\Services\Igsn;

use App\Enums\Igsn\IgsnClassificationType;
use App\Models\IgsnClassification;
use App\Models\Resource;
use App\Services\BotProtection\LandingPageRenderDataCacheService;
use App\Services\LegacyIgsnPortalService;
use App\Support\IgsnIdentifier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class IgsnClassificationBackfillService
{
    public function __construct(
        private readonly LegacyIgsnPortalService $legacyPortal,
        private readonly IgsnDifMetadataExtractor $extractor,
        private readonly LandingPageRenderDataCacheService $landingPageCache,
    ) {}

    /**
     * @param  list<string>  $dois
     * @return array{
     *     scanned: int,
     *     changed: int,
     *     unchanged: int,
     *     inserted: int,
     *     types_filled: int,
     *     missing_dif: int,
     *     rejected: int,
     *     conflicts: int,
     *     errors: int,
     *     records: list<array{
     *         resource_id: int,
     *         doi: string,
     *         handle: string,
     *         status: string,
     *         existing_values: string,
     *         source_values: string,
     *         inserted_values: string,
     *         types_filled: string,
     *         rejected_values: string,
     *         conflicts: string,
     *         message: string
     *     }>
     * }
     */
    public function run(
        bool $apply = false,
        int $afterId = 0,
        int $limit = 0,
        int $chunk = 100,
        array $dois = [],
    ): array {
        $chunk = max(1, min(100, $chunk));
        $limit = max(0, $limit);
        $cursor = max(0, $afterId);
        $doiFilter = $this->normalizeDoiFilter($dois);
        $stats = [
            'scanned' => 0,
            'changed' => 0,
            'unchanged' => 0,
            'inserted' => 0,
            'types_filled' => 0,
            'missing_dif' => 0,
            'rejected' => 0,
            'conflicts' => 0,
            'errors' => 0,
            'records' => [],
        ];

        while ($limit === 0 || $stats['scanned'] < $limit) {
            $batchSize = $limit === 0 ? $chunk : min($chunk, $limit - $stats['scanned']);
            $resources = Resource::query()
                ->with(['igsnMetadata', 'landingPage'])
                ->when(! $apply, fn (Builder $query): Builder => $query->with('igsnClassifications'))
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
                    $stats['errors']++;
                    $stats['records'][] = $this->emptyRecord($resource, '', 'error', 'Invalid IGSN DOI.');

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
                Log::warning('IGSN classification backfill batch failed', [
                    'handles' => array_keys($byHandle),
                    'error_class' => $exception::class,
                ]);

                foreach ($byHandle as $handle => $resource) {
                    $stats['errors']++;
                    $stats['records'][] = $this->emptyRecord(
                        $resource,
                        $handle,
                        'error',
                        'Legacy DIF request failed.',
                    );
                }

                continue;
            }

            foreach ($byHandle as $handle => $resource) {
                $difXml = $documents[$handle] ?? null;
                if (! is_string($difXml)) {
                    $stats['missing_dif']++;
                    $stats['records'][] = $this->emptyRecord(
                        $resource,
                        $handle,
                        'missing_dif',
                        'No DIF metadata returned.',
                    );

                    continue;
                }

                try {
                    $fields = $this->extractor->extractClassificationFields($difXml);
                    if ($fields === null) {
                        throw new RuntimeException('DIF metadata does not contain a readable sample.');
                    }

                    $result = $this->updateResource($resource, $fields, $apply);
                    $stats[$result['changed'] ? 'changed' : 'unchanged']++;
                    $stats['inserted'] += count($result['inserted_values']);
                    $stats['types_filled'] += count($result['types_filled']);
                    $stats['rejected'] += count($result['rejected_values']);
                    $stats['conflicts'] += count($result['conflicts']);
                    $stats['records'][] = [
                        'resource_id' => (int) $resource->id,
                        'doi' => (string) $resource->doi,
                        'handle' => $handle,
                        'status' => $result['changed'] ? ($apply ? 'updated' : 'would_update') : 'unchanged',
                        'existing_values' => implode(' | ', $result['existing_values']),
                        'source_values' => implode(' | ', $result['source_values']),
                        'inserted_values' => implode(' | ', $result['inserted_values']),
                        'types_filled' => implode(' | ', $result['types_filled']),
                        'rejected_values' => implode(' | ', $result['rejected_values']),
                        'conflicts' => implode(' | ', $result['conflicts']),
                        'message' => '',
                    ];
                } catch (Throwable $exception) {
                    $stats['errors']++;
                    $stats['records'][] = $this->emptyRecord(
                        $resource,
                        $handle,
                        'error',
                        $exception->getMessage(),
                    );
                    Log::warning('IGSN classification backfill record failed', [
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
     * @param  array{
     *     items: list<array{value: string, classification_type: IgsnClassificationType|null}>,
     *     rejected: list<array{value: string, material: string|null, sample_index: int}>
     * }  $fields
     * @return array{
     *     changed: bool,
     *     existing_values: list<string>,
     *     source_values: list<string>,
     *     inserted_values: list<string>,
     *     types_filled: list<string>,
     *     rejected_values: list<string>,
     *     conflicts: list<string>
     * }
     */
    private function updateResource(Resource $resource, array $fields, bool $apply): array
    {
        if ($apply) {
            $result = $this->applyResourceUpdate((int) $resource->id, $fields);

            if ($result['changed']) {
                $resource->load('landingPage');
                $landingPage = $resource->landingPage;
                if ($landingPage !== null && $landingPage->isPublished()) {
                    $this->landingPageCache->forgetById((int) $landingPage->id);
                }
            }

            return $result;
        }

        $existing = $resource->igsnClassifications->sortBy('position')->values();
        $existingByValue = [];
        foreach ($existing as $classification) {
            $existingByValue[$this->valueKey($classification->value)] ??= $classification;
        }

        $toInsert = [];
        $toFillType = [];
        $conflicts = [];

        foreach ($fields['items'] as $item) {
            $key = $this->valueKey($item['value']);
            $classification = $existingByValue[$key] ?? null;
            if (! $classification instanceof IgsnClassification) {
                $toInsert[] = $item;

                continue;
            }

            if ($item['classification_type'] === null) {
                continue;
            }

            if ($classification->classification_type === null) {
                $toFillType[] = [
                    'classification' => $classification,
                    'classification_type' => $item['classification_type'],
                ];

                continue;
            }

            if ($classification->classification_type !== $item['classification_type']) {
                $conflicts[] = sprintf(
                    '%s (%s in database, %s in source)',
                    $classification->value,
                    $classification->classification_type->value,
                    $item['classification_type']->value,
                );
            }
        }

        $changed = $toInsert !== [] || $toFillType !== [];

        return [
            'changed' => $changed,
            'existing_values' => array_values($existing
                ->map(static fn (IgsnClassification $classification): string => $classification->value)
                ->all()),
            'source_values' => array_column($fields['items'], 'value'),
            'inserted_values' => array_column($toInsert, 'value'),
            'types_filled' => array_map(
                static fn (array $update): string => $update['classification']->value,
                $toFillType,
            ),
            'rejected_values' => array_map(
                static fn (array $rejected): string => sprintf(
                    '%s (material: %s, sample: %d)',
                    $rejected['value'],
                    $rejected['material'] ?? 'none',
                    $rejected['sample_index'],
                ),
                $fields['rejected'],
            ),
            'conflicts' => $conflicts,
        ];
    }

    /**
     * @param  array{
     *     items: list<array{value: string, classification_type: IgsnClassificationType|null}>,
     *     rejected: list<array{value: string, material: string|null, sample_index: int}>
     * }  $fields
     * @return array{
     *     changed: bool,
     *     existing_values: list<string>,
     *     source_values: list<string>,
     *     inserted_values: list<string>,
     *     types_filled: list<string>,
     *     rejected_values: list<string>,
     *     conflicts: list<string>
     * }
     */
    private function applyResourceUpdate(int $resourceId, array $fields): array
    {
        return DB::transaction(function () use ($resourceId, $fields): array {
            Resource::query()->whereKey($resourceId)->lockForUpdate()->firstOrFail(['id']);

            $existing = IgsnClassification::query()
                ->where('resource_id', $resourceId)
                ->orderBy('position')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $existingByValue = [];
            foreach ($existing as $classification) {
                $existingByValue[$this->valueKey($classification->value)] ??= $classification;
            }

            $existingValues = array_values($existing
                ->map(static fn (IgsnClassification $classification): string => $classification->value)
                ->all());
            $insertedValues = [];
            $typesFilled = [];
            $conflicts = [];
            $maximum = $existing->max('position');
            $nextPosition = $maximum === null ? 0 : ((int) $maximum) + 1;

            foreach ($fields['items'] as $item) {
                $key = $this->valueKey($item['value']);
                $classification = $existingByValue[$key] ?? null;

                if (! $classification instanceof IgsnClassification) {
                    $timestamp = now();
                    $inserted = IgsnClassification::query()->insertOrIgnore([
                        'resource_id' => $resourceId,
                        'value' => $item['value'],
                        'classification_type' => $item['classification_type']?->value,
                        'position' => $nextPosition,
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ]);

                    if ($inserted === 1) {
                        $insertedValues[] = $item['value'];
                        $nextPosition++;

                        continue;
                    }

                    $classification = IgsnClassification::query()
                        ->where('resource_id', $resourceId)
                        ->where('value', $item['value'])
                        ->lockForUpdate()
                        ->first();
                    if (! $classification instanceof IgsnClassification) {
                        throw new RuntimeException('Unable to insert the IGSN classification atomically.');
                    }

                    $existingByValue[$key] = $classification;
                    $existingValues[] = $classification->value;
                }

                $sourceType = $item['classification_type'];
                if ($sourceType === null) {
                    continue;
                }

                if ($classification->classification_type === null) {
                    $updated = IgsnClassification::query()
                        ->whereKey($classification->id)
                        ->whereNull('classification_type')
                        ->update([
                            'classification_type' => $sourceType->value,
                            'updated_at' => now(),
                        ]);

                    if ($updated === 1) {
                        $classification->classification_type = $sourceType;
                        $typesFilled[] = $classification->value;

                        continue;
                    }

                    $classification->refresh();
                }

                $databaseType = $classification->classification_type;
                if ($databaseType === null) {
                    throw new RuntimeException('Unable to fill the IGSN classification type atomically.');
                }

                if ($databaseType !== $sourceType) {
                    $conflicts[] = sprintf(
                        '%s (%s in database, %s in source)',
                        $classification->value,
                        $databaseType->value,
                        $sourceType->value,
                    );
                }
            }

            return [
                'changed' => $insertedValues !== [] || $typesFilled !== [],
                'existing_values' => $existingValues,
                'source_values' => array_column($fields['items'], 'value'),
                'inserted_values' => $insertedValues,
                'types_filled' => $typesFilled,
                'rejected_values' => array_map(
                    static fn (array $rejected): string => sprintf(
                        '%s (material: %s, sample: %d)',
                        $rejected['value'],
                        $rejected['material'] ?? 'none',
                        $rejected['sample_index'],
                    ),
                    $fields['rejected'],
                ),
                'conflicts' => $conflicts,
            ];
        });
    }

    /** @param list<string> $dois
     * @return list<string>
     */
    private function normalizeDoiFilter(array $dois): array
    {
        $normalized = [];
        foreach ($dois as $doi) {
            $value = IgsnIdentifier::normalizeInputToDoi($doi);
            if ($value === null) {
                throw new InvalidArgumentException(sprintf(
                    'Invalid IGSN DOI or handle filter: "%s".',
                    $doi,
                ));
            }

            $normalized[$value] = true;
        }

        return array_keys($normalized);
    }

    private function valueKey(string $value): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value));
    }

    /**
     * @return array{
     *     resource_id: int,
     *     doi: string,
     *     handle: string,
     *     status: string,
     *     existing_values: string,
     *     source_values: string,
     *     inserted_values: string,
     *     types_filled: string,
     *     rejected_values: string,
     *     conflicts: string,
     *     message: string
     * }
     */
    private function emptyRecord(Resource $resource, string $handle, string $status, string $message): array
    {
        return [
            'resource_id' => (int) $resource->id,
            'doi' => (string) $resource->doi,
            'handle' => $handle,
            'status' => $status,
            'existing_values' => '',
            'source_values' => '',
            'inserted_values' => '',
            'types_filled' => '',
            'rejected_values' => '',
            'conflicts' => '',
            'message' => $message,
        ];
    }
}
