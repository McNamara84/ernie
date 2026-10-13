<?php

declare(strict_types=1);

namespace App\Contracts;

interface ReportsDiscoveryDetails
{
    /** @return array<string, int|string|bool|null> */
    public function discoveryDetails(): array;
}
