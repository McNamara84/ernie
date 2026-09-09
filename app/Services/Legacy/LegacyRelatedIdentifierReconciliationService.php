<?php

declare(strict_types=1);

namespace App\Services\Legacy;

use App\Models\IdentifierType;
use App\Models\RelatedIdentifier;
use App\Models\RelationType;
use App\Models\Resource;
use App\Services\BotProtection\LandingPageRenderDataCacheService;
use App\Services\DoiSuggestionService;
use App\Services\LegacyResourceLookupService;
use App\Services\RelatedIdentifierImportMergeService;
use App\Services\RelatedIdentifierTypeResolverService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class LegacyRelatedIdentifierReconciliationService
{
    private const LEGACY_SOURCE = 'sumario-pmd';

    public function __construct(
        private readonly LegacyResourceLookupService $legacyLookup,
        private readonly RelatedIdentifierImportMergeService $mergeService,
        private readonly RelatedIdentifierTypeResolverService $typeResolver,
        private readonly DoiSuggestionService $doiSuggestionService,
        private readonly LandingPageRenderDataCacheService $landingPageCache,
    ) {}

    /**
     * @param  list<string>  $dois
     * @return array{
     *     resources_scanned: int,
     *     legacy_resources: int,
     *     changed: int,
     *     unchanged: int,
     *     missing_legacy: int,
     *     relations_found: int,
     *     relations_added: int,
     *     invalid_relations: int,
     *     duplicate_legacy_relations: int,
     *     cache_invalidation_failures: int,
     *     errors: int,
     *     last_scanned_resource_id: int|null,
     *     sync_resource_ids: list<int>,
     *     records: list<array{
     *         resource_id: int,
     *         doi: string,
     *         legacy_resource_id: int|null,
     *         status: string,
     *         legacy_relations: int,
     *         missing_relations: int,
     *         invalid_relations: int,
     *         duplicate_legacy_relations: int,
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
        $cursor = max(0, $afterId);
        $limit = max(0, $limit);
        $chunk = max(1, min(1000, $chunk));
        $doiFilter = $this->normalizeDoiFilter($dois);
        $stats = [
            'resources_scanned' => 0,
            'legacy_resources' => 0,
            'changed' => 0,
            'unchanged' => 0,
            'missing_legacy' => 0,
            'relations_found' => 0,
            'relations_added' => 0,
            'invalid_relations' => 0,
            'duplicate_legacy_relations' => 0,
            'cache_invalidation_failures' => 0,
            'errors' => 0,
            'last_scanned_resource_id' => null,
            'sync_resource_ids' => [],
            'records' => [],
        ];

        if ($dois !== [] && $doiFilter === []) {
            return $stats;
        }

        while ($limit === 0 || $stats['resources_scanned'] < $limit) {
            $batchSize = $limit === 0 ? $chunk : min($chunk, $limit - $stats['resources_scanned']);
            $resources = Resource::query()
                ->with(['relatedIdentifiers.identifierType', 'relatedIdentifiers.relationType', 'landingPage'])
                ->where('id', '>', $cursor)
                ->whereNotNull('doi')
                ->where('doi', '!=', '')
                ->when($doiFilter !== [], fn (Builder $query): Builder => $query->whereIn('doi', $doiFilter))
                ->orderBy('id')
                ->limit($batchSize)
                ->get();

            if ($resources->isEmpty()) {
                break;
            }

            $cursor = (int) $resources->last()->id;
            $stats['last_scanned_resource_id'] = $cursor;

            foreach ($resources as $resource) {
                $stats['resources_scanned']++;
                $doi = is_string($resource->doi) ? trim($resource->doi) : '';

                try {
                    $legacyResourceId = $resource->legacy_source === self::LEGACY_SOURCE
                        ? $resource->legacy_source_id
                        : null;
                    $legacy = $this->legacyLookup->relatedIdentifierMetadata($doi, $legacyResourceId);

                    if (! $legacy['found']) {
                        $stats['missing_legacy']++;
                        $stats['records'][] = $this->record(
                            $resource,
                            null,
                            'missing_legacy',
                            message: 'No matching SUMARIO resource was found.',
                        );

                        continue;
                    }

                    $stats['legacy_resources']++;
                    $prepared = $this->prepareLegacyRelations($legacy['relatedIdentifiers']);
                    $missing = $this->missingRelations($resource, $prepared['relations']);
                    $added = 0;

                    if ($apply && $missing !== []) {
                        $added = $this->applyMissingRelations((int) $resource->id, $missing);
                    }

                    $missingCount = count($missing);
                    $changed = $apply ? $added > 0 : $missingCount > 0;
                    $stats['relations_found'] += count($prepared['relations']);
                    $stats['invalid_relations'] += $prepared['invalid'];
                    $stats['duplicate_legacy_relations'] += $prepared['duplicates'];
                    $stats['relations_added'] += $apply ? $added : $missingCount;

                    if ($changed) {
                        $stats['changed']++;
                    } else {
                        $stats['unchanged']++;
                    }

                    if ($apply && $added > 0) {
                        $stats['sync_resource_ids'][] = (int) $resource->id;

                        if (! $this->invalidatePublishedLandingPage($resource)) {
                            $stats['cache_invalidation_failures']++;
                        }
                    }

                    $stats['records'][] = $this->record(
                        resource: $resource,
                        legacyResourceId: $legacy['legacyResourceId'],
                        status: $changed ? ($apply ? 'added' : 'would_add') : 'unchanged',
                        legacyRelations: count($prepared['relations']),
                        missingRelations: $apply ? $added : $missingCount,
                        invalidRelations: $prepared['invalid'],
                        duplicateLegacyRelations: $prepared['duplicates'],
                        message: $this->resultMessage($prepared['invalid'], $prepared['duplicates']),
                    );
                } catch (Throwable $exception) {
                    $stats['errors']++;
                    $stats['records'][] = $this->record(
                        resource: $resource,
                        legacyResourceId: $resource->legacy_source_id,
                        status: 'error',
                        message: $exception->getMessage(),
                    );
                    Log::warning('Legacy related identifier reconciliation failed.', [
                        'resource_id' => $resource->id,
                        'doi' => $doi,
                        'error' => $exception->getMessage(),
                    ]);
                }
            }
        }

        $stats['sync_resource_ids'] = array_values(array_unique($stats['sync_resource_ids']));

        return $stats;
    }

    /**
     * @param  array<int, mixed>  $records
     * @return array{relations: list<array<string, string>>, invalid: int, duplicates: int}
     */
    private function prepareLegacyRelations(array $records): array
    {
        $valid = [];
        $invalid = 0;

        foreach ($records as $record) {
            if (! is_array($record)) {
                $invalid++;

                continue;
            }

            $identifier = $this->stringValue($record['relatedIdentifier'] ?? $record['identifier'] ?? null);
            $identifierType = $this->typeResolver->resolveIdentifierType(
                $record['relatedIdentifierType'] ?? $record['identifierType'] ?? $record['identifier_type'] ?? null,
            );
            $relationType = $this->typeResolver->resolveRelationType(
                $record['relationType'] ?? $record['relation_type'] ?? null,
            );

            if ($identifier === null || $identifierType === null || $relationType === null) {
                $invalid++;

                continue;
            }

            if ($identifierType === 'DOI') {
                $identifier = preg_replace('/^doi:\s*/i', '', $identifier) ?? $identifier;

                if (! $this->doiSuggestionService->isValidDoiFormat($identifier)) {
                    $invalid++;

                    continue;
                }

                $identifier = $this->doiSuggestionService->normalizeDoi($identifier);
            }

            $valid[] = [
                ...$record,
                'relatedIdentifier' => $identifier,
                'relatedIdentifierType' => $identifierType,
                'relationType' => $relationType,
            ];
        }

        $relations = $this->mergeService->merge([], [], $valid);

        return [
            'relations' => $relations,
            'invalid' => $invalid,
            'duplicates' => max(0, count($valid) - count($relations)),
        ];
    }

    /**
     * @param  list<array<string, string>>  $legacyRelations
     * @return list<array<string, string>>
     */
    private function missingRelations(Resource $resource, array $legacyRelations): array
    {
        $existingKeys = [];

        foreach ($resource->relatedIdentifiers as $relatedIdentifier) {
            $existingKeys[$this->mergeService->normalizedKey(
                $relatedIdentifier->identifier,
                $relatedIdentifier->identifierType->slug,
                $relatedIdentifier->relationType->slug,
            )] = true;
        }

        return array_values(array_filter(
            $legacyRelations,
            fn (array $relation): bool => ! isset($existingKeys[$this->mergeService->normalizedKey(
                $relation['relatedIdentifier'],
                $relation['relatedIdentifierType'],
                $relation['relationType'],
            )]),
        ));
    }

    /**
     * @param  list<array<string, string>>  $missing
     */
    private function applyMissingRelations(int $resourceId, array $missing): int
    {
        return DB::transaction(function () use ($resourceId, $missing): int {
            $resource = Resource::query()->whereKey($resourceId)->lockForUpdate()->firstOrFail();
            $resource->load(['relatedIdentifiers.identifierType', 'relatedIdentifiers.relationType']);
            $stillMissing = $this->missingRelations($resource, $missing);
            $nextPosition = ((int) ($resource->relatedIdentifiers->max('position') ?? -1)) + 1;
            $added = 0;

            foreach ($stillMissing as $relation) {
                $identifierTypeId = IdentifierType::query()->where('slug', $relation['relatedIdentifierType'])->value('id');
                $relationTypeId = RelationType::query()->where('slug', $relation['relationType'])->value('id');

                if (! is_numeric($identifierTypeId) || ! is_numeric($relationTypeId)) {
                    throw new \RuntimeException(sprintf(
                        'Required vocabulary is missing for %s / %s.',
                        $relation['relatedIdentifierType'],
                        $relation['relationType'],
                    ));
                }

                RelatedIdentifier::query()->create([
                    'resource_id' => $resourceId,
                    'identifier' => $relation['relatedIdentifier'],
                    'identifier_type_id' => (int) $identifierTypeId,
                    'relation_type_id' => (int) $relationTypeId,
                    'relation_type_information' => $relation['relationTypeInformation'] ?? null,
                    'citation_label' => $relation['citationLabel'] ?? null,
                    'related_metadata_scheme' => $relation['relatedMetadataScheme'] ?? null,
                    'scheme_uri' => $relation['schemeUri'] ?? null,
                    'scheme_type' => $relation['schemeType'] ?? null,
                    'resource_type_general' => $relation['resourceTypeGeneral'] ?? null,
                    'position' => $nextPosition++,
                ]);
                $added++;
            }

            return $added;
        });
    }

    private function invalidatePublishedLandingPage(Resource $resource): bool
    {
        $landingPage = $resource->landingPage;

        if ($landingPage === null || ! $landingPage->isPublished()) {
            return true;
        }

        try {
            return $this->landingPageCache->forgetById((int) $landingPage->id);
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }

    /** @param list<string> $dois
     * @return list<string>
     */
    private function normalizeDoiFilter(array $dois): array
    {
        $normalized = [];

        foreach ($dois as $doi) {
            if (! $this->doiSuggestionService->isValidDoiFormat($doi)) {
                continue;
            }

            $normalized[] = $this->doiSuggestionService->normalizeDoi($doi);
        }

        return array_values(array_unique($normalized));
    }

    private function stringValue(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function resultMessage(int $invalid, int $duplicates): string
    {
        $messages = [];

        if ($invalid > 0) {
            $messages[] = "Skipped {$invalid} invalid legacy relation(s).";
        }
        if ($duplicates > 0) {
            $messages[] = "Collapsed {$duplicates} duplicate legacy relation(s).";
        }

        return implode(' ', $messages);
    }

    /**
     * @return array{resource_id: int, doi: string, legacy_resource_id: int|null, status: string, legacy_relations: int, missing_relations: int, invalid_relations: int, duplicate_legacy_relations: int, message: string}
     */
    private function record(
        Resource $resource,
        ?int $legacyResourceId,
        string $status,
        int $legacyRelations = 0,
        int $missingRelations = 0,
        int $invalidRelations = 0,
        int $duplicateLegacyRelations = 0,
        string $message = '',
    ): array {
        return [
            'resource_id' => (int) $resource->id,
            'doi' => (string) $resource->doi,
            'legacy_resource_id' => $legacyResourceId,
            'status' => $status,
            'legacy_relations' => $legacyRelations,
            'missing_relations' => $missingRelations,
            'invalid_relations' => $invalidRelations,
            'duplicate_legacy_relations' => $duplicateLegacyRelations,
            'message' => $message,
        ];
    }
}
