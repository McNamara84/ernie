/**
 * Resolves an identifier to its full URL based on the identifier type.
 *
 * Supports the most common DataCite identifier types used in geosciences.
 * Returns null for unsupported types or invalid/empty identifiers —
 * callers skip rendering those items entirely.
 */
export function resolveIdentifierUrl(identifier: string, identifierType: string): string | null {
    const id = identifier.trim();

    if (!id) {
        return null;
    }

    switch (identifierType) {
        case 'DOI':
            return `https://doi.org/${id}`;
        case 'URL':
            return isSafeHttpUrl(id) ? id : null;
        case 'Handle':
            return `https://hdl.handle.net/${id}`;
        case 'arXiv':
            return `https://arxiv.org/abs/${id}`;
        case 'IGSN':
            return `https://igsn.org/${id}`;
        case 'ISBN':
            return `https://search.worldcat.org/isbn/${id}`;
        case 'ISSN':
            return `https://portal.issn.org/resource/ISSN/${id}`;
        case 'URN':
            return `https://nbn-resolving.org/${id}`;
        case 'RAiD':
            return `https://raid.org/${id}`;
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
