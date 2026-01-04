<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\File;

/**
 * Service for parsing and managing Laravel log files.
 */
class LogService
{
    private const LOG_LEVELS = [
        'emergency',
        'alert',
        'critical',
        'error',
        'warning',
        'notice',
        'info',
        'debug',
    ];

    /**
     * Maximum log file size to load into memory (50 MB).
     * Files larger than this will be truncated to the last N bytes.
     */
    private const MAX_FILE_SIZE = 50 * 1024 * 1024;

    /**
     * Get available log levels.
     *
     * @return array<string>
     */
    public function getAvailableLevels(): array
    {
        return self::LOG_LEVELS;
    }

    /**
     * Get paginated log entries from the Laravel log file.
     *
     * @return array{data: array<int, array{timestamp: string, level: string, message: string, context: string, line_number: int}>, current_page: int, last_page: int, per_page: int, total: int}
     */
    public function getLogs(
        int $perPage = 50,
        int $page = 1,
        ?string $level = null,
        ?string $search = null
    ): array {
        $logPath = storage_path('logs/laravel.log');

        if (! File::exists($logPath)) {
            return $this->emptyResult($perPage, $page);
        }

        $entries = $this->parseLogFile($logPath);

        // Filter by level
        if ($level !== null && $level !== '') {
            /** @var array<int, array{timestamp: string, level: string, message: string, context: string, line_number: int}> $entries */
            $entries = array_values(array_filter($entries, fn (array $entry): bool => strtolower($entry['level']) === strtolower($level)));
        }

        // Filter by search term
        if ($search !== null && $search !== '') {
            $searchLower = strtolower($search);
            /** @var array<int, array{timestamp: string, level: string, message: string, context: string, line_number: int}> $entries */
            $entries = array_values(array_filter($entries, fn (array $entry): bool => str_contains(strtolower($entry['message']), $searchLower)
                    || str_contains(strtolower($entry['context']), $searchLower)));
        }

        // Reverse to show newest first
        /** @var array<int, array{timestamp: string, level: string, message: string, context: string, line_number: int}> $entries */
        $entries = array_reverse($entries);

        // Pagination
        $total = count($entries);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $lastPage));
        $offset = ($page - 1) * $perPage;

        /** @var array<int, array{timestamp: string, level: string, message: string, context: string, line_number: int}> $data */
        $data = array_slice($entries, $offset, $perPage);

        return [
            'data' => $data,
            'current_page' => $page,
            'last_page' => $lastPage,
            'per_page' => $perPage,
            'total' => $total,
        ];
    }

    /**
     * Parse the Laravel log file into structured entries.
     * Uses streaming approach for large files to limit memory usage.
     *
     * @return array<int, array{timestamp: string, level: string, message: string, context: string, line_number: int}>
     */
    private function parseLogFile(string $logPath): array
    {
        $fileSize = File::size($logPath);

        // For very large files, only read the last MAX_FILE_SIZE bytes
        if ($fileSize > self::MAX_FILE_SIZE) {
            $handle = fopen($logPath, 'r');
            if ($handle === false) {
                \Illuminate\Support\Facades\Log::warning('LogService: Failed to open log file for reading', ['path' => $logPath]);

                return [];
            }

            // Seek to position near the end
            fseek($handle, -self::MAX_FILE_SIZE, SEEK_END);
            // Skip first partial line
            fgets($handle);
            $content = fread($handle, self::MAX_FILE_SIZE);
            fclose($handle);

            if ($content === false) {
                \Illuminate\Support\Facades\Log::warning('LogService: Failed to read from large log file', [
                    'path' => $logPath,
                    'file_size' => $fileSize,
                ]);

                return [];
            }
        } else {
            $content = File::get($logPath);
        }

        $lines = explode("\n", $content);
        $entries = [];
        $currentEntry = null;
        $lineNumber = 0;

        // Laravel log format: [YYYY-MM-DD HH:MM:SS] environment.LEVEL: message
        $pattern = '/^\[(\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}:\d{2})\]\s+\w+\.(\w+):\s*(.*)$/';

        foreach ($lines as $line) {
            $lineNumber++;

            if (preg_match($pattern, $line, $matches)) {
                // Save previous entry if exists
                if ($currentEntry !== null) {
                    $entries[] = $currentEntry;
                }

                // Start new entry
                $currentEntry = [
                    'timestamp' => $matches[1],
                    'level' => strtolower($matches[2]),
                    'message' => $matches[3],
                    'context' => '',
                    'line_number' => $lineNumber,
                ];
            } elseif ($currentEntry !== null && trim($line) !== '') {
                // Append to context (stack traces, etc.)
                $currentEntry['context'] .= ($currentEntry['context'] !== '' ? "\n" : '').$line;
            }
        }

        // Don't forget the last entry
        if ($currentEntry !== null) {
            $entries[] = $currentEntry;
        }

        return $entries;
    }

    /**
     * Delete a specific log entry by its line number.
     * Uses line numbers for precise identification to avoid ambiguity with duplicate timestamps.
     *
     * Note: This operation loads the entire file into memory. For very large files (>50MB),
     * consider using log rotation instead of individual entry deletion.
     *
     * @param  int  $lineNumber  The starting line number of the log entry
     * @param  string  $timestamp  The timestamp for validation (to ensure correct entry)
     */
    public function deleteLogEntry(int $lineNumber, string $timestamp): bool
    {
        $logPath = storage_path('logs/laravel.log');

        if (! File::exists($logPath)) {
            return false;
        }

        // Safety check: Don't process very large files for deletion
        $fileSize = File::size($logPath);
        if ($fileSize > self::MAX_FILE_SIZE) {
            return false;
        }

        $fileContent = File::get($logPath);
        $lines = explode("\n", $fileContent);
        $newLines = [];
        $found = false;
        $skipUntilNextEntry = false;
        // Line numbers are 1-indexed to match log entries returned to the frontend
        $currentLine = 1;

        $pattern = '/^\[(\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}:\d{2})\]\s+\w+\.\w+:/';

        foreach ($lines as $line) {
            // Check if this is a new log entry
            if (preg_match($pattern, $line, $matches)) {
                $skipUntilNextEntry = false;

                // Check if this is the entry to delete by line number AND timestamp
                if ($currentLine === $lineNumber && $matches[1] === $timestamp) {
                    $found = true;
                    $skipUntilNextEntry = true;
                    $currentLine++;

                    continue;
                }
            }

            if (! $skipUntilNextEntry) {
                $newLines[] = $line;
            }
            $currentLine++;
        }

        if ($found) {
            // Use file locking to prevent race conditions during concurrent deletions
            $handle = fopen($logPath, 'c');
            if ($handle !== false && flock($handle, LOCK_EX)) {
                ftruncate($handle, 0);
                fwrite($handle, implode("\n", $newLines));
                fflush($handle);
                flock($handle, LOCK_UN);
                fclose($handle);
            } else {
                if ($handle !== false) {
                    fclose($handle);
                }
                \Illuminate\Support\Facades\Log::warning('LogService: Failed to acquire lock for log file deletion', ['path' => $logPath]);

                return false;
            }
        }

        return $found;
    }

    /**
     * Clear all log entries.
     * Uses file locking to prevent race conditions.
     */
    public function clearLogs(): bool
    {
        $logPath = storage_path('logs/laravel.log');

        if (! File::exists($logPath)) {
            return true;
        }

        // Use file locking for atomic operation
        $handle = fopen($logPath, 'c');
        if ($handle !== false && flock($handle, LOCK_EX)) {
            ftruncate($handle, 0);
            fflush($handle);
            flock($handle, LOCK_UN);
            fclose($handle);

            return true;
        }

        if ($handle !== false) {
            fclose($handle);
        }

        \Illuminate\Support\Facades\Log::warning('LogService: Failed to acquire lock for clearing logs', ['path' => $logPath]);

        return false;
    }

    /**
     * Get empty result structure.
     *
     * @return array{data: array<int, array{timestamp: string, level: string, message: string, context: string, line_number: int}>, current_page: int, last_page: int, per_page: int, total: int}
     */
    private function emptyResult(int $perPage, int $page): array
    {
        return [
            'data' => [],
            'current_page' => $page,
            'last_page' => 1,
            'per_page' => $perPage,
            'total' => 0,
        ];
    }
}
