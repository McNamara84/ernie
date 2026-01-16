import type { IdentifierType } from '@/types';

/**
 * Auto-detect identifier type from the input value.
 *
 * Supports detection of:
 * - DOI (Digital Object Identifier)
 * - arXiv (arXiv preprint identifiers)
 * - bibcode (ADS Bibliographic Code)
 * - ARK (Archival Resource Key)
 * - Handle (Handle System identifiers)
 * - URL (Uniform Resource Locator)
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

    // arXiv URL patterns (must be checked before generic URL)
    // Matches: https://arxiv.org/abs/XXXX.XXXXX, /pdf/XXXX.XXXXX, /html/XXXX.XXXXX, /src/XXXX.XXXXX
    // Also matches old format: https://arxiv.org/abs/category/YYMMNNN (e.g., hep-th/9901001)
    if (trimmed.match(/^https?:\/\/arxiv\.org\/(?:abs|pdf|html|src)\/\S+/i)) {
        return 'arXiv';
    }

    // arXiv with prefix (arXiv:XXXX.XXXXX or arXiv:XXXX.XXXXXvN)
    // New format (April 2007+): arXiv:YYMM.NNNNN (e.g., arXiv:2501.13958)
    // Old format (pre-April 2007): arXiv:category/YYMMNNN (e.g., arXiv:hep-th/9901001)
    if (trimmed.match(/^arxiv:/i)) {
        return 'arXiv';
    }

    // arXiv bare new format: YYMM.NNNNN or YYMM.NNNNNvN (version suffix)
    // Year-month must be valid (07-99 for 2007-2099, or 00-06 for 2100+)
    // Must have 4-5 digit paper number
    if (trimmed.match(/^\d{4}\.\d{4,5}(v\d+)?$/)) {
        return 'arXiv';
    }

    // arXiv old format bare: category/YYMMNNN (e.g., hep-th/9901001, astro-ph/9310023)
    // Categories include: hep-th, hep-ph, hep-lat, hep-ex, astro-ph, cond-mat, gr-qc, quant-ph, etc.
    if (trimmed.match(/^[a-z-]+\/\d{7}$/i)) {
        return 'arXiv';
    }

    // Bibcode URL patterns (ADS - Astrophysics Data System)
    // Matches: https://ui.adsabs.harvard.edu/abs/BIBCODE or http://adsabs.harvard.edu/abs/BIBCODE
    if (trimmed.match(/^https?:\/\/(?:ui\.)?adsabs\.harvard\.edu\/abs\/\S+/i)) {
        return 'bibcode';
    }

    // Bibcode compact format: 19 characters - YYYYJJJJJVVVVMPPPPA
    // YYYY = 4-digit year (1800-2099)
    // JJJJJ = 5-char journal code (may include &, dots)
    // VVVV = 4-char volume (dots for padding)
    // M = qualifier (L for letter, . for normal, A for article)
    // PPPP = 4-char page (dots for padding)
    // A = first letter of first author's last name
    // Examples: 2024AJ....167...20Z, 1970ApJ...161L..77K, 2024A&A...687A..74T
    if (trimmed.match(/^\d{4}[A-Za-z&.]{5}[A-Za-z0-9.]{4}[A-Za-z.][A-Za-z0-9.]{4}[A-Za-z]$/i)) {
        return 'bibcode';
    }

    // Bibcode special formats (non-standard journal codes like arXiv, jwst.prop)
    // Format: YYYYcode......restA (variable structure for special sources)
    // Examples: 2024arXiv240413032B, 2023jwst.prop.4537H
    if (trimmed.match(/^\d{4}(?:arXiv|jwst\.prop|PhDT|Sci|Natur)\S+[A-Za-z]$/i)) {
        return 'bibcode';
    }

    // ARK with resolver URL patterns (must be checked before generic URL)
    // Matches various ARK resolvers: n2t.net, ark.bnf.fr, familysearch.org, archive.org, data.bnf.fr, etc.
    // ARK format in URL: https://resolver/ark:/NAAN/Name or https://resolver/ark:NAAN/Name
    if (trimmed.match(/^https?:\/\/[^/]+\/ark:\/?\d{5,}\/\S+/i)) {
        return 'ARK';
    }

    // ARK compact format: ark:NAAN/Name or ark:/NAAN/Name
    // NAAN (Name Assigning Authority Number) is typically 5 digits
    if (trimmed.match(/^ark:\/?\d{5,}\/\S+/i)) {
        return 'ARK';
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
