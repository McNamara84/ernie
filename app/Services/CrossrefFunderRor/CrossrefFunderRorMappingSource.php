<?php

declare(strict_types=1);

namespace App\Services\CrossrefFunderRor;

interface CrossrefFunderRorMappingSource
{
    /**
     * @return list<array<string, mixed>>
     */
    public function candidatesForCrossrefFunderId(string $normalizedFundrefId): array;
}
