<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\GeoLocation;
use App\Models\Resource;
use App\Services\Legacy\LegacyCoverageGeometryParserService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SumarioPmdCoverageEnrichmentService
{
    private const CONNECTION = 'metaworks';

    private const BOUND_TOLERANCE = 0.000001;

    public function __construct(
        private readonly LegacyCoverageGeometryParserService $geometryParser,
    ) {}

    public function enrich(Resource $resource, string $doi): bool
    {
        $doi = trim($doi);

        if (! $resource->exists || $doi === '') {
            return false;
        }

        try {
            $coverages = $this->loadLegacyLineCoverages($doi);

            if ($coverages === []) {
                return false;
            }

            return DB::connection($resource->getConnectionName())
                ->transaction(function () use ($resource, $doi, $coverages): bool {
                    $dataCiteBoxes = $this->loadDataCiteBoxes($resource);

                    /** @var array<int, list<array{box: GeoLocation, coverage: array{coverage_id: int, legacy_resource_id: int}}>> $boxAssignments */
                    $boxAssignments = [];

                    foreach ($coverages as $coverage) {
                        $matchingBox = $this->findMatchingBox($dataCiteBoxes, $doi, $coverage);

                        $resource->geoLocations()->create([
                            'geo_type' => 'line',
                            'place' => $coverage['description'],
                            'polygon_points' => $coverage['points'],
                        ]);

                        if ($matchingBox !== null) {
                            $boxAssignments[$matchingBox->id][] = [
                                'box' => $matchingBox,
                                'coverage' => $coverage,
                            ];
                        }
                    }

                    foreach ($boxAssignments as $boxId => $assignments) {
                        if (count($assignments) !== 1) {
                            foreach ($assignments as $assignment) {
                                $this->logAmbiguousBoxMatch(
                                    $doi,
                                    $assignment['coverage'],
                                    [$boxId],
                                    'candidate_assigned_to_multiple_coverages',
                                );
                            }

                            continue;
                        }

                        $assignments[0]['box']->delete();
                    }

                    $resource->unsetRelation('geoLocations');

                    return true;
                });
        } catch (\Throwable $exception) {
            Log::warning('SUMARIO coverage enrichment failed; preserving DataCite geolocation metadata.', [
                'doi' => $doi,
                'resource_id' => $resource->id,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * @return list<array{
     *     coverage_id: int,
     *     legacy_resource_id: int,
     *     minlat: float|null,
     *     maxlat: float|null,
     *     minlon: float|null,
     *     maxlon: float|null,
     *     description: string|null,
     *     points: list<array{longitude: float, latitude: float}>
     * }>
     */
    private function loadLegacyLineCoverages(string $doi): array
    {
        $legacyResourceId = DB::connection(self::CONNECTION)
            ->table('resource')
            ->whereRaw('LOWER(identifier) = ?', [mb_strtolower($doi)])
            ->value('id');

        if (! is_numeric($legacyResourceId)) {
            return [];
        }

        $rows = DB::connection(self::CONNECTION)
            ->table('coverage')
            ->where('resource_id', (int) $legacyResourceId)
            ->whereNotNull('wkt')
            ->whereRaw('TRIM(wkt) <> ?', [''])
            ->orderBy('id')
            ->get([
                'id',
                'resource_id',
                'minlat',
                'maxlat',
                'minlon',
                'maxlon',
                'description',
                'wkt',
            ]);

        $coverages = [];

        foreach ($rows as $row) {
            $coverageId = is_numeric($row->id ?? null) ? (int) $row->id : 0;
            $points = $this->geometryParser->parseLine($row->wkt ?? null);

            if ($points === null) {
                Log::warning('Legacy coverage geometry could not be parsed as a line.', [
                    'doi' => $doi,
                    'legacy_resource_id' => (int) $legacyResourceId,
                    'coverage_id' => $coverageId,
                ]);

                continue;
            }

            $coverages[] = [
                'coverage_id' => $coverageId,
                'legacy_resource_id' => (int) $legacyResourceId,
                'minlat' => $this->nullableFloat($row->minlat ?? null),
                'maxlat' => $this->nullableFloat($row->maxlat ?? null),
                'minlon' => $this->nullableFloat($row->minlon ?? null),
                'maxlon' => $this->nullableFloat($row->maxlon ?? null),
                'description' => $this->filledString($row->description ?? null),
                'points' => array_map(
                    static fn (array $point): array => [
                        'longitude' => $point['lon'],
                        'latitude' => $point['lat'],
                    ],
                    $points,
                ),
            ];
        }

        return $coverages;
    }

    /**
     * @return Collection<int, GeoLocation>
     */
    private function loadDataCiteBoxes(Resource $resource): Collection
    {
        return $resource->geoLocations()
            ->where('geo_type', 'box')
            ->whereNotNull('west_bound_longitude')
            ->whereNotNull('east_bound_longitude')
            ->whereNotNull('south_bound_latitude')
            ->whereNotNull('north_bound_latitude')
            ->get();
    }

    /**
     * @param  Collection<int, GeoLocation>  $dataCiteBoxes
     * @param  array{
     *     coverage_id: int,
     *     legacy_resource_id: int,
     *     minlat: float|null,
     *     maxlat: float|null,
     *     minlon: float|null,
     *     maxlon: float|null,
     *     description: string|null,
     *     points: list<array{longitude: float, latitude: float}>
     * }  $coverage
     */
    private function findMatchingBox(Collection $dataCiteBoxes, string $doi, array $coverage): ?GeoLocation
    {
        if (! $this->hasCompleteBounds($coverage)) {
            return null;
        }

        $candidates = $dataCiteBoxes
            ->filter(fn (GeoLocation $geoLocation): bool => $this->boundsMatch($geoLocation, $coverage))
            ->values();

        if ($candidates->isEmpty()) {
            return null;
        }

        $legacyDescription = $this->normaliseDescription($coverage['description']);

        if ($candidates->count() === 1) {
            /** @var GeoLocation $candidate */
            $candidate = $candidates->first();
            $candidateDescription = $this->normaliseDescription($candidate->place);

            if (
                $legacyDescription !== ''
                && $candidateDescription !== ''
                && $legacyDescription !== $candidateDescription
            ) {
                $this->logAmbiguousBoxMatch($doi, $coverage, [$candidate->id], 'description_mismatch');

                return null;
            }

            return $candidate;
        }

        if ($legacyDescription !== '') {
            $descriptionMatches = $candidates
                ->filter(fn (GeoLocation $candidate): bool => $this->normaliseDescription($candidate->place) === $legacyDescription)
                ->values();

            if ($descriptionMatches->count() === 1) {
                /** @var GeoLocation $candidate */
                $candidate = $descriptionMatches->first();

                return $candidate;
            }
        }

        $this->logAmbiguousBoxMatch(
            $doi,
            $coverage,
            array_values($candidates->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all()),
            'multiple_candidates',
        );

        return null;
    }

    /**
     * @param  array{minlat: float|null, maxlat: float|null, minlon: float|null, maxlon: float|null}  $coverage
     */
    private function hasCompleteBounds(array $coverage): bool
    {
        return $coverage['minlat'] !== null
            && $coverage['maxlat'] !== null
            && $coverage['minlon'] !== null
            && $coverage['maxlon'] !== null;
    }

    /**
     * @param  array{minlat: float|null, maxlat: float|null, minlon: float|null, maxlon: float|null}  $coverage
     */
    private function boundsMatch(GeoLocation $geoLocation, array $coverage): bool
    {
        if (! $this->hasCompleteBounds($coverage)) {
            return false;
        }

        return $this->isNear((float) $geoLocation->south_bound_latitude, (float) $coverage['minlat'])
            && $this->isNear((float) $geoLocation->north_bound_latitude, (float) $coverage['maxlat'])
            && $this->isNear((float) $geoLocation->west_bound_longitude, (float) $coverage['minlon'])
            && $this->isNear((float) $geoLocation->east_bound_longitude, (float) $coverage['maxlon']);
    }

    private function isNear(float $actual, float $expected): bool
    {
        return abs($actual - $expected) <= self::BOUND_TOLERANCE;
    }

    /**
     * @param  array{coverage_id: int, legacy_resource_id: int}  $coverage
     * @param  list<int>  $candidateIds
     */
    private function logAmbiguousBoxMatch(string $doi, array $coverage, array $candidateIds, string $reason): void
    {
        Log::warning('Legacy coverage line did not have one safe DataCite box replacement candidate.', [
            'doi' => $doi,
            'legacy_resource_id' => $coverage['legacy_resource_id'],
            'coverage_id' => $coverage['coverage_id'],
            'candidate_geo_location_ids' => $candidateIds,
            'reason' => $reason,
        ]);
    }

    private function normaliseDescription(?string $description): string
    {
        if ($description === null) {
            return '';
        }

        $normalised = mb_strtolower(trim($description), 'UTF-8');

        return preg_replace('/\s+/u', ' ', $normalised) ?? '';
    }

    private function nullableFloat(mixed $value): ?float
    {
        if (is_string($value)) {
            $value = trim($value);
        }

        return is_numeric($value) ? (float) $value : null;
    }

    private function filledString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}
