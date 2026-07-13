<?php

declare(strict_types=1);

namespace App\Services\DatabaseDumps;

use Symfony\Component\Process\Process;

final class SymfonyDatabaseDumpProcessRunner implements DatabaseDumpProcessRunner
{
    /**
     * @var array<string, bool>
     */
    private array $optionSupportCache = [];

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
        $cacheKey = "{$client}:{$option}";

        if (array_key_exists($cacheKey, $this->optionSupportCache)) {
            return $this->optionSupportCache[$cacheKey];
        }

        try {
            $process = new Process([$client, '--help']);
            $process->setTimeout(10);
            $process->run();

            $output = $process->getOutput()."\n".$process->getErrorOutput();
            $optionName = str_contains($option, '=')
                ? substr($option, 0, (int) strpos($option, '='))
                : $option;

            return $this->optionSupportCache[$cacheKey] = str_contains($output, $optionName);
        } catch (\Throwable) {
            return $this->optionSupportCache[$cacheKey] = false;
        }
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
