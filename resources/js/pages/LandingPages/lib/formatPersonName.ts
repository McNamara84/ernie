/**
 * Formats a person's name defensively, handling null values.
 */
export function formatPersonName(familyName: string | null, givenName: string | null): string {
    if (familyName && givenName) return `${familyName}, ${givenName}`;
    if (familyName) return familyName;
    if (givenName) return givenName;
    return 'Unknown';
}
