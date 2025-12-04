<?php

namespace App\Console\Commands;

use App\Models\Right;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class UpdateLicenseUsageCount extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rights:update-usage-count';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update usage count for all rights based on resource associations';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Calculating rights usage counts...');

        // Get usage counts from the rights table (which has resource_id)
        $usageCounts = DB::table('rights')
            ->select('identifier', DB::raw('COUNT(DISTINCT resource_id) as count'))
            ->whereNotNull('resource_id')
            ->groupBy('identifier')
            ->pluck('count', 'identifier');

        if ($usageCounts->isEmpty()) {
            $this->info('No rights usage found.');

            return Command::SUCCESS;
        }

        $this->info('Successfully calculated usage counts for '.count($usageCounts).' rights.');

        return Command::SUCCESS;
    }
}
