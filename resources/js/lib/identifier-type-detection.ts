import type { IdentifierType } from '@/types';

// Pre-compiled regex patterns for better performance
// These are compiled once at module load time, not on every function call

// IGSN patterns
const IGSN_DOI_URL = /^https?:\/\/(?:doi\.org|dx\.doi\.org)\/10\.(?:60516|58052|60510|58108|58095)\/\S+/i;
const IGSN_LEGACY_URL = /^https?:\/\/igsn\.org\/10\.273\/\S+/i;
const IGSN_DOI_BARE = /^10\.(?:60516|58052|60510|58108|58095)\/\S+$/;
const IGSN_LEGACY_BARE = /^10\.273\/\S+$/;
const IGSN_PREFIX = /^igsn:?\s*[A-Za-z0-9]+$/i;
const IGSN_URN = /^urn:igsn:[A-Za-z0-9]+$/i;
const IGSN_BARE_CODE = /^(?:AU|SSH|BGR[A-Z]?|ICDP|CSR[A-Z]?|GFZ|MBCR|ARDC)[A-Z0-9]{2,12}$/i;

// Handle patterns
const HANDLE_WDCC = /^10\.1594\/\S+$/;
const HANDLE_HDL_URL = /^https?:\/\/hdl\.handle\.net\/(?:api\/handles\/)?\S+/i;
const HANDLE_HDL_PROTOCOL = /^hdl:\/\/\S+/i;
const HANDLE_URN = /^urn:handle:\S+/i;
const HANDLE_CUSTOM_RESOLVER = /^https?:\/\/[^/]+\/objects\/\d+(?:\.\w+)?\/\S+/i;
const HANDLE_BARE = /^\d+(?:\.\w+)?\/\S+$/;

// DOI patterns
const DOI_INVALID_SPACES = /^10\.\d+\s+\S/;
const DOI_URL = /^https?:\/\/(?:doi\.org|dx\.doi\.org)\/(.+)/i;
const DOI_PREFIX = /^doi:/i;
const DOI_BARE = /^10\.\d{4,}/;

// arXiv patterns
const ARXIV_URL = /^https?:\/\/arxiv\.org\/(?:abs|pdf|html|src)\/\S+/i;
const ARXIV_PREFIX = /^arxiv:/i;
const ARXIV_NEW_FORMAT = /^\d{4}\.\d{4,5}(v\d+)?$/;
const ARXIV_OLD_FORMAT = /^[a-z-]+\/\d{7}$/i;

// Bibcode patterns
const BIBCODE_URL = /^https?:\/\/(?:ui\.)?adsabs\.harvard\.edu\/abs\/\S+/i;
const BIBCODE_STANDARD = /^\d{4}[A-Za-z&.]{5}[A-Za-z0-9.]{4}[A-Za-z.][A-Za-z0-9.]{4}[A-Za-z]$/i;
const BIBCODE_SPECIAL = /^\d{4}(?:arXiv|jwst\.prop|PhDT|Sci|Natur)\S+[A-Za-z]$/i;

// CSTR patterns
const CSTR_URL = /^https?:\/\/(?:identifiers\.org|bioregistry\.io)\/cstr:/i;
const CSTR_PREFIX = /^cstr:\d{5}\.\d{2}\.\S+/i;
const CSTR_BARE = /^\d{5}\.\d{2}\.[A-Za-z_][A-Za-z0-9_.~-]*\.\S+$/;

// ISBN patterns
const ISBN_OPENEDITION_1 = /^https?:\/\/isbn\.openedition\.org\/97[89]/i;
const ISBN_OPENEDITION_2 = /^https?:\/\/books\.openedition\.org\/isbn\/97[89]/i;
const ISBN_URN = /^urn:isbn:/i;
const ISBN_PREFIX = /^isbn(?:-?(?:13|10))?[:\s]+/i;
const ISBN_13 = /^97[89]\d{10}$/;
const ISBN_10 = /^\d{9}[\dXx]$/;

