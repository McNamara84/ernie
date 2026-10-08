<?php

declare(strict_types=1);

namespace App\Services;

use Tests\Helpers\NativeSleep;

// Test-only adapters; keep them separate from the support class so static
// namespace discovery does not treat that class as application code.
function sleep(int $seconds): int
{
    return NativeSleep::sleep($seconds);
}

function usleep(int $microseconds): void
{
    NativeSleep::usleep($microseconds);
}
