<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\LogDownloadPeriod;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Generator;
use InvalidArgumentException;
use RuntimeException;

final class LogDownloadService
{
    private const LOG_ENTRY_PATTERN = '/^\[(\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}:\d{2})\]\s+\S+\.[A-Za-z]+:/';

    private const ROTATED_LOG_PATTERN = '/^laravel-(\d{4})-(\d{2})-(\d{2})\.log$/';

    /**
     * @param  resource  $stream
     */
    public function writeTextExport(
        $stream,
        LogDownloadPeriod $period,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
    ): void {
        if (! is_resource($stream)) {
            throw new InvalidArgumentException('The log export target must be a writable stream.');
        }

        $utcStartsAt = $startsAt->utc();
        $utcEndsAt = $endsAt->utc();

        $this->write($stream, implode("\n", [
            '# ERNIE application log export',
            "# Period: {$period->label()}",
            '# From (UTC): '.$utcStartsAt->format('Y-m-d\TH:i:s\Z'),
            '# To (UTC): '.$utcEndsAt->format('Y-m-d\TH:i:s\Z'),
            '# Generated (UTC): '.$utcEndsAt->format('Y-m-d\TH:i:s\Z'),
            '',
            '',
        ]));

        $entryWritten = false;
        $previousEntryEndedWithNewline = true;

        foreach ($this->entriesBetween($utcStartsAt, $utcEndsAt) as $entry) {
            if ($entryWritten && ! $previousEntryEndedWithNewline) {
                $this->write($stream, "\n");
            }

            $this->write($stream, $entry['content']);
            $entryWritten = true;
            $previousEntryEndedWithNewline = str_ends_with($entry['content'], "\n");
        }

        if (! $entryWritten) {
            $this->write($stream, "# No log entries were available for this period.\n");
        }
    }

    /**
     * @return Generator<int, array{timestamp: CarbonImmutable, content: string, source_order: int, sequence: int}>
     */
    private function entriesBetween(CarbonImmutable $startsAt, CarbonImmutable $endsAt): Generator
    {
        /** @var array<int, Generator<int, array{timestamp: CarbonImmutable, content: string, source_order: int, sequence: int}>> $generators */
        $generators = [];
        /** @var array<int, array{timestamp: CarbonImmutable, content: string, source_order: int, sequence: int}> $heads */
        $heads = [];

        foreach ($this->logSources() as $source) {
            $generator = $this->entriesFromSource($source, $startsAt, $endsAt);
            $generator->rewind();

            if (! $generator->valid()) {
                continue;
            }

            $key = $source['order'];
            $generators[$key] = $generator;
            $heads[$key] = $generator->current();
        }

        while ($heads !== []) {
            $selectedKey = null;

            foreach ($heads as $key => $entry) {
                if ($selectedKey === null || $this->compareEntries($entry, $heads[$selectedKey]) < 0) {
                    $selectedKey = $key;
                }
            }

            yield $heads[$selectedKey];

            $generators[$selectedKey]->next();
            if ($generators[$selectedKey]->valid()) {
                $heads[$selectedKey] = $generators[$selectedKey]->current();
            } else {
                unset($generators[$selectedKey], $heads[$selectedKey]);
            }
        }
    }

    /**
     * @param  array{path: string, size: int, order: int}  $source
     * @return Generator<int, array{timestamp: CarbonImmutable, content: string, source_order: int, sequence: int}>
     */
    private function entriesFromSource(array $source, CarbonImmutable $startsAt, CarbonImmutable $endsAt): Generator
    {
        $handle = @fopen($source['path'], 'rb');
        if ($handle === false) {
            return;
        }

        $remainingBytes = $source['size'];
        $currentEntry = null;
        $sequence = 0;

        try {
            while ($remainingBytes > 0) {
                $line = fgets($handle, $remainingBytes + 1);
                if ($line === false) {
                    break;
                }

                $remainingBytes -= strlen($line);

                if (preg_match(self::LOG_ENTRY_PATTERN, $line, $matches) === 1) {
                    if ($currentEntry !== null && $this->isWithinPeriod($currentEntry['timestamp'], $startsAt, $endsAt)) {
                        yield $currentEntry;
                    }

                    $sequence++;
                    $timestamp = $this->parseTimestamp($matches[1]);
                    $currentEntry = $timestamp === null ? null : [
                        'timestamp' => $timestamp,
                        'content' => $line,
                        'source_order' => $source['order'],
                        'sequence' => $sequence,
                    ];

                    continue;
                }

                if ($currentEntry !== null) {
                    $currentEntry['content'] .= $line;
                }
            }

            if ($currentEntry !== null && $this->isWithinPeriod($currentEntry['timestamp'], $startsAt, $endsAt)) {
                yield $currentEntry;
            }
        } finally {
            fclose($handle);
        }
    }

