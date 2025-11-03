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

        // Build CASE statement with parameter bindings for SQL injection prevention
        $cases = [];
        $bindings = [];

        foreach ($usageCounts as $licenseId => $count) {
            $cases[] = 'WHEN id = ? THEN ?';
            $bindings[] = $licenseId;
            $bindings[] = $count;
        }

        $caseStatement = implode(' ', $cases);

        // Single UPDATE query with CASE statement and parameter bindings
        DB::statement("
            UPDATE licenses
            SET usage_count = CASE {$caseStatement} ELSE 0 END
        ", $bindings);

        $this->info('Successfully updated usage counts for '.count($usageCounts).' licenses.');

        return Command::SUCCESS;
    }
}
