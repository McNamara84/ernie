<?php

declare(strict_types=1);

namespace App\Services\DateType;

use App\Enums\CacheKey;
use App\Support\Traits\ChecksCacheTagging;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class DateTypeUsageService
{
    use ChecksCacheTagging;

    /** @return array<int, int> Date type IDs mapped to date entry counts. */
    public function getUsageCounts(): array
    {
        $key = CacheKey::EDITOR_DATE_TYPE_USAGE;

        /** @var array<int, int> $counts */
        $counts = $this->getCacheInstance($key->tags())->remember(
            $key->key(),
            $key->ttl(),
            static function (): array {
                $rows = DB::table('dates')
                    ->join('resources', 'resources.id', '=', 'dates.resource_id')
                    ->leftJoin('resource_types', 'resource_types.id', '=', 'resources.resource_type_id')
                    ->where(static function (Builder $query): void {
                        $query->whereNull('resource_types.id')
                            ->orWhere('resource_types.slug', '<>', 'physical-object');
                    })
                    ->selectRaw('dates.date_type_id, COUNT(*) AS usage_count')
                    ->groupBy('dates.date_type_id')
                    ->get();

                $counts = [];
                foreach ($rows as $row) {
                    $counts[(int) $row->date_type_id] = (int) $row->usage_count;
                }

                return $counts;
            },
        );

        return $counts;
    }
}