    private function parseTimestamp(string $value): ?CarbonImmutable
    {
        $timestamp = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            $value,
            new DateTimeZone('UTC'),
        );
        $errors = DateTimeImmutable::getLastErrors();

        if ($timestamp === false || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }

        return CarbonImmutable::instance($timestamp);
    }

    private function isWithinPeriod(
        CarbonImmutable $timestamp,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
    ): bool {
        return $timestamp->greaterThanOrEqualTo($startsAt)
            && $timestamp->lessThanOrEqualTo($endsAt);
    }

    /**
     * @param  array{timestamp: CarbonImmutable, content: string, source_order: int, sequence: int}  $left
     * @param  array{timestamp: CarbonImmutable, content: string, source_order: int, sequence: int}  $right
     */
    private function compareEntries(array $left, array $right): int
    {
        $timestampComparison = $left['timestamp']->getTimestamp() <=> $right['timestamp']->getTimestamp();
        if ($timestampComparison !== 0) {
            return $timestampComparison;
        }

        $sourceComparison = $left['source_order'] <=> $right['source_order'];
        if ($sourceComparison !== 0) {
            return $sourceComparison;
        }

        return $left['sequence'] <=> $right['sequence'];
    }

    /**
     * @return list<array{path: string, size: int, order: int}>
     */
    private function logSources(): array
    {
        $logDirectory = storage_path('logs');
        $resolvedDirectory = realpath($logDirectory);
        if ($resolvedDirectory === false || ! is_dir($resolvedDirectory)) {
            return [];
        }

        $filenames = scandir($resolvedDirectory);
        if ($filenames === false) {
            return [];
        }

        /** @var list<array{path: string, size: int}> $sources */
        $sources = [];

        foreach ($filenames as $filename) {
            if (! $this->isSupportedFilename($filename)) {
                continue;
            }

            $candidate = $resolvedDirectory.DIRECTORY_SEPARATOR.$filename;
            if (is_link($candidate)) {
                continue;
            }

            $resolvedPath = realpath($candidate);
            if ($resolvedPath === false || dirname($resolvedPath) !== $resolvedDirectory) {
                continue;
            }

            if (! is_file($resolvedPath) || ! is_readable($resolvedPath)) {
                continue;
            }

            $size = filesize($resolvedPath);
            if ($size === false) {
                continue;
            }

            $sources[] = [
                'path' => $resolvedPath,
                'size' => $size,
            ];
        }

        usort($sources, static fn (array $left, array $right): int => basename($left['path']) <=> basename($right['path']));

        return array_map(
            static fn (array $source, int $order): array => [
                ...$source,
                'order' => $order,
            ],
            $sources,
            array_keys($sources),
        );
    }

    private function isSupportedFilename(string $filename): bool
    {
        if ($filename === 'laravel.log') {
            return true;
        }

        if (preg_match(self::ROTATED_LOG_PATTERN, $filename, $matches) !== 1) {
            return false;
        }

        return checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1]);
    }

    /**
     * @param  resource  $stream
     */
    private function write($stream, string $content): void
    {
        $length = strlen($content);
        $offset = 0;

        while ($offset < $length) {
            $written = fwrite($stream, substr($content, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('Failed to write the log export stream.');
            }

            $offset += $written;
        }
    }
}
