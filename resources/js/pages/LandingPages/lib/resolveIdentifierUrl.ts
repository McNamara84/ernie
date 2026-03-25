/**
 * Resolves an identifier to its full URL based on the identifier type.
 *
 * Supports the most common DataCite identifier types used in geosciences.
 * Returns null for unsupported types or invalid/empty identifiers —
 * callers should display the raw identifier as text in that case.
 */
export function resolveIdentifierUrl(identifier: string, identifierType: string): string | null {
    if (!identifier.trim()) {
        return null;
    }

    switch (identifierType) {
        case 'DOI':
            return `https://doi.org/${identifier}`;
        case 'URL':
            return isSafeHttpUrl(identifier) ? identifier : null;
        case 'Handle':
            return `https://hdl.handle.net/${identifier}`;
        case 'arXiv':
            return `https://arxiv.org/abs/${identifier}`;
        case 'IGSN':
            return `https://igsn.org/${identifier}`;
        case 'ISBN':
            return `https://search.worldcat.org/isbn/${identifier}`;
        case 'ISSN':
            return `https://portal.issn.org/resource/ISSN/${identifier}`;
        case 'URN':
            return `https://nbn-resolving.org/${identifier}`;
        case 'RAiD':
            return `https://doi.org/${identifier}`;
        default:
            return null;
    }
}

/**
 * Validates that a URL string uses a safe HTTP(S) scheme.
 * Rejects javascript:, data:, and other dangerous schemes.
 */
function isSafeHttpUrl(url: string): boolean {
    try {
        const parsed = new URL(url);
        return parsed.protocol === 'http:' || parsed.protocol === 'https:';
    } catch {
        return false;
    }
}