// EAN-13 patterns
const EAN13_URL = /^https?:\/\/(?:identifiers\.org\/ean13:|gs1\.[^/]+\/01\/)/i;
const EAN13_URN = /^urn:(?:ean13|gtin(?:-13)?):[\d-]+$/i;
const EAN13_BARE = /^\d{13}$/;
const EAN13_NOT_ISBN = /^97[89]/;

// LSID patterns
const LSID_URN = /^urn:lsid:[a-z0-9.-]+:[a-z0-9._-]+:[a-z0-9._-]+(?::\d+)?$/i;
const LSID_IO_URL = /^https?:\/\/lsid\.io\/urn:lsid:[a-z0-9.-]+:[a-z0-9._-]+:[a-z0-9._-]+(?::\d+)?$/i;
const LSID_SERVICE_LOCATOR = /^https?:\/\/[a-z0-9.-]+\/ws\/services\/ServiceLocator\?lsid=urn:lsid:[a-z0-9.-]+:[a-z0-9._-]+:[a-z0-9._-]+(?::\d+)?$/i;
const LSID_ZOOBANK = /^https?:\/\/zoobank\.org\/urn:lsid:zoobank\.org:[a-z0-9._-]+:[a-z0-9._-]+$/i;

// PMID patterns
const PMID_PUBMED_URL = /^https?:\/\/pubmed\.ncbi\.nlm\.nih\.gov\/\d{1,9}$/i;
const PMID_LEGACY_URL = /^https?:\/\/(?:www\.)?ncbi\.nlm\.nih\.gov\/pubmed\/\d{1,9}$/i;
const PMID_PREFIX = /^(?:pmid|pubmed\s*id):?\s*\d{1,9}$/i;
const PMID_SEARCH_FIELD = /^\d{1,9}\s*\[(?:pmid|uid)\]$/i;

// w3id patterns
const W3ID_URL = /^https?:\/\/w3id\.org\/[a-z0-9._\/-]+(?:#[a-z0-9._-]*)?$/i;

// PURL patterns
const PURL_ORG = /^https?:\/\/purl\.org\/[a-z0-9._\/-]+$/i;
const PURL_OCLC = /^https?:\/\/purl\.oclc\.org\/[a-z0-9._\/-]+$/i;
const PURL_LIB = /^https?:\/\/purl\.lib\.[a-z0-9.-]+\/[a-z0-9._\/?=&-]+$/i;
const PURL_GENERIC = /^https?:\/\/purl\.[a-z0-9.-]+\.(?:org|edu)\/[a-z0-9._\/-]+$/i;

// RRID patterns
const RRID_PREFIX = /^rrid:?\s*[a-z]+[_:]?[a-z0-9_:-]+$/i;
const RRID_SCICRUNCH = /^https?:\/\/scicrunch\.org\/resolver\/RRID:[a-z]+[_:]?[a-z0-9_:-]+$/i;
const RRID_SITE = /^https?:\/\/rrid\.site\/RRID:[a-z]+[_:]?[a-z0-9_:-]+$/i;

// UPC patterns
const UPC_PREFIX = /^(?:upc-?a?|gtin-?12):?\s*(\d[\d\s-]{10,14}\d)$/i;
const UPC_E = /^upc-?e:?\s*\d{8}$/i;

// LISSN patterns
const LISSN_URL = /^https?:\/\/portal\.issn\.org\/resource\/ISSN-L\/\d{4}-?\d{3}[\dXx]$/i;
const LISSN_PREFIX = /^(?:lissn|issn-l):?\s*\d{4}-?\d{3}[\dXx]$/i;

// EISSN patterns
const EISSN_URL = /^https?:\/\/(?:portal\.issn\.org\/resource\/ISSN\/|identifiers\.org\/issn:|www\.worldcat\.org\/issn\/)\d{4}-?\d{3}[\dXx]$/i;
const EISSN_URN = /^urn:issn:\d{4}-?\d{3}[\dXx]$/i;
const EISSN_PREFIX = /^(?:e-?issn|p-?issn|issn):?\s*\d{4}-?\d{3}[\dXx]$/i;
const EISSN_PARENTHETICAL = /^issn\s+\d{4}-?\d{3}[\dXx]\s*\((?:online|print)\)$/i;
const EISSN_STANDARD = /^\d{4}-\d{3}[\dXx]$/;
const EISSN_COMPACT = /^\d{7}[\dXx]$/i;

// ISTC patterns
const ISTC_URN = /^urn:istc:[0-9A-Ja-j-]+$/i;
const ISTC_PREFIX = /^istc\s*(?:\([^)]+\))?:?\s*[0-9A-Ja-j]{3}-?[0-9]{4}-?[0-9A-Ja-j]{4}-?[0-9A-Ja-j]{4}-?[0-9A-Ja-j]$/i;
const ISTC_HYPHENATED = /^[0-9A-Ja-j]{3}-[0-9]{4}-[0-9A-Ja-j]{4}-[0-9A-Ja-j]{4}-[0-9A-Ja-j]$/i;
const ISTC_COMPACT = /^[0-9A-Ja-j]{3}[0-9]{4}[0-9A-Ja-j]{9}$/i;

