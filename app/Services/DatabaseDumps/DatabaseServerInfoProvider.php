<?php

declare(strict_types=1);

namespace App\Services\DatabaseDumps;

interface DatabaseServerInfoProvider
{
    /**
     * @return array{version: string|null, version_comment: string|null, compile_os: string|null, compile_machine: string|null, source: string}
     */
    public function resolve(string $connectionName): array;
}
