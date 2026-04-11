/**
 * Abbreviates a given name to initials for citation display.
 *
 * Rules:
 * - Each space-separated part is abbreviated independently
 * - Hyphenated parts preserve the hyphen (Jean-Pierre → J.-P.)
 * - Already-abbreviated names with dot (e.g. "A.") pass through unchanged
 * - Single letters without dot get a dot appended (e.g. "A" → "A.")
 * - Null/empty input returns empty string
 *
 * @example
 * abbreviateGivenName("Alice")          // "A."
 * abbreviateGivenName("Alice Marie")    // "A. M."
 * abbreviateGivenName("Jean-Pierre")    // "J.-P."
 * abbreviateGivenName("Hans-Jürgen Peter") // "H.-J. P."
 * abbreviateGivenName(null)             // ""
 */
export function abbreviateGivenName(givenName: string | null | undefined): string {
    if (!givenName || givenName.trim() === '') {
        return '';
    }

    return givenName
        .trim()
        .split(/\s+/)
        .map((part) =>
            part
                .split('-')
                .map((sub) => {
                    // Already abbreviated (e.g. "A." or "A")
                    if (sub.length <= 2 && (sub.length === 1 || sub.endsWith('.'))) {
                        return sub.endsWith('.') ? sub : `${sub}.`;
                    }
                    return `${sub.charAt(0).toUpperCase()}.`;
                })
                .join('-'),
        )
        .join(' ');
}