// ARK patterns
const ARK_URL = /^https?:\/\/[^/]+(?:\/[^/]+)*\/ark:\/?\d{5,}\/\S+/i;
const ARK_COMPACT = /^ark:\/?\d{5,}\/\S+/i;

// URN patterns
const URN_NBN_DE = /^https?:\/\/nbn-resolving\.(?:de|org)\/urn:[a-z0-9][a-z0-9-]{0,31}:\S+$/i;
const URN_FI = /^https?:\/\/urn\.fi\/urn:[a-z0-9][a-z0-9-]{0,31}:\S+$/i;
const URN_SE = /^https?:\/\/urn\.kb\.se\/resolve\?urn=urn:[a-z0-9][a-z0-9-]{0,31}:\S+$/i;
const URN_NL = /^https?:\/\/persistent-identifier\.nl\/urn:[a-z0-9][a-z0-9-]{0,31}:\S+$/i;
const URN_N2T = /^https?:\/\/n2t\.net\/urn:[a-z0-9][a-z0-9-]{0,31}:\S+$/i;
const URN_GENERIC = /^urn:(?!isbn:|lsid:|igsn:|issn:|istc:|handle:)[a-z0-9][a-z0-9-]{0,31}:\S+$/i;

// URL pattern
const URL_HTTP = /^https?:\/\//i;

/**
 * Auto-detect identifier type from the input value.
 *
 * Performance optimized version with pre-compiled regex patterns.
 * Supports detection of: DOI, arXiv, bibcode, ARK, Handle, URL, URN, w3id,
 * ISBN, ISSN, LISSN, EISSN, IGSN, PMID, RRID, UPC, PURL, LSID, ISTC, CSTR, EAN13
 *
 * @param value - The identifier string to analyze
 * @returns The detected IdentifierType
 */
