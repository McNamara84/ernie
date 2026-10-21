<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Usage\CounterTrackingStateService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class CounterMarkGap extends Command
{
    protected $signature = 'counter:mark-gap {from : First affected UTC date (YYYY-MM-DD)} {through : Last affected UTC date (YYYY-MM-DD)}';

    protected $description = 'Mark known observation gaps, for example after restoring a database backup';

    public function handle(CounterTrackingStateService $state): int
    {
        $from = (string) $this->argument('from');
        $through = (string) $this->argument('through');
        if (! CarbonImmutable::hasFormat($from, 'Y-m-d') || ! CarbonImmutable::hasFormat($through, 'Y-m-d')) {
            $this->error('Expected YYYY-MM-DD dates.');

            return self::INVALID;
        }
        $state->markGap(CarbonImmutable::parse($from, 'UTC'), CarbonImmutable::parse($through, 'UTC'));
        $this->info('Observation gap marked. Existing aggregate counts have been preserved.');

        return self::SUCCESS;
    }
}
