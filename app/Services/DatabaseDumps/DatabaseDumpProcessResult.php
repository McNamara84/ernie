<?php

declare(strict_types=1);

namespace App\Services\DatabaseDumps;

final class DatabaseDumpProcessResult
{
    public function __construct(
        public readonly int $exitCode,
        public readonly string $errorOutput = '',
    ) {}

    public function successful(): bool
    {
        return $this->exitCode === 0;
    }
}
