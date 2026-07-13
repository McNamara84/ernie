<?php

declare(strict_types=1);

namespace App\Services\DatabaseDumps;

use Symfony\Component\Process\Process;

final class SymfonyDatabaseDumpProcessRunner implements DatabaseDumpProcessRunner
{
    /**
     * @var array<string, string|null>
     */
    private array $helpOutputCache = [];

    public function findDumpClient(): ?string
    {
        $configuredBinary = config('database_dumps.dump_binary');

        $candidates = array_values(array_filter([
            is_string($configuredBinary) ? $configuredBinary : null,
            'mysqldump',
            'mariadb-dump',
        ]));

        foreach ($candidates as $candidate) {
            $resolved = $this->resolveExecutable($candidate);

            if ($resolved !== null) {
                return $resolved;
            }
        }

        return null;
    }

    public function supportsOption(string $client, string $option): bool
    {
        $output = $this->helpOutput($client);

        if ($output === null) {
            return false;
        }

        return str_contains($output, $this->optionName($option));
    }

    private function helpOutput(string $client): ?string
    {
        if (array_key_exists($client, $this->helpOutputCache)) {
            return $this->helpOutputCache[$client];
        }

        try {
            $process = new Process([$client, '--help']);
            $process->setTimeout(10);
            $process->run();

            return $this->helpOutputCache[$client] = $process->getOutput()."\n".$process->getErrorOutput();
        } catch (\Throwable) {
            return $this->helpOutputCache[$client] = null;
        }
    }

    private function optionName(string $option): string
    {
        return str_contains($option, '=')
            ? substr($option, 0, (int) strpos($option, '='))
            : $option;
    }

    /**
     * @param  list<string>  $command
     */
    public function run(array $command, string $compressedOutputPath, int $timeoutSeconds): DatabaseDumpProcessResult
    {
        $directory = dirname($compressedOutputPath);

        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $gzip = gzopen($compressedOutputPath, 'wb9');

        if ($gzip === false) {
            throw new \RuntimeException('Could not open compressed dump target for writing.');
        }

        $errorOutput = '';

        try {
            $process = new Process($command);
            $process->setTimeout($timeoutSeconds);
            $process->run(function (string $type, string $buffer) use ($gzip, &$errorOutput): void {
                if ($type === Process::OUT) {
                    gzwrite($gzip, $buffer);

                    return;
                }

                $errorOutput .= $buffer;

                if (strlen($errorOutput) > 12000) {
                    $errorOutput = substr($errorOutput, -12000);
                }
            });

            return new DatabaseDumpProcessResult(
                exitCode: $process->getExitCode() ?? 1,
                errorOutput: $errorOutput,
            );
        } finally {
            gzclose($gzip);
        }
    }

    private function resolveExecutable(string $candidate): ?string
    {
        if (str_contains($candidate, DIRECTORY_SEPARATOR) || str_contains($candidate, '/')) {
            return is_file($candidate) && is_executable($candidate) ? $candidate : null;
        }

        $path = getenv('PATH') ?: '';

        foreach (explode(PATH_SEPARATOR, $path) as $directory) {
            $directory = trim($directory);

            if ($directory === '') {
                continue;
            }

            $fullPath = rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$candidate;

            if (is_file($fullPath) && is_executable($fullPath)) {
                return $fullPath;
            }
        }

        return null;
    }
}
