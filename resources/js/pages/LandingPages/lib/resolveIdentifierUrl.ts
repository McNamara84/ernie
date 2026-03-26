/**
 * Resolves an identifier to its full URL based on the identifier type.
 *
 * Supports the most common DataCite identifier types used in geosciences.
 * Returns null for unsupported types or invalid/empty identifiers —
 * callers skip rendering those items entirely.
 *
 * DOI and Handle identifiers are normalized: if the stored value is already
 * a full resolver URL (e.g. https://doi.org/10..., https://dx.doi.org/10...,
 * https://hdl.handle.net/...), the bare identifier is extracted first to
 * avoid double-prefixing.
 */
export function resolveIdentifierUrl(identifier: string, identifierType: string): string | null {
    const id = identifier.trim();

    if (!id) {
        return null;
    }

    switch (identifierType) {
        case 'DOI': {
            const doi = normalizeDoiKey(id);
            return doi ? `https://doi.org/${doi}` : null;
        }
        case 'URL':
            return isSafeHttpUrl(id) ? id : null;
        case 'Handle': {
            const handle = stripHandlePrefix(id);
            return handle ? `https://hdl.handle.net/${handle}` : null;
        }
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
 * Strips common DOI resolver URL prefixes and trims whitespace,
 * returning the bare DOI. Exported so callers can normalize DOI keys
 * consistently (deduplication, cache keys, display text).
 */
export function normalizeDoiKey(value: string): string {
    return value
        .trim()
        .replace(/^https?:\/\/(dx\.)?doi\.org\//i, '');
}

/** Strips the Handle resolver URL prefix, returning the bare handle. */
function stripHandlePrefix(value: string): string {
    return value
        .replace(/^https?:\/\/hdl\.handle\.net\//i, '');
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