export function detectIdentifierType(value: string): IdentifierType {
    const trimmed = value.trim();
    const len = trimmed.length;

    // Fast path: empty or very short strings
    if (len === 0) return 'URL';

    // Get first character for fast prefix checks
    const firstChar = trimmed[0].toLowerCase();
    const firstTwo = len >= 2 ? trimmed.substring(0, 2).toLowerCase() : '';

    // Early rejection: DOI-like pattern with spaces is invalid
    if (firstChar === '1' && DOI_INVALID_SPACES.test(trimmed)) {
        return 'URL';
    }

    // === URL-based identifiers (most common case - check early) ===
    if (firstChar === 'h') {
        // IGSN DOI URLs
        if (IGSN_DOI_URL.test(trimmed)) return 'IGSN';
        if (IGSN_LEGACY_URL.test(trimmed)) return 'IGSN';

        // DOI URLs
        if (DOI_URL.test(trimmed)) return 'DOI';

        // arXiv URLs
        if (ARXIV_URL.test(trimmed)) return 'arXiv';

        // Bibcode URLs
        if (BIBCODE_URL.test(trimmed)) return 'bibcode';

        // CSTR URLs
        if (CSTR_URL.test(trimmed)) return 'CSTR';

        // ISBN OpenEdition URLs
        if (ISBN_OPENEDITION_1.test(trimmed)) return 'ISBN';
        if (ISBN_OPENEDITION_2.test(trimmed)) return 'ISBN';

        // LSID URLs
        if (LSID_IO_URL.test(trimmed)) return 'LSID';
        if (LSID_SERVICE_LOCATOR.test(trimmed)) return 'LSID';
        if (LSID_ZOOBANK.test(trimmed)) return 'LSID';

        // PMID URLs
        if (PMID_PUBMED_URL.test(trimmed)) return 'PMID';
        if (PMID_LEGACY_URL.test(trimmed)) return 'PMID';

        // w3id URLs (must be before PURL)
        if (W3ID_URL.test(trimmed)) return 'w3id';

        // PURL URLs
        if (PURL_ORG.test(trimmed)) return 'PURL';
        if (PURL_OCLC.test(trimmed)) return 'PURL';
        if (PURL_LIB.test(trimmed)) return 'PURL';
        if (PURL_GENERIC.test(trimmed)) return 'PURL';

        // RRID URLs
        if (RRID_SCICRUNCH.test(trimmed)) return 'RRID';
        if (RRID_SITE.test(trimmed)) return 'RRID';

        // LISSN URLs
        if (LISSN_URL.test(trimmed)) return 'LISSN';

        // EISSN URLs
        if (EISSN_URL.test(trimmed)) return 'EISSN';

        // EAN-13 URLs
        if (EAN13_URL.test(trimmed)) return 'EAN13';

        // ARK URLs
        if (ARK_URL.test(trimmed)) return 'ARK';

        // Handle URLs
        if (HANDLE_HDL_URL.test(trimmed)) return 'Handle';
        if (HANDLE_CUSTOM_RESOLVER.test(trimmed)) return 'Handle';

        // URN resolver URLs
        if (URN_NBN_DE.test(trimmed)) return 'URN';
        if (URN_FI.test(trimmed)) return 'URN';
        if (URN_SE.test(trimmed)) return 'URN';
        if (URN_NL.test(trimmed)) return 'URN';
        if (URN_N2T.test(trimmed)) return 'URN';

        // Generic URL (fallback for http/https)
        if (URL_HTTP.test(trimmed)) return 'URL';
    }

    // === Numeric prefix identifiers (10., digits) ===
    if (firstChar === '1' && firstTwo === '10') {
        // Check for Handle-only prefix first
        if (HANDLE_WDCC.test(trimmed)) return 'Handle';
        // IGSN DOI prefixes
        if (IGSN_DOI_BARE.test(trimmed)) return 'IGSN';
        if (IGSN_LEGACY_BARE.test(trimmed)) return 'IGSN';
        // DOI bare format
        if (DOI_BARE.test(trimmed)) return 'DOI';
    }

    // === URN-based identifiers ===
    if (firstChar === 'u' && trimmed.substring(0, 4).toLowerCase() === 'urn:') {
        // Specific URN namespaces first (more specific before generic)
        if (IGSN_URN.test(trimmed)) return 'IGSN';
        if (ISBN_URN.test(trimmed)) return 'ISBN';
        if (LSID_URN.test(trimmed)) return 'LSID';
        if (EISSN_URN.test(trimmed)) return 'EISSN';
        if (ISTC_URN.test(trimmed)) return 'ISTC';
        if (EAN13_URN.test(trimmed)) return 'EAN13';
        if (HANDLE_URN.test(trimmed)) return 'Handle';
        // Generic URN (after specific namespaces)
        if (URN_GENERIC.test(trimmed)) return 'URN';
    }

    // === Prefix-based identifiers ===

    // arXiv prefix
    if (firstChar === 'a' && ARXIV_PREFIX.test(trimmed)) return 'arXiv';

    // ARK prefix
    if (firstChar === 'a' && ARK_COMPACT.test(trimmed)) return 'ARK';

    // DOI prefix
    if (firstChar === 'd' && DOI_PREFIX.test(trimmed)) return 'DOI';

    // Handle hdl: protocol
    if (firstChar === 'h' && HANDLE_HDL_PROTOCOL.test(trimmed)) return 'Handle';

    // IGSN prefix
    if (firstChar === 'i' && IGSN_PREFIX.test(trimmed)) return 'IGSN';

    // ISBN prefix
    if (firstChar === 'i' && ISBN_PREFIX.test(trimmed)) return 'ISBN';

    // ISSN/LISSN/EISSN prefixes
    if (firstChar === 'i' || firstChar === 'l' || firstChar === 'e' || firstChar === 'p') {
        if (LISSN_PREFIX.test(trimmed)) return 'LISSN';
        if (EISSN_PREFIX.test(trimmed)) return 'EISSN';
        if (EISSN_PARENTHETICAL.test(trimmed)) return 'EISSN';
        if (ISTC_PREFIX.test(trimmed)) return 'ISTC';
    }

    // CSTR prefix
    if (firstChar === 'c' && CSTR_PREFIX.test(trimmed)) return 'CSTR';

    // PMID prefix
    if (firstChar === 'p' && PMID_PREFIX.test(trimmed)) return 'PMID';

    // RRID prefix
    if (firstChar === 'r' && RRID_PREFIX.test(trimmed)) return 'RRID';

    // UPC prefix
    if (firstChar === 'u' || firstChar === 'g') {
        const upcMatch = trimmed.match(UPC_PREFIX);
        if (upcMatch) {
            const digitsOnly = upcMatch[1].replace(/[^\d]/g, '');
            if (digitsOnly.length === 12) return 'UPC';
        }
        if (UPC_E.test(trimmed)) return 'UPC';
    }

    // === Bare numeric formats ===

    // arXiv new format: YYMM.NNNNN
    if (firstChar >= '0' && firstChar <= '9') {
        if (ARXIV_NEW_FORMAT.test(trimmed)) return 'arXiv';

        // PMID search field syntax: NNNNNNN [pmid]
        if (PMID_SEARCH_FIELD.test(trimmed)) return 'PMID';

        // Bibcode: 19-char format starting with year
        if (len === 19 && BIBCODE_STANDARD.test(trimmed)) return 'bibcode';
        if (BIBCODE_SPECIAL.test(trimmed)) return 'bibcode';

        // CSTR bare format
        if (CSTR_BARE.test(trimmed)) return 'CSTR';

        // ISBN-13 (starts with 978 or 979)
        const noFormatting = trimmed.replace(/[-\s]/g, '');
        if (ISBN_13.test(noFormatting)) return 'ISBN';

        // ISBN-10 (10 digits, last may be X)
        if (ISBN_10.test(noFormatting)) return 'ISBN';

        // EAN-13 (13 digits, not ISBN)
        if (EAN13_BARE.test(noFormatting) && !EAN13_NOT_ISBN.test(noFormatting)) return 'EAN13';

        // EISSN standard format with hyphen
        if (EISSN_STANDARD.test(trimmed)) return 'EISSN';

        // EISSN compact format (8 digits)
        if (EISSN_COMPACT.test(trimmed)) return 'EISSN';

        // ISTC hyphenated
        if (ISTC_HYPHENATED.test(trimmed)) return 'ISTC';

        // ISTC compact
        if (ISTC_COMPACT.test(trimmed)) return 'ISTC';

        // Handle bare format (prefix/suffix)
        if (HANDLE_BARE.test(trimmed)) return 'Handle';
    }

    // arXiv old format: category/YYMMNNN
    if (ARXIV_OLD_FORMAT.test(trimmed)) return 'arXiv';

    // IGSN bare codes (AU, SSH, BGR, etc.)
    if (IGSN_BARE_CODE.test(trimmed)) return 'IGSN';

    // Default fallbacks
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
        const doiUrlMatch = normalized.match(DOI_URL);
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
