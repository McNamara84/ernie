<?php

declare(strict_types=1);

namespace App\Services\DateType; 


final class DateTypePlausibilityService
{
    /**
     * Warum diese Reihenfolge?
     * Collected -> Daten wurden erhoben 
     * Created -> Daten wurden erstellt
     * Submitted -> Datensatz wurde eingereicht 
     * Accepted -> Datensatz wurde akzeptiert
     * Issued -> Datensatz wurde veröffentlicht
     * Available -> Öffentlich verfügbar
     */
    private const DATE_VALUE_ORDER = [
        ['Collected', 'Created'],
        ['Collected', 'Submitted'],
        ['Collected', 'Accepted'],
        ['Collected', 'Issued'],
        ['Collected', 'Available'],
        ['Created', 'Submitted'],
        ['Created', 'Accepted'],
        ['Created', 'Issued'],
        ['Created', 'Available'],
        ['Submitted', 'Accepted'],
        ['Submitted', 'Issued'],
        ['Submitted', 'Available'],
        ['Accepted', 'Issued'],
        ['Accepted', 'Available'],
        ['Issued', 'Available'],
    ];
    
    /**
     * @param array<string, string> $dates
     * @return array<int, array<string, mixed>>
     */
    public function hint(array $dates, ?string $resourceDoi = null) : array
    {
        $grouped = [];

        foreach (self::DATE_VALUE_ORDER as [$earlier, $later]) {
            if (! isset($dates[$earlier], $dates[$later])) {
                continue;
            }

            $valueOrderWrong = $dates[$earlier] > $dates[$later];
            
            if ($valueOrderWrong) {
                $grouped[$earlier][] = [
                    'type' => $later,
                    'value' => $dates[$later],
                ];
            }
        }

        $warnings = [];

        foreach ($grouped as $earlier => $conflicts) 
        {
            $warnings[] = $this->warning(
                $earlier,
                $dates[$earlier],
                $conflicts,
                $resourceDoi,
            );

        }
        return $warnings;     
    }

    /**
     * @param array<int, array{type: string, value: string}> $conflicts
     * @return array<string, mixed>
     */
    private function warning(string $earlier, string $earlierValue, array $conflicts, ?string $resourceDoi = null,): array
    {
        $conflictText = implode(', ', array_map(
            fn (array $conflict): string => sprintf(
                '%s (%s)',
                $conflict['type'],
                $conflict['value'],
            ),
            $conflicts,
        ));

        return [
            'suggestion_kind' => 'hint',
            'message' => sprintf(
                '%s (%s) occurs after %s. Please check whether the date values or date types are assigned correctly.',
                $earlier,
                $earlierValue,
                $conflictText,
            ),
            'confidence' => 'medium',
            'is_ambiguous' => true,
            'source_url' => $resourceDoi ? 'https://doi.org/'.$resourceDoi : null,
        ];
    }
}