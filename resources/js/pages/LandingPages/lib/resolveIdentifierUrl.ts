import { validateDOIFormat } from '@/lib/doi-validation';

/**
 * Resolves an identifier to its full URL based on the identifier type.
 *
 * Supports the most common DataCite identifier types used in geosciences.
 * Returns null for unsupported types or invalid/empty identifiers. Callers may
 * still render the original value as plain text, but must not make it clickable.
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
            return validateDOIFormat(doi).isValid ? `https://doi.org/${doi}` : null;
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
            return resolveIgsnUrl(id);
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
 * Resolve the three IGSN representations stored in legacy and current records.
 *
 * Legacy Handle IGSNs must use the Handle resolver directly. Prefixing those
 * values with igsn.org produces a broken double Handle path such as
 * `10273/10273/...`. Modern DOI-shaped IGSNs use doi.org, while bare IGSN codes
 * continue to use igsn.org.
 */
export function resolveIgsnUrl(value: string): string | null {
    const id = value
        .trim()
        .replace(/^https?:\/\/(?:www\.)?igsn\.org\/?/i, '')
        .replace(/^https?:\/\/hdl\.handle\.net\/?/i, '');

    if (!id) {
        return null;
    }

    const doi = normalizeDoiKey(id);
    if (validateDOIFormat(doi).isValid) {
        return `https://doi.org/${doi}`;
    }

    if (/^10273\/[A-Za-z0-9][A-Za-z0-9._-]*$/.test(id)) {
        return `https://hdl.handle.net/${id}`;
    }

    if (/^[A-Za-z0-9][A-Za-z0-9._-]{2,}$/.test(id)) {
        return `https://igsn.org/${id}`;
    }

    return null;
}

/**
 * Strips common DOI resolver URL and doi: prefixes and trims whitespace,
 * returning the bare DOI. Exported so callers can normalize DOI keys
 * consistently (deduplication, cache keys, display text).
 */
export function normalizeDoiKey(value: string): string {
    return value
        .trim()
        .replace(/^https?:\/\/(dx\.)?doi\.org\/?/i, '')
        .replace(/^doi:\s*/i, '');
}

/** Strips the Handle resolver URL prefix, returning the bare handle. */
function stripHandlePrefix(value: string): string {
    return value.replace(/^https?:\/\/hdl\.handle\.net\/?/i, '');
}

/**
 * Validates that a URL string uses a safe HTTP(S) scheme.
 * Rejects javascript:, data:, and other dangerous schemes.
 */
export function isSafeHttpUrl(url: string): boolean {
    try {
        const parsed = new URL(url);
        return parsed.protocol === 'http:' || parsed.protocol === 'https:';
    } catch {
        return false;
    }
}
