/**
 * Name Parsing Utilities for Old Database Contributors
 *
 * These functions parse contributor names from the legacy SUMARIOPMD database,
 * handling different name formats (comma-separated, single names, etc.)
 */

export interface ParsedName {
    firstName: string;
    lastName: string;
}

/**
 * Parse a contributor name string into firstName and lastName
 *
 * Handles the following formats:
 * - "LastName, FirstName" → splits on comma
 * - "SingleName" → treated as lastName only
 * - "" → returns empty strings
 *
 * @param name - The name string to parse (e.g., "Barthelmes, Franz" or "UNESCO")
 * @param givenName - Explicit given name if available (takes precedence)
 * @param familyName - Explicit family name if available (takes precedence)
 * @returns Object with firstName and lastName
 */
export function parseContributorName(name: string | null, givenName: string | null, familyName: string | null): ParsedName {
    // If explicit names are provided, use them directly
    if (givenName || familyName) {
        return {
            firstName: givenName || '',
            lastName: familyName || '',
        };
    }

    // No explicit names and no name string
    if (!name) {
        return {
            firstName: '',
            lastName: '',
        };
    }

    // Parse name string
    if (name.includes(',')) {
        // Format: "LastName, FirstName"
        const parts = name.split(',').map((p) => p.trim());
        return {
            lastName: parts[0] || '',
            firstName: parts[1] || '',
        };
    }

    // No comma - use entire name as lastName
    return {
        firstName: '',
        lastName: name,
    };
}
