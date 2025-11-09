<?php

namespace App\Support;

/**
 * Name parsing utilities for old database name formats.
 *
 * Handles parsing of names stored in different formats in the legacy metaworks database:
 * - Separated: firstname and lastname in separate fields
 * - Combined: "Lastname, Firstname" format in single name field
 */
class NameParser
{
    /**
     * Parse a name string that may be in "Lastname, Firstname" format.
     *
     * This method handles the dual storage format in the old metaworks database:
     * 1. If explicit firstname/lastname are provided → use them directly
     * 2. If name contains comma → split on comma ("Lastname, Firstname")
     * 3. Otherwise → use entire name as lastname
     *
     * Examples:
     * - parsePersonName("Förste, Christoph", null, null) → ["Christoph", "Förste"]
     * - parsePersonName("UNESCO", null, null) → ["", "UNESCO"] (institution)
     * - parsePersonName("Barthelmes, Franz", "Franz", "Barthelmes") → ["Franz", "Barthelmes"]
     *
     * @param  string|null  $name  Full name string (may contain comma)
     * @param  string|null  $firstName  Explicit first name (takes precedence)
     * @param  string|null  $lastName  Explicit last name (takes precedence)
     * @return array{firstName: string, lastName: string} Parsed name components
     */
    public static function parsePersonName(?string $name, ?string $firstName, ?string $lastName): array
    {
        // If explicit names are provided, use them directly (most reliable)
        if (! empty($firstName) || ! empty($lastName)) {
            return [
                'firstName' => $firstName ?? '',
                'lastName' => $lastName ?? '',
            ];
        }

        // No explicit names and no name string
        if (empty($name)) {
            return [
                'firstName' => '',
                'lastName' => '',
            ];
        }

        // Check if name is in "Lastname, Firstname" format
        if (str_contains($name, ',')) {
            $parts = array_map('trim', explode(',', $name, 2));

            return [
                'firstName' => $parts[1] ?? '',
                'lastName' => $parts[0], // explode() with limit 2 always returns at least 1 element
            ];
        }

        // No comma - use entire name as lastName (likely institution or single name)
        return [
            'firstName' => '',
            'lastName' => $name,
        ];
    }

    /**
     * Determine if parsed name components represent a person (as opposed to an institution).
     *
     * A name is considered to represent a person if:
     * - It has a firstName component (indicating comma-separated "Lastname, Firstname" format or explicit fields)
     *
     * This is a heuristic based on the old metaworks database structure where:
     * - Person names: stored as "Lastname, Firstname" OR in separate firstname/lastname fields
     * - Institution names: stored as single name without comma (e.g., "UNESCO", "Centre for Early Warning")
     *
     * @param  array{firstName: string, lastName: string}  $parsedName  Result from parsePersonName()
     * @return bool True if the name represents a person, false if it likely represents an institution
     */
    public static function isPerson(array $parsedName): bool
    {
        return ! empty($parsedName['firstName']);
    }
}
