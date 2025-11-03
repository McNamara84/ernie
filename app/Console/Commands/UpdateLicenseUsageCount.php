<?php

namespace App\Console\Commands;

use App\Models\License;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class UpdateLicenseUsageCount extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'licenses:update-usage-count';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update usage count for all licenses based on resource associations';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Calculating license usage counts...');

        // Get usage counts from pivot table
        $usageCounts = DB::table('license_resource')
            ->select('license_id', DB::raw('COUNT(*) as count'))
            ->groupBy('license_id')
            ->pluck('count', 'license_id');

        if ($usageCounts->isEmpty()) {
            // If no licenses are used, reset all to 0
            License::query()->update(['usage_count' => 0]);
            $this->info('No license usage found. All counts reset to 0.');

            return Command::SUCCESS;
        }

        // Build CASE statement for single UPDATE query
        $cases = [];
        $ids = [];

        foreach ($usageCounts as $licenseId => $count) {
            $cases[] = "WHEN id = {$licenseId} THEN {$count}";
            $ids[] = $licenseId;
        }

        $caseStatement = implode(' ', $cases);
        $idList = implode(',', $ids);

        // Single UPDATE query with CASE statement
        DB::statement("
            UPDATE licenses
            SET usage_count = CASE {$caseStatement} ELSE 0 END
        ");

        $this->info('Successfully updated usage counts for '.count($usageCounts).' licenses.');

        return Command::SUCCESS;
    }
}
