<?php

namespace App\Console;

use App\Console\Commands\SyncSpdxLicenses;
use App\Console\Commands\UpdateLicenseUsageCount;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        $schedule->command(SyncSpdxLicenses::class)->monthly();
        $schedule->command(UpdateLicenseUsageCount::class)->weekly();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
