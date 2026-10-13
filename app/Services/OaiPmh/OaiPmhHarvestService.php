<?php

declare(strict_types=1);

namespace App\Services\OaiPmh;

use App\Models\OaiPmhDeletedRecord;
use App\Models\OaiPmhHarvest;
use App\Models\Resource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class OaiPmhHarvestService
{
    /**
     * Freeze identities inside the database without loading the complete list in PHP.
     *
     * @param  Builder<Resource>  $resources
     * @param  Builder<OaiPmhDeletedRecord>  $deletedRecords
     */
    public function create(Builder $resources, Builder $deletedRecords): OaiPmhHarvest
    {
        return DB::transaction(function () use ($resources, $deletedRecords): OaiPmhHarvest {
            $harvest = OaiPmhHarvest::create([
                'item_count' => 0,
                'expires_at' => now()->addSeconds((int) config('oaipmh.resumption_token_ttl', 86400)),
            ]);
            $columns = ['harvest_id', 'position', 'kind', 'identity_id'];
            $deletedCount = DB::table('oai_pmh_harvest_items')->insertUsing($columns,
                (clone $deletedRecords)->reorder()->select([])->selectRaw(
                    '? AS harvest_id, ROW_NUMBER() OVER (ORDER BY datestamp, id) - 1 AS position, ? AS kind, id AS identity_id',
                    [$harvest->id, 'deleted'],
                ),
            );
            $resourceCount = DB::table('oai_pmh_harvest_items')->insertUsing($columns,
                (clone $resources)->reorder()->select([])->selectRaw(
                    '? AS harvest_id, ROW_NUMBER() OVER (ORDER BY '
                        .'CASE WHEN landing_pages.published_at > resources.updated_at THEN landing_pages.published_at ELSE resources.updated_at END, '
                        .'resources.id) - 1 + ? AS position, ? AS kind, resources.id AS identity_id',
                    [$harvest->id, $deletedCount, 'resource'],
                ),
            );
            $harvest->update(['item_count' => $deletedCount + $resourceCount]);

            return $harvest;
        });
    }

    /**
     * A DOI change replaces the public identity, so every token sharing an
     * inventory containing this resource must require a fresh list request.
     */
    public function invalidateResource(int $resourceId): void
    {
        OaiPmhHarvest::query()->whereIn('id', DB::table('oai_pmh_harvest_items')
            ->select('harvest_id')
            ->where('kind', 'resource')
            ->where('identity_id', $resourceId))
            // Expire rather than delete: an in-flight request can still safely
            // reference the inventory until the normal purge removes it.
            ->update(['expires_at' => now()]);
    }

    /** @return list<array{kind: 'resource'|'deleted', id: int}> */
    public function page(OaiPmhHarvest $harvest, int $position, int $pageSize): array
    {
        $rows = DB::table('oai_pmh_harvest_items')
            ->where('harvest_id', $harvest->id)
            ->where('position', '>=', $position)
            ->where('position', '<', $position + $pageSize)
            ->orderBy('position')
            ->get(['kind', 'identity_id']);
        $items = [];
        foreach ($rows as $row) {
            /** @var 'resource'|'deleted' $kind */
            $kind = $row->kind;
            $items[] = ['kind' => $kind, 'id' => (int) $row->identity_id];
        }

        return $items;
    }
}
