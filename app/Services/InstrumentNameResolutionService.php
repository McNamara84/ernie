<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PidSetting;
use Illuminate\Support\Facades\Log;

/**
 * Resolves labels from the local registry only; identifiers are never rewritten.
 *
 * @phpstan-type LookupIdentifier array{value: string, type: string, source: string}
 * @phpstan-type Resolution array{status: string, identifiers?: list<LookupIdentifier>}
 * @phpstan-type Selection array{pid: string, pidType: string, name: string, resolution?: Resolution}
 */
final class InstrumentNameResolutionService
{
    public function __construct(private readonly Pid4instVocabularyService $vocabulary) {}

    /**
     * @param  array<int, array<string, mixed>>  $instruments
     * @return list<Selection>
     */
    public function resolve(array $instruments): array
    {
        if ($instruments === []) {
            return [];
        }

        $records = [];
        $available = PidSetting::where('type', PidSetting::TYPE_PID4INST)->value('is_active') !== false;
        if ($available) {
            try {
                $records = $this->vocabulary->getLocalPayload()['data'];
            } catch (\Throwable $exception) {
                $available = false;
                Log::warning('Instrument names could not be resolved from the local registry.', [
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        $index = [];
        $identifiersByRecord = [];
        foreach ($records as $position => $record) {
            $identifiers = $record['lookupIdentifiers'] ?? [];
            $primaryKey = self::comparisonKey($record['pid'], $record['pidType']);
            if (! array_any($identifiers, fn (array $identifier): bool => self::comparisonKey($identifier['value'], $identifier['type']) === $primaryKey)) {
                $identifiers[] = ['value' => $record['pid'], 'type' => $record['pidType'], 'source' => 'registry'];
            }
            $identifiersByRecord[$position] = $identifiers;
            foreach ($identifiers as $identifier) {
                $key = self::comparisonKey($identifier['value'], $identifier['type']);
                if ($key !== '') {
                    $index[$key][$position] = true;
                }
            }
        }

        $resolved = [];
        foreach ($instruments as $instrument) {
            // Older upload sessions can contain malformed entries.
            if (! is_string($instrument['pid'] ?? null) || ! is_string($instrument['pidType'] ?? null)) {
                continue;
            }
            $pid = $instrument['pid'];
            $type = $instrument['pidType'];
            $name = is_string($instrument['name'] ?? null) ? $instrument['name'] : $pid;
            $key = self::comparisonKey($pid, $type);
            $matches = array_keys($index[$key] ?? []);
            $resolution = ['status' => $available ? 'not_found' : 'unavailable'];
            if (count($matches) > 1) {
                $resolution = ['status' => 'ambiguous'];
            } elseif (count($matches) === 1) {
                $position = $matches[0];
                $recordName = trim($records[$position]['name']);
                if ($recordName !== '') {
                    if (trim($name) === '' || self::comparisonKey($name, $type) === $key) {
                        $name = mb_substr($recordName, 0, 1024);
                    }
                    $resolution = ['status' => 'matched'];
                }
                $valuesByType = [];
                foreach ($identifiersByRecord[$position] as $identifier) {
                    $identifierKey = self::comparisonKey($identifier['value'], $identifier['type']);
                    if ($identifierKey !== '') {
                        $valuesByType[$identifier['type']][$identifierKey] = true;
                    }
                }
                if (array_any($valuesByType, fn (array $values): bool => count($values) > 1)) {
                    $resolution = [
                        'status' => 'identifier_conflict',
                        'identifiers' => $identifiersByRecord[$position],
                    ];
                }
            }
            $resolved[] = ['pid' => $pid, 'pidType' => $type, 'name' => trim($name) === '' ? $pid : $name, 'resolution' => $resolution];
        }

        return $resolved;
    }

    /** Comparison only: do not use this value for storage or exports. */
    public static function comparisonKey(string $value, string $type): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $host = match ($type) {
            'Handle' => 'hdl.handle.net',
            'DOI' => 'doi.org',
            default => null,
        };
        $parts = parse_url($value);
        if ($host !== null && is_array($parts)
            && in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            && strtolower($parts['host'] ?? '') === $host
            && ! isset($parts['port'])
            && ! isset($parts['user']) && ! isset($parts['pass'])) {
            $value = ltrim($parts['path'] ?? '', '/');
        }

        // DOI comparisons ignore case; Handle suffixes and stored identifiers retain it.
        return $value === '' ? '' : $type.':'.($type === 'DOI' ? strtolower($value) : $value);
    }
}
