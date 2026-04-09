<?php

declare(strict_types=1);

namespace App\Services\OaiPmh;

use App\Models\Resource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
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

        // Resource type sets via SQL join + distinct (avoids loading all models)
        $types = DB::table('resources')
            ->join('resource_types', 'resources.resource_type_id', '=', 'resource_types.id')
            ->whereExists(fn ($q) => $q->select(DB::raw(1))
                ->from('landing_pages')
                ->whereColumn('landing_pages.resource_id', 'resources.id')
                ->where('landing_pages.is_published', true))
            ->whereNotNull('resources.doi')
            ->select('resource_types.name')
            ->distinct()
            ->orderBy('resource_types.name')
            ->pluck('name');

        foreach ($types as $typeName) {
            $sets[] = [
                'spec' => 'resourcetype:' . $typeName,
                'name' => $typeName,
            ];
        }

        // Publication year sets via SQL distinct
        $years = DB::table('resources')
            ->whereExists(fn ($q) => $q->select(DB::raw(1))
                ->from('landing_pages')
                ->whereColumn('landing_pages.resource_id', 'resources.id')
                ->where('landing_pages.is_published', true))
            ->whereNotNull('resources.doi')
            ->whereNotNull('resources.publication_year')
            ->select('resources.publication_year')
            ->distinct()
            ->orderBy('resources.publication_year')
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
     *
     * - resourcetype:{non-empty alphanumeric/space value}
     * - year:{4-digit year}
     */
    public function isValidSetSpec(string $setSpec): bool
    {
        return (bool) preg_match('/^resourcetype:[A-Za-z0-9 ]+$/', $setSpec)
            || (bool) preg_match('/^year:\d{4}$/', $setSpec);
    }
}
