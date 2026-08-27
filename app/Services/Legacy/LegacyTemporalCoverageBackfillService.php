<?php

declare(strict_types=1);

namespace App\Services\Legacy;

use App\Models\GeoLocation;
use App\Models\OldDataset;
use App\Models\Resource;
use App\Services\BotProtection\LandingPageRenderDataCacheService;
use App\Services\DoiSuggestionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final class LegacyTemporalCoverageBackfillService
{
    private const LEGACY_SOURCE = 'sumario-pmd';

    public function __construct(
        private readonly DoiSuggestionService $doiSuggestionService,
        private readonly LandingPageRenderDataCacheService $landingPageCache,
    ) {}

    /**
     * @param  list<string>  $dois
     * @param  list<int>  $legacyIds
     * @return array{
     *     scanned: int,
     *     changed: int,
     *     unchanged: int,
     *     no_temporal: int,
     *     missing_legacy: int,
     *     manual_review: int,
     *     errors: int,
     *     coverages_updated: int,
     *     coverages_created: int,
     *     records: list<array{
     *         resource_id: int,
     *         doi: string,
     *         legacy_resource_id: int|null,
     *         match_method: string,
     *         status: string,
     *         temporal_coverages: int,
     *         coverages_updated: int,
     *         coverages_created: int,
     *         warnings: int,
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
        array $legacyIds = [],
        bool $matchByDoi = false,
    ): array {
        $cursor = max(0, $afterId);
        $limit = max(0, $limit);
        $chunk = max(1, min(1000, $chunk));
        $doiFilter = $this->normalizeDoiFilter($dois);
        $legacyIdFilter = array_values(array_unique(array_filter(
            array_map('intval', $legacyIds),
            static fn (int $id): bool => $id > 0,
        )));
        $stats = [
            'scanned' => 0,
            'changed' => 0,
            'unchanged' => 0,
            'no_temporal' => 0,
            'missing_legacy' => 0,
            'manual_review' => 0,
            'errors' => 0,
            'coverages_updated' => 0,
            'coverages_created' => 0,
            'records' => [],
        ];

        if (($dois !== [] && $doiFilter === []) || ($legacyIds !== [] && $legacyIdFilter === [])) {
            return $stats;
        }

        while ($limit === 0 || $stats['scanned'] < $limit) {
            $batchSize = $limit === 0 ? $chunk : min($chunk, $limit - $stats['scanned']);
            $resources = Resource::query()
                ->with(['geoLocations', 'landingPage'])
                ->where('id', '>', $cursor)
                ->when(
                    ! $matchByDoi,
                    fn (Builder $query): Builder => $query
                        ->where('legacy_source', self::LEGACY_SOURCE)
                        ->whereNotNull('legacy_source_id'),
                    fn (Builder $query): Builder => $query->where(function (Builder $scope): void {
                        $scope
                            ->where(function (Builder $linked): void {
                                $linked
                                    ->where('legacy_source', self::LEGACY_SOURCE)
                                    ->whereNotNull('legacy_source_id');
                            })
                            ->orWhereNotNull('doi');
                    }),
                )
                ->when($doiFilter !== [], fn (Builder $query): Builder => $query->whereIn('doi', $doiFilter))
                ->when($legacyIdFilter !== [], fn (Builder $query): Builder => $query->whereIn('legacy_source_id', $legacyIdFilter))
                ->orderBy('id')
                ->limit($batchSize)
                ->get();

            if ($resources->isEmpty()) {
                break;
            }

            $cursor = (int) $resources->last()->id;

            foreach ($resources as $resource) {
                $stats['scanned']++;

                try {
                    [$oldDataset, $matchMethod] = $this->resolveLegacyResource($resource, $matchByDoi);

                    if ($oldDataset === null) {
                        $stats['missing_legacy']++;
                        $stats['records'][] = $this->record(
                            resource: $resource,
                            legacyResourceId: null,
                            matchMethod: $matchMethod,
                            status: 'missing_legacy',
                            message: 'No matching SUMARIO resource was found.',
                        );

                        continue;
                    }

                    $result = $this->backfillResource($resource, $oldDataset, $apply);
                    $changed = $result['coverages_updated'] > 0 || $result['coverages_created'] > 0;

                    if ($result['temporal_coverages'] === 0) {
                        $stats['no_temporal']++;
                        $status = 'no_temporal';
                    } elseif ($changed) {
                        $stats['changed']++;
                        $status = $apply ? 'updated' : 'would_update';
                    } else {
                        $stats['unchanged']++;
                        $status = 'unchanged';
                    }

                    if ($result['warnings'] !== []) {
                        $stats['manual_review']++;
                        $status = match ($status) {
                            'updated' => 'updated_with_warnings',
                            'would_update' => 'would_update_with_warnings',
                            'unchanged' => 'manual_review',
                            default => $status,
                        };
                    }

                    $stats['coverages_updated'] += $result['coverages_updated'];
                    $stats['coverages_created'] += $result['coverages_created'];
                    $stats['records'][] = $this->record(
                        resource: $resource,
                        legacyResourceId: (int) $oldDataset->id,
                        matchMethod: $matchMethod,
                        status: $status,
                        temporalCoverages: $result['temporal_coverages'],
                        coveragesUpdated: $result['coverages_updated'],
                        coveragesCreated: $result['coverages_created'],
                        warnings: count($result['warnings']),
                        message: implode(' ', $result['warnings']),
                    );
                } catch (\Throwable $exception) {
                    $stats['errors']++;
                    $stats['records'][] = $this->record(
                        resource: $resource,
                        legacyResourceId: $resource->legacy_source_id,
                        matchMethod: $resource->legacy_source_id !== null ? 'legacy_source_id' : 'doi',
                        status: 'error',
                        message: $exception->getMessage(),
                    );
                    Log::warning('Legacy temporal coverage backfill failed', [
                        'resource_id' => $resource->id,
                        'legacy_resource_id' => $resource->legacy_source_id,
                        'error' => $exception->getMessage(),
                    ]);
                }
            }
        }

        return $stats;
    }

    /**
     * @return array{0: OldDataset|null, 1: string}
     */
    private function resolveLegacyResource(Resource $resource, bool $matchByDoi): array
    {
        if ($resource->legacy_source === self::LEGACY_SOURCE && $resource->legacy_source_id !== null) {
            return [OldDataset::query()->find($resource->legacy_source_id), 'legacy_source_id'];
        }

        if (! $matchByDoi || ! is_string($resource->doi) || trim($resource->doi) === '') {
            return [null, 'none'];
        }

        $matches = OldDataset::query()
            ->whereRaw('LOWER(identifier) = ?', [mb_strtolower(trim($resource->doi))])
            ->orderBy('id')
            ->limit(2)
            ->get();

        if ($matches->count() > 1) {
            throw new RuntimeException('Multiple SUMARIO resources have the same DOI; no automatic match is safe.');
        }

        return [$matches->first(), 'doi'];
    }

    /**
     * @return array{temporal_coverages: int, coverages_updated: int, coverages_created: int, warnings: list<string>}
     */
    private function backfillResource(Resource $resource, OldDataset $oldDataset, bool $apply): array
    {
        $legacyCoverages = array_values($oldDataset->getCoverages());
        $locations = $resource->geoLocations->values();
        $usedLocationIds = [];
        $updates = [];
        $creates = [];
        $warnings = [];
        $temporalCoverages = 0;

        foreach ($legacyCoverages as $position => $coverage) {
            $incoming = $this->incomingTemporalValues($coverage);
            if (! $this->hasAnyValue($incoming)) {
                continue;
            }

            $temporalCoverages++;
            $match = $this->findMatchingLocation(
                coverage: $coverage,
                position: $position,
                legacyCoverageCount: count($legacyCoverages),
                locations: array_values($locations->all()),
                usedLocationIds: $usedLocationIds,
            );

            if ($match['location'] === null) {
                if ($match['reason'] === 'no_spatial_identity') {
                    $creates[] = [
                        'resource_id' => (int) $resource->id,
                        'place' => $this->nullableString($coverage['description'] ?? null),
                        ...$incoming,
                        'position' => $position,
                    ];

                    continue;
                }

                $warnings[] = sprintf(
                    'Legacy coverage #%d was not changed: %s.',
                    $position + 1,
                    $match['reason'] === 'ambiguous'
                        ? 'multiple existing GeoLocations matched'
                        : 'no existing GeoLocation matched its spatial data',
                );

                continue;
            }

            $location = $match['location'];
            $usedLocationIds[(int) $location->id] = true;
            $merge = $this->mergeTemporalValues($location, $incoming);

            if ($merge['conflicts'] !== []) {
                $warnings[] = sprintf(
                    'Legacy coverage #%d was not changed because existing values differ in: %s.',
                    $position + 1,
                    implode(', ', $merge['conflicts']),
                );

                continue;
            }

            if ($merge['updates'] !== []) {
                $updates[] = ['location' => $location, 'values' => $merge['updates']];
            }
        }

        if ($apply && ($updates !== [] || $creates !== [])) {
            DB::connection($resource->getConnectionName())->transaction(function () use ($updates, $creates): void {
                foreach ($updates as $update) {
                    $update['location']->fill($update['values'])->save();
                }

                foreach ($creates as $attributes) {
                    GeoLocation::query()->create($attributes);
                }
            });

            $landingPage = $resource->landingPage;
            if ($landingPage !== null && $landingPage->isPublished()) {
                $this->landingPageCache->forgetById((int) $landingPage->id);
            }
        }

        return [
            'temporal_coverages' => $temporalCoverages,
            'coverages_updated' => count($updates),
            'coverages_created' => count($creates),
            'warnings' => $warnings,
        ];
    }

    /**
     * @param  array<string, mixed>  $coverage
     * @param  list<GeoLocation>  $locations
     * @param  array<int, true>  $usedLocationIds
     * @return array{location: GeoLocation|null, reason: string}
     */
    private function findMatchingLocation(
        array $coverage,
        int $position,
        int $legacyCoverageCount,
        array $locations,
        array $usedLocationIds,
    ): array {
        $available = array_values(array_filter(
            $locations,
            static fn (GeoLocation $location): bool => ! isset($usedLocationIds[(int) $location->id]),
        ));
        $sourceSignature = $this->coverageSpatialSignature($coverage);
        $sourceDescription = $this->normalizeDescription($coverage['description'] ?? null);

        if ($sourceSignature !== null) {
            $spatialMatches = array_values(array_filter(
                $available,
                fn (GeoLocation $location): bool => $this->locationSpatialSignature($location) === $sourceSignature,
            ));

            if (count($spatialMatches) === 1) {
                return ['location' => $spatialMatches[0], 'reason' => 'spatial'];
            }

            if (count($spatialMatches) > 1) {
                $descriptionMatches = $this->descriptionMatches($spatialMatches, $sourceDescription);
                if (count($descriptionMatches) === 1) {
                    return ['location' => $descriptionMatches[0], 'reason' => 'spatial_description'];
                }

                $positional = $this->positionalCandidate($locations, $position, $legacyCoverageCount);
                if ($positional !== null && in_array($positional, $spatialMatches, true)) {
                    return ['location' => $positional, 'reason' => 'spatial_position'];
                }

                return ['location' => null, 'reason' => 'ambiguous'];
            }

            // Older imports may have stored a legacy line as its bounding box. A
            // one-to-one position plus the same description is the only safe fallback.
            $positional = $this->positionalCandidate($locations, $position, $legacyCoverageCount);
            if (
                $positional !== null
                && ! isset($usedLocationIds[(int) $positional->id])
                && $sourceDescription !== ''
                && $this->normalizeDescription($positional->place) === $sourceDescription
            ) {
                return ['location' => $positional, 'reason' => 'description_position'];
            }

            return ['location' => null, 'reason' => 'no_match'];
        }

        if ($sourceDescription !== '') {
            $descriptionMatches = array_values(array_filter(
                $available,
                fn (GeoLocation $location): bool => $this->locationSpatialSignature($location) === null
                    && $this->normalizeDescription($location->place) === $sourceDescription,
            ));

            if (count($descriptionMatches) === 1) {
                return ['location' => $descriptionMatches[0], 'reason' => 'description'];
            }

            if (count($descriptionMatches) > 1) {
                $positional = $this->positionalCandidate($locations, $position, $legacyCoverageCount);
                if ($positional !== null && in_array($positional, $descriptionMatches, true)) {
                    return ['location' => $positional, 'reason' => 'description_position'];
                }

                return ['location' => null, 'reason' => 'ambiguous'];
            }
        }

        return ['location' => null, 'reason' => 'no_spatial_identity'];
    }

    /**
     * @param  list<GeoLocation>  $locations
     */
    private function positionalCandidate(array $locations, int $position, int $legacyCoverageCount): ?GeoLocation
    {
        if (count($locations) !== $legacyCoverageCount) {
            return null;
        }

        return $locations[$position] ?? null;
    }

    /**
     * @param  list<GeoLocation>  $locations
     * @return list<GeoLocation>
     */
    private function descriptionMatches(array $locations, string $description): array
    {
        if ($description === '') {
            return [];
        }

        return array_values(array_filter(
            $locations,
            fn (GeoLocation $location): bool => $this->normalizeDescription($location->place) === $description,
        ));
    }

    /**
     * @param  array<string, mixed>  $coverage
     * @return array{start_date: string|null, end_date: string|null, temporal_mode: string|null, start_time: string|null, end_time: string|null, timezone: string|null}
     */
    private function incomingTemporalValues(array $coverage): array
    {
        return [
            'start_date' => $this->nullableString($coverage['startDate'] ?? null),
            'end_date' => $this->nullableString($coverage['endDate'] ?? null),
            'temporal_mode' => $this->nullableString($coverage['temporalMode'] ?? null),
            'start_time' => $this->nullableString($coverage['startTime'] ?? null),
            'end_time' => $this->nullableString($coverage['endTime'] ?? null),
            'timezone' => $this->nullableString($coverage['timezone'] ?? null),
        ];
    }

    /**
     * @param  array{start_date: string|null, end_date: string|null, temporal_mode: string|null, start_time: string|null, end_time: string|null, timezone: string|null}  $incoming
     * @return array{updates: array<string, string>, conflicts: list<string>}
     */
    private function mergeTemporalValues(GeoLocation $location, array $incoming): array
    {
        $current = [
            'start_date' => $this->nullableString($location->start_date),
            'end_date' => $this->nullableString($location->end_date),
            'temporal_mode' => $this->nullableString($location->temporal_mode),
            'start_time' => $this->nullableString($location->start_time),
            'end_time' => $this->nullableString($location->end_time),
            'timezone' => $this->nullableString($location->timezone),
        ];
        $updates = [];
        $conflicts = [];

        foreach ($incoming as $field => $value) {
            if ($value === null) {
                continue;
            }

            if ($current[$field] === null) {
                $updates[$field] = $value;

                continue;
            }

            if ($this->canonicalTemporalValue($field, $current[$field]) !== $this->canonicalTemporalValue($field, $value)) {
                $conflicts[] = $field;
            }
        }

        return ['updates' => $updates, 'conflicts' => $conflicts];
    }

    private function canonicalTemporalValue(string $field, string $value): string
    {
        if (in_array($field, ['start_time', 'end_time'], true) && preg_match('/^(\d{2}:\d{2}):00$/D', $value, $matches) === 1) {
            return $matches[1];
        }

        if ($field === 'timezone' && in_array(strtoupper($value), ['Z', 'UTC', 'GMT'], true)) {
            return 'UTC';
        }

        return $value;
    }

    /** @param array<string, string|null> $values */
    private function hasAnyValue(array $values): bool
    {
        return array_filter($values, static fn (?string $value): bool => $value !== null) !== [];
    }

    /** @param array<string, mixed> $coverage */
    private function coverageSpatialSignature(array $coverage): ?string
    {
        $type = is_string($coverage['type'] ?? null) ? $coverage['type'] : '';

        return match ($type) {
            'point' => $this->coordinateSignature('point', [
                $coverage['latMin'] ?? null,
                $coverage['lonMin'] ?? null,
            ]),
            'box' => $this->coordinateSignature('box', [
                $coverage['latMin'] ?? null,
                $coverage['latMax'] ?? null,
                $coverage['lonMin'] ?? null,
                $coverage['lonMax'] ?? null,
            ]),
            'polygon', 'line' => $this->pointsSignature($type, $coverage['polygonPoints'] ?? null),
            default => null,
        };
    }

    private function locationSpatialSignature(GeoLocation $location): ?string
    {
        if ($location->geo_type === 'line') {
            return $this->pointsSignature('line', $location->polygon_points);
        }

        if ($location->hasPolygon()) {
            return $this->pointsSignature('polygon', $location->polygon_points);
        }

        if ($location->hasBox()) {
            return $this->coordinateSignature('box', [
                $location->south_bound_latitude,
                $location->north_bound_latitude,
                $location->west_bound_longitude,
                $location->east_bound_longitude,
            ]);
        }

        if ($location->hasPoint()) {
            return $this->coordinateSignature('point', [
                $location->point_latitude,
                $location->point_longitude,
            ]);
        }

        return null;
    }

    /** @param list<mixed> $coordinates */
    private function coordinateSignature(string $type, array $coordinates): ?string
    {
        if (array_filter($coordinates, 'is_numeric') !== $coordinates) {
            return null;
        }

        return $type.':'.implode(',', array_map(
            static fn (mixed $coordinate): string => number_format((float) $coordinate, 8, '.', ''),
            $coordinates,
        ));
    }

    private function pointsSignature(string $type, mixed $points): ?string
    {
        if (! is_array($points) || $points === []) {
            return null;
        }

        $coordinates = [];
        foreach ($points as $point) {
            if (! is_array($point)) {
                return null;
            }

            $latitude = $point['latitude'] ?? $point['lat'] ?? null;
            $longitude = $point['longitude'] ?? $point['lon'] ?? null;
            if (! is_numeric($latitude) || ! is_numeric($longitude)) {
                return null;
            }

            $coordinates[] = number_format((float) $latitude, 8, '.', '').','.number_format((float) $longitude, 8, '.', '');
        }

        return $type.':'.implode(';', $coordinates);
    }

    private function normalizeDescription(mixed $value): string
    {
        if (! is_string($value)) {
            return '';
        }

        return mb_strtolower(trim((string) preg_replace('/\s+/', ' ', $value)));
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    /**
     * @param  list<string>  $dois
     * @return list<string>
     */
    private function normalizeDoiFilter(array $dois): array
    {
        $normalized = [];
        foreach ($dois as $doi) {
            $value = $this->doiSuggestionService->normalizeDoi($doi);
            if ($value !== '') {
                $normalized[$value] = true;
            }
        }

        return array_keys($normalized);
    }

    /**
     * @return array{resource_id: int, doi: string, legacy_resource_id: int|null, match_method: string, status: string, temporal_coverages: int, coverages_updated: int, coverages_created: int, warnings: int, message: string}
     */
    private function record(
        Resource $resource,
        ?int $legacyResourceId,
        string $matchMethod,
        string $status,
        int $temporalCoverages = 0,
        int $coveragesUpdated = 0,
        int $coveragesCreated = 0,
        int $warnings = 0,
        string $message = '',
    ): array {
        return [
            'resource_id' => (int) $resource->id,
            'doi' => (string) $resource->doi,
            'legacy_resource_id' => $legacyResourceId,
            'match_method' => $matchMethod,
            'status' => $status,
            'temporal_coverages' => $temporalCoverages,
            'coverages_updated' => $coveragesUpdated,
            'coverages_created' => $coveragesCreated,
            'warnings' => $warnings,
            'message' => $message,
        ];
    }
}
