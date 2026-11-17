<?php

declare(strict_types=1);

namespace App\Services;

/** Bounded settings diagnostics; the full report remains in the private snapshot. */
final class Pid4instUpdateDiagnosticsService
{
    /**
     * @param  array<string, mixed>  $snapshot
     * @return array{excludedCount: int, exclusions: list<array{id: string, reasonCode: string, source?: string}>, refreshPending: bool}
     */
    public static function fromSnapshot(array $snapshot): array
    {
        $excluded = data_get($snapshot, '_sync.excluded', []);
        $count = 0;
        $details = [];
        foreach (is_array($excluded) ? $excluded : [] as $entry) {
            if (! is_array($entry) || ! is_string($entry['id'] ?? null)) {
                continue;
            }
            $count++;
            if (count($details) < 10) {
                $details[] = [
                    'id' => $entry['id'],
                    'reasonCode' => is_string($entry['reasonCode'] ?? null) ? $entry['reasonCode'] : 'invalid_record',
                    ...(in_array($entry['source'] ?? null, ['b2inst', 'datacite'], true) ? ['source' => $entry['source']] : []),
                ];
            }
        }

        return [
            'excludedCount' => $count,
            'exclusions' => $details,
            'refreshPending' => data_get($snapshot, '_sync.refreshPending') === true,
        ];
    }
}
