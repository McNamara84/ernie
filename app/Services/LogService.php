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
     * Uses memory-efficient filtering during parsing to reduce memory usage.
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

        // Parse and filter in one pass to reduce memory usage
        $entries = $this->parseLogFileWithFilter($logPath, $level, $search);

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

        // Clear the full entries array to free memory before returning
        unset($entries);

        return [
            'data' => $data,
            'current_page' => $page,
            'last_page' => $lastPage,
            'per_page' => $perPage,
            'total' => $total,
        ];
    }

    /**
     * Parse log file and filter entries in a single pass to reduce memory usage.
     * Uses streaming approach for large files to limit memory usage.
     * Entries that don't match the filter criteria are immediately discarded.
     *
     * @return array<int, array{timestamp: string, level: string, message: string, context: string, line_number: int}>
     */
    private function parseLogFileWithFilter(string $logPath, ?string $level = null, ?string $search = null): array
    {
        $fileSize = File::size($logPath);
        $levelLower = $level !== null && $level !== '' ? strtolower($level) : null;
        $searchLower = $search !== null && $search !== '' ? strtolower($search) : null;

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
        $entryNumber = 0;

        // Laravel log format: [YYYY-MM-DD HH:MM:SS] environment.LEVEL: message
        $pattern = '/^\[(\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}:\d{2})\]\s+\w+\.(\w+):\s*(.*)$/';

        // Helper function to check if entry matches filters
        $matchesFilter = function (array $entry) use ($levelLower, $searchLower): bool {
            // Check level filter
            if ($levelLower !== null && $entry['level'] !== $levelLower) {
                return false;
            }

            // Check search filter
            if ($searchLower !== null) {
                $messageMatch = str_contains(strtolower($entry['message']), $searchLower);
                $contextMatch = str_contains(strtolower($entry['context']), $searchLower);
                if (! $messageMatch && ! $contextMatch) {
                    return false;
                }
            }

            return true;
        };

        foreach ($lines as $line) {
            if (preg_match($pattern, $line, $matches)) {
                // Check if previous entry matches filters before saving
                if ($currentEntry !== null && $matchesFilter($currentEntry)) {
                    $entries[] = $currentEntry;
                }

                $entryNumber++;

                // Start new entry
                $currentEntry = [
                    'timestamp' => $matches[1],
                    'level' => strtolower($matches[2]),
                    'message' => $matches[3],
                    'context' => '',
                    'line_number' => $entryNumber,
                ];
            } elseif ($currentEntry !== null && trim($line) !== '') {
                // Append to context (stack traces, etc.)
                $currentEntry['context'] .= ($currentEntry['context'] !== '' ? "\n" : '').$line;
            }
        }

        // Don't forget the last entry - apply filter here too
        if ($currentEntry !== null && $matchesFilter($currentEntry)) {
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
        // This also prevents line number mismatches from file truncation in parseLogFile
        $fileSize = File::size($logPath);
        if ($fileSize > self::MAX_FILE_SIZE) {
            return false;
        }

        $fileContent = File::get($logPath);
        $lines = explode("\n", $fileContent);
        $newLines = [];
        $found = false;
        $skipUntilNextEntry = false;
        // Entry number (1-indexed) - only incremented for actual log entries, not context lines
        $entryNumber = 0;

        $pattern = '/^\[(\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}:\d{2})\]\s+\w+\.\w+:/';

        foreach ($lines as $line) {
            // Check if this is a new log entry
            if (preg_match($pattern, $line, $matches)) {
                $skipUntilNextEntry = false;
                $entryNumber++;

                // Check if this is the entry to delete by entry number AND timestamp
                if ($entryNumber === $lineNumber && $matches[1] === $timestamp) {
                    $found = true;
                    $skipUntilNextEntry = true;

                    continue;
                }
            }

            if (! $skipUntilNextEntry) {
                $newLines[] = $line;
            }
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
