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

        // Reset all licenses to 0 first
        License::query()->update(['usage_count' => 0]);

        $updatedCount = 0;

        // Update licenses with actual usage counts
        foreach ($usageCounts as $licenseId => $count) {
            License::where('id', $licenseId)->update(['usage_count' => $count]);
            $updatedCount++;
        }

        $this->info("Successfully updated usage counts for {$updatedCount} licenses.");

        return Command::SUCCESS;
    }
}
