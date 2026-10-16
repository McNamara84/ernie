<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\GemetApiService;

class GemetUpdateCheckClock extends GemetApiService
{
    public float $time = 0;

    public array $waits = [];

    public float $oversleep = 0;

    protected function checkTime(): float
    {
        return $this->time;
    }

    protected function waitForCheckRetry(float $seconds): void
    {
        $this->waits[] = $seconds;
        $this->time += $seconds + $this->oversleep;
    }
}
