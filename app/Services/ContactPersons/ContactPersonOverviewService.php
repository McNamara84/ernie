<?php

declare(strict_types=1);

namespace App\Services\ContactPersons;

use App\Services\Resources\IgsnListingQueryService;
use App\Services\Resources\ResourceListingProjectionRefreshService;
use App\Services\Resources\ResourcePartySearchNormalizerService;
use App\Support\OrcidNormalizer;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final readonly class ContactPersonOverviewService
{
    public function __construct(
        private ResourceListingProjectionRefreshService $refresh,
        private ResourcePartySearchNormalizerService $searchNormalizer,
        private IgsnListingQueryService $igsns,
    ) {}

    /** @return array{groups:list<array<string,mixed>>, pagination:array<string,int>} */
    public function overview(string $search, int $perPage, int $page): array
    {
        $this->refresh->flushPending();
        $groups = DB::table('resource_contact_points as points')
            ->select('identity_key')->selectRaw('MIN(name_sort) as sort_name')->groupBy('identity_key');
        if ($search !== '') {
            $pattern = $this->searchNormalizer->likePattern(mb_strtolower(OrcidNormalizer::extractBareId($search)));
            $groups->whereIn('identity_key', function (Builder $matches) use ($pattern): void {
                $matches->select('identity_key')->from('resource_contact_points')->where(function (Builder $fields) use ($pattern): void {
                    $fields->whereRaw("LOWER(display_name) LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereRaw("email_normalized LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereRaw("LOWER(orcid) LIKE ? ESCAPE '!'", [$pattern]);
                });
            });
        }

        $paginated = $groups->orderBy('sort_name')->orderBy('identity_key')->paginate($perPage, ['*'], 'page', $page);
        $keys = $paginated->getCollection()->pluck('identity_key')->all();
        $counts = DB::table('resource_contact_points as points')
            ->leftJoin('resource_listing_projections as listing', 'listing.resource_id', '=', 'points.resource_id')
            ->leftJoinSub($this->igsns->baseQuery()->select('resources.id'), 'igsn_targets', 'igsn_targets.id', '=', 'points.resource_id')
            ->whereIn('points.identity_key', $keys)
            ->select('points.identity_key', 'points.variant_key')
            ->selectRaw('MIN(points.resource_id) as representative_resource_id')
            ->selectRaw('COUNT(DISTINCT CASE WHEN listing.is_igsn = 0 THEN points.resource_id END) as resource_count')
            ->selectRaw('COUNT(DISTINCT igsn_targets.id) as igsn_count')
            ->groupBy('points.identity_key', 'points.variant_key');
        // Match the filter description's representative, independently of collation.
        $variants = $keys === [] ? collect() : DB::query()->fromSub($counts, 'counts')
            ->join('resource_contact_points as representative', function ($join): void {
                $join->on('representative.resource_id', '=', 'counts.representative_resource_id')
                    ->on('representative.variant_key', '=', 'counts.variant_key');
            })
            ->select('counts.*', 'representative.orcid', 'representative.display_name as name', 'representative.email')
            ->orderBy('representative.name_sort')->orderBy('name')->orderBy('email')->orderBy('counts.variant_key')
            ->get()->groupBy('identity_key');

        $data = [];
        foreach ($keys as $key) {
            $rows = $variants->get($key, collect());
            $data[] = [
                'key' => $key,
                'orcid' => $rows->first()?->orcid,
                'variants' => $rows->map(static fn ($row): array => [
                    'key' => $row->variant_key,
                    'name' => $row->name,
                    'email' => $row->email,
                    'resource_count' => (int) $row->resource_count,
                    'igsn_count' => (int) $row->igsn_count,
                ])->values()->all(),
            ];
        }

        return [
            'groups' => $data,
            'pagination' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
        ];
    }
}
