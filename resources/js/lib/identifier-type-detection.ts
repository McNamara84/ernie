import type { IdentifierType } from '@/types';

/**
 * Auto-detect identifier type from the input value.
 *
 * Supports detection of:
 * - DOI (Digital Object Identifier)
 * - URL (Uniform Resource Locator)
 * - Handle (Handle System identifiers)
 * - More identifier types to be added
 *
 * @param value - The identifier string to analyze
 * @returns The detected IdentifierType
 */
export function detectIdentifierType(value: string): IdentifierType {
    const trimmed = value.trim();

    // DOI with URL prefix (https://doi.org/... or https://dx.doi.org/...)
    const doiUrlMatch = trimmed.match(/^https?:\/\/(?:doi\.org|dx\.doi\.org)\/(.+)/i);
    if (doiUrlMatch) {
        return 'DOI';
    }

    // DOI with doi: prefix (e.g., doi:10.1371/journal.pbio.0020449)
    if (trimmed.match(/^doi:/i)) {
        return 'DOI';
    }

    // DOI patterns (without URL prefix) - starts with 10. followed by registrant code
    if (trimmed.match(/^10\.\d{4,}/)) {
        return 'DOI';
    }

    // Handle URL patterns (must be checked before generic URL)
    // Matches: http://hdl.handle.net/prefix/suffix or https://hdl.handle.net/prefix/suffix
    if (trimmed.match(/^https?:\/\/hdl\.handle\.net\/\S+/i)) {
        return 'Handle';
    }

    // URL patterns
    if (trimmed.match(/^https?:\/\//i)) {
        return 'URL';
    }

    // Handle patterns (bare format: prefix/suffix where prefix is numeric)
    if (trimmed.match(/^\d+\/\S+$/)) {
        return 'Handle';
    }

    // Default to DOI if it looks like one (contains slash, no spaces)
    if (trimmed.includes('/') && !trimmed.includes(' ')) {
        return 'DOI';
    }

    return 'URL';
}

/**
 * Normalize an identifier for comparison and storage.
 *
 * For DOIs:
 * - Removes URL prefixes (https://doi.org/, https://dx.doi.org/)
 * - Removes doi: prefix
 *
 * @param identifier - The identifier to normalize
 * @param identifierType - The type of identifier
 * @returns The normalized identifier
 */
export function normalizeIdentifier(identifier: string, identifierType: IdentifierType): string {
    if (identifierType === 'DOI') {
        let normalized = identifier.trim();

        // Remove URL prefix from DOI
        const doiUrlMatch = normalized.match(/^https?:\/\/(?:doi\.org|dx\.doi\.org)\/(.+)/i);
        if (doiUrlMatch) {
            normalized = doiUrlMatch[1];
        }

        // Remove doi: prefix
        const doiPrefixMatch = normalized.match(/^doi:(.+)/i);
        if (doiPrefixMatch) {
            normalized = doiPrefixMatch[1];
        }

        return normalized;
    }
    return identifier;
}
