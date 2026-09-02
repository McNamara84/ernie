<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Right;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Description('Update usage count for all rights based on resource associations')]
#[Signature('rights:update-usage-count')]
class UpdateLicenseUsageCount extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Calculating rights usage counts...');

        $startedAt = hrtime(true);

        /** @var array{resource_count: int, total_rights: int, used_rights: int} $statistics */
        $statistics = DB::transaction(function (): array {
            // Count every stored resource association, regardless of workflow status.
            $usageCounts = DB::table('resource_rights')
                ->join('rights', 'resource_rights.rights_id', '=', 'rights.id')
                ->select('rights.id', DB::raw('COUNT(DISTINCT resource_rights.resource_id) as count'))
                ->groupBy('rights.id')
                ->pluck('count', 'id');

            $resourceCount = DB::table('resource_rights')
                ->distinct()
                ->count('resource_id');

            // Keep the reset and all replacements atomic so readers see either
            // the previous complete snapshot or the newly calculated one.
            DB::table('rights')->update(['usage_count' => 0]);

            foreach ($usageCounts as $rightId => $count) {
                DB::table('rights')
                    ->where('id', (int) $rightId)
                    ->update(['usage_count' => (int) $count]);
            }

            return [
                'resource_count' => $resourceCount,
                'total_rights' => Right::query()->count(),
                'used_rights' => $usageCounts->count(),
            ];
        });

        $elapsedMilliseconds = (int) round((hrtime(true) - $startedAt) / 1_000_000);

        $this->info(sprintf(
            'Successfully calculated usage counts for %d rights (%d used) across %d resources in %d ms.',
            $statistics['total_rights'],
            $statistics['used_rights'],
            $statistics['resource_count'],
            $elapsedMilliseconds,
        ));

        return Command::SUCCESS;
    }
}
