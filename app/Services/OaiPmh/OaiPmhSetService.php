<?php

declare(strict_types=1);

namespace App\Services\OaiPmh;

use App\Models\OaiPmhDeletedRecord;
use App\Models\OaiPmhFormatRecord;
use App\Models\Resource;
use App\Models\Subject;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Manages OAI-PMH set enumeration and query filtering.
 *
 * Sets are organized hierarchically:
 * - resourcetype:{slug} – By resource type slug (lowercase, e.g. dataset, physical-object)
 * - year:{YYYY} – By publication year
 * - epos-msl – By the free project keywords EPOS or MSL
 * - icgem – Models whose own DOI contains 10.5880/icgem
 */
class OaiPmhSetService
{
    public function __construct(
        private readonly EposMslSetService $projectSet,
        private readonly IcgemSetService $icgemSet,
    ) {}

    /** @return array{spec: string, name: string, description: string} */
    public function projectSetDefinition(): array
    {
        return $this->projectSet->definition();
    }

    /** @return array{spec: string, name: string, description: string} */
    public function icgemSetDefinition(): array
    {
        return $this->icgemSet->definition();
    }

    /**
     * Get all available OAI-PMH sets.
     *
     * @return list<array{spec: string, name: string, description?: string}>
     */
    public function listSets(): array
    {
        $sets = [$this->projectSetDefinition(), $this->icgemSetDefinition()];

        // Resource type sets via SQL join + distinct (avoids loading all models)
        $types = DB::table('resources')
            ->join('resource_types', 'resources.resource_type_id', '=', 'resource_types.id')
            ->whereExists(fn ($q) => $q->select(DB::raw(1))
                ->from('landing_pages')
                ->whereColumn('landing_pages.resource_id', 'resources.id')
                ->where('landing_pages.is_published', true))
            ->whereNotNull('resources.doi')
            ->select('resource_types.slug', 'resource_types.name')
            ->distinct()
            ->orderBy('resource_types.name')
            ->get();

        foreach ($types as $type) {
            $sets[] = [
                'spec' => 'resourcetype:'.$type->slug,
                'name' => $type->name,
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
                'spec' => 'year:'.$year,
                'name' => 'Publication Year '.$year,
            ];
        }

        if (config('dcatap.enabled', false)) {
            $known = array_fill_keys(array_column($sets, 'spec'), true);
            OaiPmhFormatRecord::where('metadata_prefix', 'dcat_ap')->where('status', 'deleted')
                ->select(['id', 'sets'])->chunkById(200, function ($records) use (&$sets, &$known): void {
                    foreach ($records as $record) {
                        foreach ($record->sets as $spec) {
                            if (! isset($known[$spec]) && $this->isValidSetSpec($spec)) {
                                $sets[] = ['spec' => $spec, 'name' => $spec];
                                $known[$spec] = true;
                            }
                        }
                    }
                });
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
            $setSpec === IcgemSetService::SPEC => $this->icgemSet->applyToResourceQuery($query),
            $setSpec === EposMslSetService::SPEC => $query->whereHas(
                'subjects',
                fn (Builder $q) => $this->projectSet->applyToSubjects($q),
            ),
            str_starts_with($setSpec, 'resourcetype:') => $query->whereHas(
                'resourceType',
                fn (Builder $q) => $q->where('slug', Str::after($setSpec, 'resourcetype:')),
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

        $typeSlug = $resource->resourceType?->slug;
        if ($typeSlug !== null && $typeSlug !== '') {
            $sets[] = 'resourcetype:'.$typeSlug;
        }

        if ($resource->publication_year !== null) {
            $sets[] = 'year:'.$resource->publication_year;
        }

        if ($resource->subjects->contains(fn (Subject $subject): bool => $this->projectSet->matches($subject))) {
            $sets[] = EposMslSetService::SPEC;
        }

        if ($this->icgemSet->matches($resource->doi, $typeSlug)) {
            $sets[] = IcgemSetService::SPEC;
        }

        return $sets;
    }

    /**
     * @param  Builder<OaiPmhDeletedRecord>  $query
     * @return Builder<OaiPmhDeletedRecord>
     */
    public function applyDeletedSetFilter(Builder $query, string $setSpec): Builder
    {
        return $setSpec === IcgemSetService::SPEC
            ? $this->icgemSet->applyToDeletedRecordQuery($query)
            : $query->whereJsonContains('sets', $setSpec);
    }

    /** @return list<string> */
    public function getSetsForDeletedRecord(OaiPmhDeletedRecord $record): array
    {
        $sets = array_values($record->sets ?? []);
        if ($this->icgemSet->matchesDeletedRecord($record) && ! in_array(IcgemSetService::SPEC, $sets, true)) {
            $sets[] = IcgemSetService::SPEC;
        }

        return $sets;
    }

    /**
     * Validate whether a set spec is syntactically valid.
     *
     * OAI-PMH setSpec grammar requires URL-safe characters without spaces.
     * - resourcetype:{slug with alphanumeric, hyphens, underscores}
     * - year:{4-digit year}
     */
    public function isValidSetSpec(string $setSpec): bool
    {
        return $setSpec === EposMslSetService::SPEC
            || $setSpec === IcgemSetService::SPEC
            || (bool) preg_match('/^resourcetype:[a-z0-9_-]+$/', $setSpec)
            || (bool) preg_match('/^year:\d{4}$/', $setSpec);
    }
}
