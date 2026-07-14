<?php

declare(strict_types=1);

namespace App\Services\DatabaseDumps;

interface DatabaseDumpProcessRunner
{
    public function findDumpClient(): ?string;

    public function supportsOption(string $client, string $option): bool;

    /**
     * @param  list<string>  $command
     */
    public function run(array $command, string $compressedOutputPath, int $timeoutSeconds): DatabaseDumpProcessResult;
}
