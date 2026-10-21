<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Usage\CounterListService;
use Illuminate\Console\Command;

class CounterUpdateLists extends Command
{
    protected $signature = 'counter:update-lists';

    protected $description = 'Validate and atomically activate updated COUNTER research-data classification lists';

    public function handle(CounterListService $lists): int
    {
        $this->info('Active list version: '.$lists->update());

        return self::SUCCESS;
    }
}
