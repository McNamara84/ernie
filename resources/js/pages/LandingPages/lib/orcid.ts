const ORCID_PREFIX_PATTERN = /^(?:https?:\/\/)?(?:www\.)?orcid\.org\/+/i;
const ORCID_ID_PATTERN = /^\d{4}-\d{4}-\d{4}-\d{3}[\dX]$/i;

/**
 * Resolve supported bare and URL-form ORCID identifiers to a canonical URL.
 */
export function resolveOrcidUrl(identifier: string | null | undefined): string | null {
    const normalized = identifier?.trim().replace(ORCID_PREFIX_PATTERN, '').replace(/\/+$/, '') ?? '';

    if (!ORCID_ID_PATTERN.test(normalized)) {
        return null;
    }

    return `https://orcid.org/${normalized.toUpperCase()}`;
}
