import { validateORCID } from '@/utils/validation-rules';

const ORCID_PREFIX_PATTERN = /^(?:https?:\/\/)?(?:www\.)?orcid\.org\/+/i;

/**
 * Resolve supported bare and URL-form ORCID identifiers to a canonical URL.
 */
export function resolveOrcidUrl(identifier: string | null | undefined): string | null {
    const normalized = (identifier?.trim().replace(ORCID_PREFIX_PATTERN, '').replace(/\/+$/, '') ?? '').toUpperCase();
    const validation = validateORCID(normalized);

    if (!validation.isValid || !validation.normalizedORCID) {
        return null;
    }

    return `https://orcid.org/${validation.normalizedORCID}`;
}
