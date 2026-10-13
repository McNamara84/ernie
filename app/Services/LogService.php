<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Resource;

/**
 * @phpstan-type LogEntry array{timestamp: string, level: string, message: string, context: string, line_number: int, entry_id: string, activity: ?array<string, mixed>}
 */
class LogService
{
    private const MAX_FILE_SIZE = 50 * 1024 * 1024;

    private const PATTERN = '/^\[(\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}:\d{2})\]\s+\S+\.(\w+):\s*(.*)$/';

    public function __construct(private readonly ApplicationLogSourceService $sources = new ApplicationLogSourceService) {}

    /** @return list<string> */
    public function getAvailableLevels(): array
    {
        return ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'];
    }

    /** @return array{data: list<LogEntry>, current_page: int, last_page: int, per_page: int, total: int, truncated: bool} */
    public function getLogs(int $perPage = 50, int $page = 1, ?string $level = null, ?string $search = null): array
    {
        $perPage = max(1, min(200, $perPage));
        $sources = $this->sources->sources();
        // Editing an old daily file must not move it ahead of newer history.
        $sourceDate = static fn (array $source): string => basename($source['path']) === 'laravel.log'
            ? date('Y-m-d', filemtime($source['path']) ?: 0)
            : substr(basename($source['path']), 8, 10);
        usort($sources, static fn (array $a, array $b): int => ($sourceDate($b) <=> $sourceDate($a)) ?: ($b['path'] <=> $a['path']));
        $remaining = self::MAX_FILE_SIZE;
        $entries = [];
        $truncated = false;
        foreach ($sources as $source) {
            if ($remaining <= 0) {
                $truncated = $truncated || $source['size'] > 0;

                continue;
            }
            $length = min($remaining, $source['size']);
            $start = $source['size'] - $length;
            $remaining -= $length;
            $truncated = $truncated || $start > 0;
            $handle = @fopen($source['path'], 'rb');
            if ($handle === false) {
                continue;
            }
            try {
                fseek($handle, $start);
                if ($start > 0) {
                    fgets($handle);
                }
                $current = null;
                $raw = '';
                $offset = 0;
                $number = 0;
                while (($position = ftell($handle)) !== false && $position < $source['size'] && ($line = fgets($handle, max(1, $source['size'] - $position + 1))) !== false) {
                    if (preg_match(self::PATTERN, rtrim($line, "\r\n"), $match) === 1) {
                        if ($current !== null) {
                            $this->append($entries, $current, $raw, $source['path'], $offset, $level, $search);
                        }
                        $offset = $position;
                        $number++;
                        $current = ['timestamp' => $match[1], 'level' => strtolower($match[2]), 'message' => $match[3], 'context' => '', 'line_number' => $number, 'entry_id' => '', 'activity' => null];
                        $raw = $line;
                    } elseif ($current !== null) {
                        $raw .= $line;
                        if (trim($line) !== '') {
                            $current['context'] .= ($current['context'] === '' ? '' : "\n").rtrim($line, "\r\n");
                        }
                    }
                }
                if ($current !== null) {
                    $this->append($entries, $current, $raw, $source['path'], $offset, $level, $search);
                }
            } finally {
                fclose($handle);
            }
        }
        usort($entries, static fn (array $a, array $b): int => ($b['timestamp'] <=> $a['timestamp']) ?: ($b['_order'] <=> $a['_order']));
        $total = count($entries);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $lastPage));
        $data = array_slice($entries, ($page - 1) * $perPage, $perPage);
        foreach ($data as &$entry) {
            unset($entry['_order']);
        }
        unset($entry);
        /** @var list<LogEntry> $data */
        $this->addLinks($data);

        return ['data' => $data, 'current_page' => $page, 'last_page' => $lastPage, 'per_page' => $perPage, 'total' => $total, 'truncated' => $truncated];
    }

    /** @param list<array<string, mixed>> $entries
     * @param  LogEntry  $entry
     */
    private function append(array &$entries, array $entry, string $raw, string $path, int $offset, ?string $level, ?string $search): void
    {
        if ($level !== null && $level !== '' && $entry['level'] !== strtolower($level)) {
            return;
        }
        $entry['entry_id'] = base64_encode(json_encode([basename($path), $offset, hash('sha256', $raw)], JSON_THROW_ON_ERROR));
        $entry['_order'] = basename($path).sprintf('%020d', $offset);
        if (preg_match('/\s+(\{"activity":.*\})\s*(?:\[\])?$/s', $entry['message'], $match, PREG_OFFSET_CAPTURE) === 1) {
            $context = json_decode($match[1][0], true);
            $activity = is_array($context) ? ($context['activity'] ?? null) : null;
            if (is_array($activity) && ($activity['schema_version'] ?? null) === 1 && is_string($activity['action'] ?? null)
                && is_array($activity['actor'] ?? null) && is_string($activity['actor']['name'] ?? null)) {
                $entry['activity'] = $activity;
                $entry['message'] = rtrim(substr($entry['message'], 0, $match[1][1]));
            }
        }
        $searchable = $raw.' '.json_encode($entry['activity'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($search !== null && $search !== '' && ! str_contains(mb_strtolower($searchable), mb_strtolower($search))) {
            return;
        }
        $entries[] = $entry;
    }

    /** @param list<LogEntry> $entries */
    private function addLinks(array &$entries): void
    {
        $ids = [];
        foreach ($entries as $entry) {
            $id = $entry['activity']['subject']['id'] ?? null;
            if (is_int($id) && $id > 0) {
                $ids[] = $id;
            }
        }
        $available = $ids === [] ? [] : Resource::query()->whereIn('id', $ids)->pluck('id')->all();
        foreach ($entries as &$entry) {
            if ($entry['activity'] === null) {
                continue;
            }
            $entry['activity']['dataset_path'] = null;
            $entry['activity']['doi_url'] = null;
            $subject = $entry['activity']['subject'] ?? null;
            if (! is_array($subject)) {
                continue;
            }
            if (in_array($subject['id'] ?? null, $available, true) && ! in_array($entry['activity']['action'], ['resources.destroy', 'resources.batch-destroy', 'resources.destroy-all', 'igsns.destroy', 'igsns.batch.destroy'], true)) {
                $entry['activity']['dataset_path'] = ($subject['kind'] ?? null) === 'IGSN'
                    ? (is_string($subject['doi'] ?? null) && $subject['doi'] !== '' ? '/igsns?search='.rawurlencode($subject['doi']) : null)
                    : '/editor?resourceId='.$subject['id'];
            }
            $doi = $subject['doi'] ?? null;
            if (is_string($doi) && preg_match('/^10\.\d{4,9}\/[^\s]+$/u', $doi) === 1 && ! ($entry['activity']['test_mode'] ?? false)) {
                $entry['activity']['doi_url'] = 'https://doi.org/'.str_replace('%2F', '/', rawurlencode($doi));
            }
        }
    }

    public function deleteLogEntry(int $lineNumber, string $timestamp, ?string $entryId = null): bool
    {
        $identity = $entryId === null ? null : json_decode(base64_decode($entryId, true) ?: '', true);
        if ($entryId !== null && (! is_array($identity) || count($identity) !== 3 || ! is_string($identity[0] ?? null) || ! is_int($identity[1] ?? null) || ! is_string($identity[2] ?? null))) {
            return false;
        }
        foreach ($this->sources->sources() as $source) {
            if (basename($source['path']) !== ($identity[0] ?? 'laravel.log') || $source['size'] > self::MAX_FILE_SIZE) {
                continue;
            }
            $handle = @fopen($source['path'], 'r+b');
            if ($handle === false) {
                return false;
            }
            try {
                if (! flock($handle, LOCK_EX)) {
                    return false;
                }
                $content = stream_get_contents($handle);
                if ($content === false) {
                    return false;
                }
                preg_match_all(self::PATTERN.'m', $content, $matches, PREG_OFFSET_CAPTURE);
                foreach ($matches[0] as $index => $match) {
                    $start = $match[1];
                    $end = $matches[0][$index + 1][1] ?? strlen($content);
                    $raw = substr($content, $start, $end - $start);
                    $selected = $identity === null ? $index + 1 === $lineNumber : $start === $identity[1] && hash_equals($identity[2], hash('sha256', $raw));
                    if ($selected && $matches[1][$index][0] === $timestamp) {
                        rewind($handle);
                        $replacement = substr($content, 0, $start).substr($content, $end);
                        ftruncate($handle, 0);

                        return fwrite($handle, $replacement) === strlen($replacement) && fflush($handle);
                    }
                }
            } finally {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        }

        return false;
    }

    public function clearLogs(): bool
    {
        foreach ($this->sources->sources() as $source) {
            $handle = @fopen($source['path'], 'r+b');
            if ($handle === false) {
                return false;
            }
            try {
                if (! flock($handle, LOCK_EX) || ! ftruncate($handle, 0) || ! fflush($handle)) {
                    return false;
                }
            } finally {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        }

        return true;
    }
}
