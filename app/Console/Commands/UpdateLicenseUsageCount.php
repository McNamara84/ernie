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

        // Get usage counts from the resource_rights pivot table
        $usageCounts = DB::table('resource_rights')
            ->join('rights', 'resource_rights.rights_id', '=', 'rights.id')
            ->select('rights.id', DB::raw('COUNT(DISTINCT resource_rights.resource_id) as count'))
            ->groupBy('rights.id')
            ->pluck('count', 'id');

        // Reset all usage counts to 0
        Right::query()->update(['usage_count' => 0]);

        // Update usage counts for rights that have associations
        foreach ($usageCounts as $rightId => $count) {
            Right::where('id', $rightId)->update(['usage_count' => $count]);
        }

        $totalRights = Right::count();
        $this->info('Successfully calculated usage counts for '.$totalRights.' rights.');

        return Command::SUCCESS;
    }
}
