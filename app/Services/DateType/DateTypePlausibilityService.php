<?php

declare(strict_types=1);

namespace App\Services\DateType; 

final class DateTypePlausibilityService
{
    /**
     * Warum?
     * Collected -> Daten wurden erhoben 
     * Created -> Daten wurden erstellt
     * Submitted -> Datensatz wurde eingereicht 
     * Accepted -> Datensatz wurde akzeptiert
     * Issued -> Datensatz wurde veröffentlicht
     * Available -> Öffentlich verfügbar
     */
    private const DATE_TYPE_ORDER = [
        ['Collected', 'Created'],
        ['Collected', 'Issued'],
        ['Created', 'Submitted'],
        ['Created', 'Accepted'],
        ['Created', 'Issued'],
        ['Submitted', 'Accepted'],
        ['Accepted', 'Issued'],
        ['Issued', 'Available'],
    ];
    
    /**
     * @param array<string, string> $dates
     * @return array<int, array<string, mixed>>
     */
    public function review(array $dates): array
    {
        $warnings = [];

        foreach (self::DATE_TYPE_ORDER as [$earlier, $later]) 
        {
            if (! isset($dates[$earlier], $dates[$later])) {
            continue;
            }

            if ($dates[$earlier] > $dates[$later])
            {
                $warnings[] = 
                [
                    'suggestion_kind' => 'review',
                    'message' => sprintf(
                        'Hint: %s (%s) occurs after %s (%s). Please check that the date types are assigned correctly.',
                        $earlier,
                        $dates[$earlier],
                        $later,
                        $dates[$later],
                    ),
                    'confidence' => 'medium',
                    'is_ambiguous' => true,
                ];
            }
        }
        return $warnings;
    }
}