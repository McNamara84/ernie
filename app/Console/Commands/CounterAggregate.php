<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Usage\CounterAggregationService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class CounterAggregate extends Command
{
    protected $signature = 'counter:aggregate {--through= : Last completed UTC day (YYYY-MM-DD)} {--rebuild-month= : Rebuild a month from permanent daily aggregates (YYYY-MM)}';

    protected $description = 'Finalize completed COUNTER days and rebuild monthly aggregates atomically';

    public function handle(CounterAggregationService $aggregation): int
    {
        $month = $this->option('rebuild-month');
        if (is_string($month)) {
            if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
                $this->error('Expected YYYY-MM.');

                return self::INVALID;
            }
            $aggregation->rebuildMonth(CarbonImmutable::parse($month.'-01', 'UTC'));
        } else {
            $through = $this->option('through');
            if (is_string($through) && ! CarbonImmutable::hasFormat($through, 'Y-m-d')) {
                $this->error('Expected YYYY-MM-DD.');

                return self::INVALID;
            }
            $this->info('Finalized days: '.$aggregation->aggregate(is_string($through) ? $through : null));
        }

        return self::SUCCESS;
    }
}
