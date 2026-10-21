<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Usage\CounterAggregationService;
use Illuminate\Console\Command;

class CounterPrune extends Command
{
    protected $signature = 'counter:prune';

    protected $description = 'Remove unaggregated COUNTER events before the seven-day retention deadline and mark gaps';

    public function handle(CounterAggregationService $aggregation): int
    {
        $this->info('Expired events removed: '.$aggregation->prune());

        return self::SUCCESS;
    }
}
