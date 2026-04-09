<?php

declare(strict_types=1);

namespace App\Services\OaiPmh;

use App\Models\Resource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Manages OAI-PMH set enumeration and query filtering.
 *
 * Sets are organized hierarchically:
 * - resourcetype:{TypeName} – By DataCite resource type
 * - year:{YYYY} – By publication year
 */
class OaiPmhSetService
{
    /**
     * Get all available OAI-PMH sets.
     *
     * @return list<array{spec: string, name: string}>
     */
    public function listSets(): array
    {
        $sets = [];

        // Resource type sets from published resources
        $types = Resource::query()
            ->whereHas('landingPage', fn (Builder $q) => $q->where('is_published', true))
            ->whereNotNull('doi')
            ->whereHas('resourceType')
            ->with('resourceType')
            ->get()
            ->pluck('resourceType.name')
            ->unique()
            ->filter()
            ->sort()
            ->values();

        foreach ($types as $typeName) {
            $sets[] = [
                'spec' => 'resourcetype:' . $typeName,
                'name' => $typeName,
            ];
        }

        // Publication year sets from published resources
        $years = Resource::query()
            ->whereHas('landingPage', fn (Builder $q) => $q->where('is_published', true))
            ->whereNotNull('doi')
            ->whereNotNull('publication_year')
            ->distinct()
            ->orderBy('publication_year')
            ->pluck('publication_year');

        foreach ($years as $year) {
            $sets[] = [
                'spec' => 'year:' . $year,
                'name' => 'Publication Year ' . $year,
            ];
        }

        return $sets;
    }

    /**
     * Apply a set filter to a resource query.
     *
     * @param  Builder<Resource>  $query
     * @return Builder<Resource>
     */
    public function applySetFilter(Builder $query, string $setSpec): Builder
    {
        return match (true) {
            str_starts_with($setSpec, 'resourcetype:') => $query->whereHas(
                'resourceType',
                fn (Builder $q) => $q->where('name', Str::after($setSpec, 'resourcetype:')),
            ),
            str_starts_with($setSpec, 'year:') => $query->where(
                'publication_year',
                (int) Str::after($setSpec, 'year:'),
            ),
            default => $query->whereRaw('1 = 0'), // Unknown set → no results
        };
    }

    /**
     * Determine the set specs for a given resource.
     *
     * @return list<string>
     */
    public function getSetsForResource(Resource $resource): array
    {
        $sets = [];

        $typeName = $resource->resourceType?->name;
        if ($typeName !== null && $typeName !== '') {
            $sets[] = 'resourcetype:' . $typeName;
        }

        if ($resource->publication_year !== null) {
            $sets[] = 'year:' . $resource->publication_year;
        }

        return $sets;
    }

    /**
     * Validate whether a set spec is syntactically valid.
     */
    public function isValidSetSpec(string $setSpec): bool
    {
        return str_starts_with($setSpec, 'resourcetype:')
            || str_starts_with($setSpec, 'year:');
    }
}
