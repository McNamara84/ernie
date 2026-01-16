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
 * - URN (Uniform Resource Name)
 * - w3id (W3C Permanent Identifier)
 * - More identifier types to be added
 *
 * @param value - The identifier string to analyze
 * @returns The detected IdentifierType
 */
export function detectIdentifierType(value: string): IdentifierType {
    const trimmed = value.trim();

    // Early rejection: strings with only spaces between parts that look like DOIs are invalid
    // e.g., "10.5880 with spaces" should not match as DOI
    if (/^10\.\d+\s+\S/.test(trimmed)) {
        // This looks like a DOI prefix followed by space and more text - not a valid DOI
        return 'URL';
    }

    // IGSN (International Generic Sample Number) detection
    // Must be checked before DOI since IGSN has DOI-like formats (10.60516/..., 10.58052/...)

    // IGSN DOI URL patterns: https://doi.org/10.60516/..., https://doi.org/10.58052/..., etc.
    // Known IGSN DOI prefixes: 10.60516 (Australia), 10.58052 (SESAR USA), 10.60510 (MARUM/ICDP/GFZ),
    // 10.58108 (CSIRO), 10.58095 (MARUM)
    if (trimmed.match(/^https?:\/\/(?:doi\.org|dx\.doi\.org)\/10\.(?:60516|58052|60510|58108|58095)\/\S+/i)) {
        return 'IGSN';
    }

    // IGSN Legacy Handle URL: https://igsn.org/10.273/...
    if (trimmed.match(/^https?:\/\/igsn\.org\/10\.273\/\S+/i)) {
        return 'IGSN';
    }

    // IGSN with DOI prefix (bare): 10.60516/..., 10.58052/..., etc.
    if (trimmed.match(/^10\.(?:60516|58052|60510|58108|58095)\/\S+$/)) {
        return 'IGSN';
    }

    // IGSN Legacy Handle (bare): 10.273/...
    if (trimmed.match(/^10\.273\/\S+$/)) {
        return 'IGSN';
    }

    // IGSN with explicit prefix: IGSN CODE, IGSN:CODE, igsn:CODE
    if (trimmed.match(/^igsn:?\s*[A-Za-z0-9]+$/i)) {
        return 'IGSN';
    }

    // IGSN with URN format: urn:igsn:CODE
    if (trimmed.match(/^urn:igsn:[A-Za-z0-9]+$/i)) {
        return 'IGSN';
    }

    // Handle with known Handle-only prefixes (must be checked BEFORE DOI)
    // 10.1594 is WDCC (World Data Center for Climate) - registered as Handle, not DOI
    // These prefixes look like DOIs but are actually Handle prefixes
    if (trimmed.match(/^10\.1594\/\S+$/)) {
        return 'Handle';
    }

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

    // CSTR (China Science and Technology Resource) URL patterns
    // Matches: https://identifiers.org/cstr:..., https://bioregistry.io/cstr:...
    if (trimmed.match(/^https?:\/\/(?:identifiers\.org|bioregistry\.io)\/cstr:/i)) {
        return 'CSTR';
    }

    // CSTR with prefix: CSTR:RA_CODE.TYPE.NAMESPACE.LOCAL_ID or cstr:...
    // Format: CSTR:NNNNN.NN.namespace.local_id
    // RA_CODE is typically 5 digits (e.g., 31253, 50001)
    // TYPE is typically 2 digits (e.g., 11, 22)
    // NAMESPACE and LOCAL_ID can contain letters, numbers, underscores, hyphens, dots, tildes
    if (trimmed.match(/^cstr:\d{5}\.\d{2}\.\S+/i)) {
        return 'CSTR';
    }

    // CSTR bare format (without prefix): RA_CODE.TYPE.NAMESPACE.LOCAL_ID
    // Must start with 5 digits, then 2 digits after dot, then namespace/id
    // This is less common but valid
    if (trimmed.match(/^\d{5}\.\d{2}\.[A-Za-z_][A-Za-z0-9_.~-]*\.\S+$/)) {
        return 'CSTR';
    }

    // ISBN (International Standard Book Number) detection
    // Must be checked before EAN-13 since ISBN-13 starts with 978 or 979

    // ISBN with OpenEdition URL: isbn.openedition.org/978-... or books.openedition.org/isbn/978...
    if (trimmed.match(/^https?:\/\/isbn\.openedition\.org\/97[89]/i)) {
        return 'ISBN';
    }
    if (trimmed.match(/^https?:\/\/books\.openedition\.org\/isbn\/97[89]/i)) {
        return 'ISBN';
    }

    // ISBN with URN format: urn:isbn:...
    if (trimmed.match(/^urn:isbn:/i)) {
        return 'ISBN';
    }

    // ISBN with explicit prefix: ISBN-13:, ISBN-10:, ISBN:, ISBN followed by number
    // Matches: ISBN-13: 978-..., ISBN 978-..., ISBN: 0-306-..., ISBN (eBook): 978-...
    if (trimmed.match(/^isbn(?:-?(?:13|10))?[:\s]+/i)) {
        return 'ISBN';
    }

    // ISBN-13 compact or with hyphens (starts with 978 or 979)
    // Format: 978XXXXXXXXXX or 979XXXXXXXXXX (13 digits total)
    // Or with hyphens: 978-X-XX-XXXXXX-X
    const isbnCandidate = trimmed.replace(/[-\s]/g, '');
    if (/^97[89]\d{10}$/.test(isbnCandidate)) {
        return 'ISBN';
    }

    // ISBN-10 format (legacy): 10 digits, last may be X
    // Format: XXXXXXXXXX or X-XXX-XXXXX-X
    const isbn10Candidate = trimmed.replace(/[-\s]/g, '');
    if (/^\d{9}[\dXx]$/.test(isbn10Candidate)) {
        return 'ISBN';
    }

    // EAN-13 (European Article Number) URL patterns
    // Matches: https://identifiers.org/ean13:..., GS1 Digital Link URLs
    if (trimmed.match(/^https?:\/\/(?:identifiers\.org\/ean13:|gs1\.[^/]+\/01\/)/i)) {
        return 'EAN13';
    }

    // EAN-13 with URN prefix: urn:ean13:..., urn:gtin:..., urn:gtin-13:...
    if (trimmed.match(/^urn:(?:ean13|gtin(?:-13)?):[\d-]+$/i)) {
        return 'EAN13';
    }

    // EAN-13 compact format: exactly 13 digits (may have hyphens/spaces)
    // First remove hyphens and spaces to check if it's 13 digits
    // Note: ISBN-13 (978/979) is already handled above
    const eanCandidate = trimmed.replace(/[-\s]/g, '');
    if (/^\d{13}$/.test(eanCandidate) && !/^97[89]/.test(eanCandidate)) {
        // Validate it's not another identifier type by checking prefix patterns
        // EAN-13 country codes: 000-019 (USA/Canada), 020-029 (store internal), 030-039 (USA drugs),
        // 040-049 (used to define company prefix), 050-059 (coupons), 060-139 (USA/Canada),
        // 200-299 (store internal), 300-379 (France), 380-389 (Bulgaria), 400-440 (Germany),
        // 450-459/490-499 (Japan), 460-469 (Russia), 470 (Kyrgyzstan), 471 (Taiwan),
        // 474 (Estonia), 475 (Latvia), 476 (Azerbaijan), 477 (Lithuania), 478 (Uzbekistan),
        // 479 (Sri Lanka), 480 (Philippines), 481 (Belarus), 482 (Ukraine), 484 (Moldova),
        // 485 (Armenia), 486 (Georgia), 487 (Kazakhstan), 489 (Hong Kong),
        // 500-509 (UK), 520 (Greece), 528 (Lebanon), 529 (Cyprus), 530 (Albania),
        // 531 (Macedonia), 535 (Malta), 539 (Ireland), 540-549 (Belgium/Luxembourg),
        // 560 (Portugal), 569 (Iceland), 570-579 (Denmark), 590 (Poland),
        // 594 (Romania), 599 (Hungary), 600-601 (South Africa), 603 (Ghana), 604 (Senegal),
        // 608 (Bahrain), 609 (Mauritius), 611 (Morocco), 613 (Algeria), 615 (Nigeria),
        // 616 (Kenya), 618 (Ivory Coast), 619 (Tunisia), 620 (Tanzania), 621 (Syria),
        // 622 (Egypt), 623 (Brunei), 624 (Libya), 625 (Jordan), 626 (Iran),
        // 627 (Kuwait), 628 (Saudi Arabia), 629 (UAE), 640-649 (Finland),
        // 690-699 (China), 700-709 (Norway), 729 (Israel), 730-739 (Sweden),
        // 740 (Guatemala), 741 (El Salvador), 742 (Honduras), 743 (Nicaragua),
        // 744 (Costa Rica), 745 (Panama), 746 (Dominican Rep.), 750 (Mexico),
        // 754-755 (Canada), 759 (Venezuela), 760-769 (Switzerland), 770-771 (Colombia),
        // 773 (Uruguay), 775 (Peru), 777 (Bolivia), 778-779 (Argentina),
        // 780 (Chile), 784 (Paraguay), 786 (Ecuador), 789-790 (Brazil),
        // 800-839 (Italy), 840-849 (Spain), 850 (Cuba), 858 (Slovakia), 859 (Czech Rep.),
        // 860 (Serbia), 865 (Mongolia), 867 (North Korea), 868-869 (Turkey),
        // 870-879 (Netherlands), 880 (South Korea), 884 (Cambodia), 885 (Thailand),
        // 888 (Singapore), 890 (India), 893 (Vietnam), 896 (Pakistan),
        // 899 (Indonesia), 900-919 (Austria), 930-939 (Australia), 940-949 (New Zealand),
        // 950 (GS1 Global Office), 951 (EPCglobal), 955 (Malaysia), 958 (Macau),
        // 960-969 (GS1 UK), 977 (Serial publications ISSN), 978-979 (ISBN books)
        return 'EAN13';
    }

    // LSID (Life Science Identifier) detection - ISO 21047
    // Format: urn:lsid:authority:namespace:objectid[:version]
    // Examples: urn:lsid:ebi.ac.uk:SWISS-PROT.accession:P34355:3
    //           urn:lsid:zoobank.org:act:8BDC0735-FEA4-4298-83FA-D04F67C3FBEC

    // LSID URN format: urn:lsid:authority:namespace:objectid[:version]
    // Authority: domain name (e.g., ebi.ac.uk, zoobank.org, ncbi.nlm.nih.gov)
    // Namespace: identifier type (e.g., SWISS-PROT.accession, PDB, GenBank.accession, act, pub, namebank)
    // ObjectID: unique identifier (alphanumeric, UUID, or numeric)
    // Version: optional revision number
    if (trimmed.match(/^urn:lsid:[a-z0-9.-]+:[a-z0-9._-]+:[a-z0-9._-]+(?::\d+)?$/i)) {
        return 'LSID';
    }

    // LSID with lsid.io resolver: https://lsid.io/urn:lsid:...
    if (trimmed.match(/^https?:\/\/lsid\.io\/urn:lsid:[a-z0-9.-]+:[a-z0-9._-]+:[a-z0-9._-]+(?::\d+)?$/i)) {
        return 'LSID';
    }

    // LSID with ServiceLocator pattern: http://authority/ws/services/ServiceLocator?lsid=urn:lsid:...
    if (trimmed.match(/^https?:\/\/[a-z0-9.-]+\/ws\/services\/ServiceLocator\?lsid=urn:lsid:[a-z0-9.-]+:[a-z0-9._-]+:[a-z0-9._-]+(?::\d+)?$/i)) {
        return 'LSID';
    }

    // LSID with ZooBank resolver: http://zoobank.org/urn:lsid:zoobank.org:...
    if (trimmed.match(/^https?:\/\/zoobank\.org\/urn:lsid:zoobank\.org:[a-z0-9._-]+:[a-z0-9._-]+$/i)) {
        return 'LSID';
    }

    // PMID (PubMed ID) detection
    // PubMed identifiers are numeric IDs for biomedical literature
    // Range: typically 1-9 digits (currently up to ~40 million)

    // PMID with PubMed URL: https://pubmed.ncbi.nlm.nih.gov/NNNNNNN
    if (trimmed.match(/^https?:\/\/pubmed\.ncbi\.nlm\.nih\.gov\/\d{1,9}$/i)) {
        return 'PMID';
    }

    // PMID with legacy NCBI URL: http://www.ncbi.nlm.nih.gov/pubmed/NNNNNNN
    if (trimmed.match(/^https?:\/\/(?:www\.)?ncbi\.nlm\.nih\.gov\/pubmed\/\d{1,9}$/i)) {
        return 'PMID';
    }

    // PMID with prefix: PMID: NNNNNNN, PMID NNNNNNN, PubMed ID NNNNNNN
    if (trimmed.match(/^(?:pmid|pubmed\s*id):?\s*\d{1,9}$/i)) {
        return 'PMID';
    }

    // PMID with search field syntax: NNNNNNN [pmid] or NNNNNNN [uid]
    if (trimmed.match(/^\d{1,9}\s*\[(?:pmid|uid)\]$/i)) {
        return 'PMID';
    }

    // w3id (W3C Permanent Identifier) detection
    // w3id.org provides persistent URLs for web resources, particularly ontologies and vocabularies
    // Note: w3id is a distinct identifier type, NOT the same as PURL
    // Must be checked BEFORE PURL since w3id.org was previously misdetected as PURL

    // w3id with w3id.org domain: http(s)://w3id.org/namespace[/path][#fragment]
    // Supports: paths, trailing slashes, fragments, all alphanumeric/special chars in path
    if (trimmed.match(/^https?:\/\/w3id\.org\/[a-z0-9._\/-]+(?:#[a-z0-9._-]*)?$/i)) {
        return 'w3id';
    }

    // PURL (Persistent URL) detection
    // PURLs are persistent URLs that redirect to actual resource locations
    // Common domains: purl.org, purl.oclc.org, purl.lib.*, purl.example.org
    // Note: w3id.org is now handled separately as 'w3id' type

    // PURL with purl.org domain: http(s)://purl.org/path
    if (trimmed.match(/^https?:\/\/purl\.org\/[a-z0-9._\/-]+$/i)) {
        return 'PURL';
    }

    // PURL with purl.oclc.org domain (original OCLC PURL service)
    if (trimmed.match(/^https?:\/\/purl\.oclc\.org\/[a-z0-9._\/-]+$/i)) {
        return 'PURL';
    }

    // PURL with institutional purl.lib.* domain (library PURLs)
    if (trimmed.match(/^https?:\/\/purl\.lib\.[a-z0-9.-]+\/[a-z0-9._\/?=&-]+$/i)) {
        return 'PURL';
    }

    // PURL with generic purl.*.org or purl.*.edu pattern
    if (trimmed.match(/^https?:\/\/purl\.[a-z0-9.-]+\.(?:org|edu)\/[a-z0-9._\/-]+$/i)) {
        return 'PURL';
    }

    // RRID (Research Resource Identifier) detection
    // RRIDs identify research resources like antibodies, cell lines, organisms, tools
    // Format: RRID:Authority_LocalID (e.g., RRID:AB_90755, RRID:CVCL_0030)
    // Authorities: AB (Antibody), CVCL (Cellosaurus), SCR (SciCrunch), IMSR_JAX, MMRRC, Addgene, SAMN
    // Special cases: IMSR_JAX:000664 (colon after authority), SAMN19842595 (no underscore)

    // RRID with prefix: RRID:Authority_LocalID or RRID: Authority_LocalID
    // Supports: RRID:AB_90755, RRID:IMSR_JAX:000664, RRID:SAMN19842595
    if (trimmed.match(/^rrid:?\s*[a-z]+[_:]?[a-z0-9_:-]+$/i)) {
        return 'RRID';
    }

    // RRID with SciCrunch resolver URL: https://scicrunch.org/resolver/RRID:...
    if (trimmed.match(/^https?:\/\/scicrunch\.org\/resolver\/RRID:[a-z]+[_:]?[a-z0-9_:-]+$/i)) {
        return 'RRID';
    }

    // RRID with rrid.site portal URL: https://rrid.site/RRID:...
    if (trimmed.match(/^https?:\/\/rrid\.site\/RRID:[a-z]+[_:]?[a-z0-9_:-]+$/i)) {
        return 'RRID';
    }

    // UPC (Universal Product Code) detection
    // UPC-A is a 12-digit barcode used primarily in the US and Canada for retail products
    // Format: NNNNNNNNNNNN (12 digits) or with hyphens/spaces
    // Number System (0-9) + Company Prefix (5 digits) + Product Code (5 digits) + Check Digit

    // UPC with prefix: UPC: NNNNNNNNNNNN, UPC-A: NNNNNNNNNNNN, GTIN-12: NNNNNNNNNNNN
    // Supports: UPC, UPCA, UPC-A, GTIN12, GTIN-12
    const upcMatch = trimmed.match(/^(?:upc-?a?|gtin-?12):?\s*(\d[\d\s-]{10,14}\d)$/i);
    if (upcMatch) {
        // Verify it has exactly 12 digits when stripped of formatting
        const digitsOnly = upcMatch[1].replace(/[^\d]/g, '');
        if (digitsOnly.length === 12) {
            return 'UPC';
        }
    }

    // UPC-E (compressed 8-digit format) with prefix
    if (trimmed.match(/^upc-?e:?\s*\d{8}$/i)) {
        return 'UPC';
    }

    // LISSN (Linking ISSN / ISSN-L) detection
    // LISSN links different media versions of the same serial publication
    // Must be checked before EISSN since LISSN has more specific prefixes

    // LISSN with portal.issn.org URL: https://portal.issn.org/resource/ISSN-L/NNNN-NNNN
    if (trimmed.match(/^https?:\/\/portal\.issn\.org\/resource\/ISSN-L\/\d{4}-?\d{3}[\dXx]$/i)) {
        return 'LISSN';
    }

    // LISSN with explicit prefix: LISSN NNNN-NNNN, ISSN-L NNNN-NNNN
    if (trimmed.match(/^(?:lissn|issn-l):?\s*\d{4}-?\d{3}[\dXx]$/i)) {
        return 'LISSN';
    }

    // EISSN (Electronic ISSN) URL patterns
    // Matches: https://portal.issn.org/resource/ISSN/NNNN-NNNN
    // Matches: https://identifiers.org/issn:NNNN-NNNN
    // Matches: https://www.worldcat.org/issn/NNNN-NNNN
    if (trimmed.match(/^https?:\/\/(?:portal\.issn\.org\/resource\/ISSN\/|identifiers\.org\/issn:|www\.worldcat\.org\/issn\/)\d{4}-?\d{3}[\dXx]$/i)) {
        return 'EISSN';
    }

    // EISSN with URN prefix: urn:issn:NNNN-NNNN
    if (trimmed.match(/^urn:issn:\d{4}-?\d{3}[\dXx]$/i)) {
        return 'EISSN';
    }

    // EISSN with prefix patterns: EISSN NNNN-NNNN, e-ISSN NNNN-NNNN, eISSN NNNN-NNNN
    // Also supports: ISSN NNNN-NNNN, p-ISSN NNNN-NNNN, pISSN NNNN-NNNN
    // All ISSN variants (print and electronic) are detected as EISSN in DataCite schema
    if (trimmed.match(/^(?:e-?issn|p-?issn|issn):?\s*\d{4}-?\d{3}[\dXx]$/i)) {
        return 'EISSN';
    }

    // EISSN with explicit prefix and parenthetical type (ISSN 0378-5955 (Online))
    if (trimmed.match(/^issn\s+\d{4}-?\d{3}[\dXx]\s*\((?:online|print)\)$/i)) {
        return 'EISSN';
    }

    // EISSN/ISSN standard format with hyphen: NNNN-NNNC (C = check digit 0-9 or X)
    // ISSN is always 8 digits with a check digit that can be 0-9 or X
    // Must have hyphen in position 5 for standard format
    if (trimmed.match(/^\d{4}-\d{3}[\dXx]$/)) {
        return 'EISSN';
    }

    // EISSN compact format: NNNNNNCC (8 digits, last may be X)
    // Only detect if exactly 8 characters and last is digit or X
    if (trimmed.match(/^\d{7}[\dXx]$/i)) {
        return 'EISSN';
    }

    // ISTC (International Standard Text Code) detection
    // Format: 16 hexadecimal characters (0-9, A-J) in groups: XXX-YYYY-ZZZZ-ZZZZ-C
    // Components: Registration Agency (3) - Year (4) - Work Element (8) - Check (1)
    // Note: ISTC uses extended hex (0-9, A-J) not standard hex (0-9, A-F)

    // ISTC with URN format: urn:istc:...
    if (trimmed.match(/^urn:istc:[0-9A-Ja-j-]+$/i)) {
        return 'ISTC';
    }

    // ISTC with explicit prefix: ISTC XXX-YYYY-..., ISTC: XXX-..., ISTC (Bowker): ...
    if (trimmed.match(/^istc\s*(?:\([^)]+\))?:?\s*[0-9A-Ja-j]{3}-?[0-9]{4}-?[0-9A-Ja-j]{4}-?[0-9A-Ja-j]{4}-?[0-9A-Ja-j]$/i)) {
        return 'ISTC';
    }

    // ISTC with hyphens: XXX-YYYY-ZZZZ-ZZZZ-C (16 chars total, displayed as 3-4-4-4-1)
    // RA (3 hex) - Year (4 digits) - Work (8 hex split 4-4) - Check (1 hex)
    if (trimmed.match(/^[0-9A-Ja-j]{3}-[0-9]{4}-[0-9A-Ja-j]{4}-[0-9A-Ja-j]{4}-[0-9A-Ja-j]$/i)) {
        return 'ISTC';
    }

    // ISTC compact format: 16 characters without hyphens
    // Pattern: 3 hex + 4 digits + 8 hex + 1 hex = 16 chars
    if (trimmed.match(/^[0-9A-Ja-j]{3}[0-9]{4}[0-9A-Ja-j]{9}$/i)) {
        return 'ISTC';
    }

    // ARK with resolver URL patterns (must be checked before generic URL)
    // Matches various ARK resolvers: n2t.net, ark.bnf.fr, familysearch.org, archive.org, data.bnf.fr, etc.
    // ARK format in URL: https://resolver/ark:/NAAN/Name, https://resolver/path/ark:/NAAN/Name
    // Also handles: https://archive.org/details/ark:/13960/t5z64fc55
    if (trimmed.match(/^https?:\/\/[^/]+(?:\/[^/]+)*\/ark:\/?\d{5,}\/\S+/i)) {
        return 'ARK';
    }

    // ARK compact format: ark:NAAN/Name or ark:/NAAN/Name
    // NAAN (Name Assigning Authority Number) is typically 5 digits
    if (trimmed.match(/^ark:\/?\d{5,}\/\S+/i)) {
        return 'ARK';
    }

    // Handle URL patterns (must be checked before generic URL)
    // Matches: http://hdl.handle.net/prefix/suffix or https://hdl.handle.net/prefix/suffix
    // Also matches API URLs: https://hdl.handle.net/api/handles/prefix/suffix
    // And with query params: ?noredirect, ?auth
    if (trimmed.match(/^https?:\/\/hdl\.handle\.net\/(?:api\/handles\/)?\S+/i)) {
        return 'Handle';
    }

    // Handle with hdl:// protocol: hdl://prefix/suffix
    if (trimmed.match(/^hdl:\/\/\S+/i)) {
        return 'Handle';
    }

    // Handle with URN format: urn:handle:prefix/suffix
    if (trimmed.match(/^urn:handle:\S+/i)) {
        return 'Handle';
    }

    // Handle with custom resolvers (e.g., GWDG)
    // Matches: https://vm11.pid.gwdg.de:8445/objects/prefix/suffix
    if (trimmed.match(/^https?:\/\/[^/]+\/objects\/\d+(?:\.\w+)?\/\S+/i)) {
        return 'Handle';
    }

    // IGSN bare code format (must be before URL and Handle)
    // IGSN codes are alphanumeric, typically 4-15 characters, starting with allocating agent prefix
    // Common prefixes: AU (Australia), SSH (SESAR), BGR (Germany), ICDP, CSR (CSIRO), GFZ, MBCR (MARUM), ARDC
    // Pattern: 2-4 letter prefix + alphanumeric suffix
    // Note: This is a heuristic - bare codes without prefix are harder to detect reliably
    // Examples: AU1101, SSH000SUA, BGRB5054RX05201, ICDP5054ESYI201, CSRWA275, GFZ000001ABC
    if (trimmed.match(/^(?:AU|SSH|BGR[A-Z]?|ICDP|CSR[A-Z]?|GFZ|MBCR|ARDC)[A-Z0-9]{2,12}$/i)) {
        return 'IGSN';
    }

    // URN (Uniform Resource Name) detection - RFC 8141
    // Format: urn:NID:NSS where NID is Namespace Identifier and NSS is Namespace Specific String
    // Note: Specific URN namespaces (isbn, lsid, igsn, issn, istc, handle) are detected as their specific types above
    // This section handles generic URN namespaces: uuid, oid, nbn, iptc, example, ietf, oasis, etc.

    // URN resolver URLs (must be checked before generic URL)
    // German NBN resolver: https://nbn-resolving.de/urn:nbn:... or https://nbn-resolving.org/...
    if (trimmed.match(/^https?:\/\/nbn-resolving\.(?:de|org)\/urn:[a-z0-9][a-z0-9-]{0,31}:\S+$/i)) {
        return 'URN';
    }

    // Finnish NBN resolver: https://urn.fi/urn:nbn:fi:...
    if (trimmed.match(/^https?:\/\/urn\.fi\/urn:[a-z0-9][a-z0-9-]{0,31}:\S+$/i)) {
        return 'URN';
    }

    // Swedish NBN resolver: https://urn.kb.se/resolve?urn=urn:nbn:se:...
    if (trimmed.match(/^https?:\/\/urn\.kb\.se\/resolve\?urn=urn:[a-z0-9][a-z0-9-]{0,31}:\S+$/i)) {
        return 'URN';
    }

    // Dutch NBN resolver: https://persistent-identifier.nl/urn:nbn:nl:...
    if (trimmed.match(/^https?:\/\/persistent-identifier\.nl\/urn:[a-z0-9][a-z0-9-]{0,31}:\S+$/i)) {
        return 'URN';
    }

    // Name-to-Thing (N2T) URN resolver: https://n2t.net/urn:...
    if (trimmed.match(/^https?:\/\/n2t\.net\/urn:[a-z0-9][a-z0-9-]{0,31}:\S+$/i)) {
        return 'URN';
    }

    // Generic URN format: urn:NID:NSS
    // NID: 2-32 chars, alphanumeric and hyphens, must start with letter or digit
    // NSS: Namespace Specific String, can contain various characters including :, /, #, ?, +, %, ;, etc.
    // Common NIDs: uuid, oid, nbn, iptc, example, ietf, oasis, mpeg, iso, ogc, epc, lex, publicid, xmlorg, stalwart
    // Excluded: isbn (→ ISBN), lsid (→ LSID), igsn (→ IGSN), issn (→ EISSN), istc (→ ISTC), handle (→ Handle)
    if (trimmed.match(/^urn:(?!isbn:|lsid:|igsn:|issn:|istc:|handle:)[a-z0-9][a-z0-9-]{0,31}:\S+$/i)) {
        return 'URN';
    }

    // URL patterns
    if (trimmed.match(/^https?:\/\//i)) {
        return 'URL';
    }

    // Handle patterns (bare format: prefix/suffix)
    // Prefix can be:
    // - Simple numeric: 2142/103380
    // - With dots: 21.T11998/..., 21.11145/..., 10.1594/...
    // Suffix can contain: alphanumerics, hyphens, underscores, dots, colons
    if (trimmed.match(/^\d+(?:\.\w+)?\/\S+$/)) {
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
