/**
 * Resolves an identifier to its full URL based on the identifier type.
 *
 * Supports the most common DataCite identifier types used in geosciences.
 * Returns null for unsupported types — callers should display the raw identifier as text.
 */
export function resolveIdentifierUrl(identifier: string, identifierType: string): string | null {
    switch (identifierType) {
        case 'DOI':
            return `https://doi.org/${identifier}`;
        case 'URL':
            return identifier;
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
