/**
 * Maps Laravel backend validation error keys to form sections and DOM field selectors.
 *
 * This enables the frontend to:
 * 1. Group errors by accordion section
 * 2. Open the correct accordion section
 * 3. Scroll to and focus the affected field
 * 4. Show inline error indicators on fields
 *
 * @see Issue #605 – Helpful error messages
 */

export interface MappedError {
    /** The original backend validation key, e.g. "authors.0.lastName" */
    backendKey: string;
    /** Human-readable error message from backend */
    message: string;
    /** Accordion section value, e.g. "authors" */
    sectionId: string;
    /** Display name of the section, e.g. "Authors" */
    sectionName: string;
    /** DOM CSS selector to scroll to / focus on click, or null if not directly focusable */
    fieldSelector: string | null;
    /** Form validation field ID matching useFormValidation state keys (e.g. 'year'), or null */
    fieldId: string | null;
}

interface SectionMapping {
    sectionId: string;
    sectionName: string;
}

/**
 * Maps backend validation key prefixes to accordion section IDs and display names.
 */
const SECTION_MAP: Record<string, SectionMapping> = {
    doi: { sectionId: 'resource-info', sectionName: 'Resource Information' },
    year: { sectionId: 'resource-info', sectionName: 'Resource Information' },
    resourceType: { sectionId: 'resource-info', sectionName: 'Resource Information' },
    version: { sectionId: 'resource-info', sectionName: 'Resource Information' },
    language: { sectionId: 'resource-info', sectionName: 'Resource Information' },
    titles: { sectionId: 'resource-info', sectionName: 'Resource Information' },
    datacenters: { sectionId: 'resource-info', sectionName: 'Resource Information' },
    licenses: { sectionId: 'licenses-rights', sectionName: 'Licenses & Rights' },
    authors: { sectionId: 'authors', sectionName: 'Authors' },
    contributors: { sectionId: 'contributors', sectionName: 'Contributors' },
    descriptions: { sectionId: 'descriptions', sectionName: 'Descriptions' },
    gcmdKeywords: { sectionId: 'controlled-vocabularies', sectionName: 'Controlled Vocabularies' },
    freeKeywords: { sectionId: 'free-keywords', sectionName: 'Free Keywords' },
    mslLaboratories: { sectionId: 'msl-laboratories', sectionName: 'MSL Laboratories' },
    spatialTemporalCoverages: { sectionId: 'spatial-temporal-coverage', sectionName: 'Spatial & Temporal Coverage' },
    dates: { sectionId: 'dates', sectionName: 'Dates' },
    relatedIdentifiers: { sectionId: 'related-work', sectionName: 'Related Work' },
    instruments: { sectionId: 'used-instruments', sectionName: 'Used Instruments' },
    fundingReferences: { sectionId: 'funding-references', sectionName: 'Funding References' },
};

/**
 * Maps simple top-level backend keys to their corresponding DOM field selectors.
 */
const SIMPLE_FIELD_MAP: Record<string, string> = {
    doi: '#doi',
    year: '#year',
    resourceType: '#resourceType',
    version: '#version',
    language: '#language',
};

/**
 * Maps simple top-level backend keys to useFormValidation field IDs.
 */
const FIELD_ID_MAP: Record<string, string> = {
    doi: 'doi',
    year: 'year',
    resourceType: 'resourceType',
    version: 'version',
    language: 'language',
};

/**
 * Extracts the top-level key prefix from a dot-notation backend key.
 *
 * @example
 * extractKeyPrefix('authors.0.lastName') → 'authors'
 * extractKeyPrefix('year') → 'year'
 * extractKeyPrefix('titles.0.title') → 'titles'
 */
function extractKeyPrefix(backendKey: string): string {
    const dotIndex = backendKey.indexOf('.');
    return dotIndex === -1 ? backendKey : backendKey.substring(0, dotIndex);
}

/**
 * Extracts the section name from a prefixed backend error message.
 *
 * Backend messages follow the format: "[Section Name] Error detail"
 * This function parses that prefix for grouping purposes.
 *
 * @returns The section name from the message prefix, or null if no prefix found.
 */
export function extractSectionFromMessage(message: string): string | null {
    const match = message.match(/^\[([^\]]+)\]\s*/);
    return match ? match[1] : null;
}

/**
 * Strips the section prefix from a backend error message.
 *
 * "[Resource Information] Publication Year is required." → "Publication Year is required."
 */
export function stripSectionPrefix(message: string): string {
    return message.replace(/^\[([^\]]+)\]\s*/, '');
}

/**
 * Resolves a DOM field selector for a given backend validation key.
 *
 * For simple keys (e.g., "year"), returns the mapped selector.
 * For array keys (e.g., "authors.0.lastName"), attempts to build a selector
 * based on the actual data-testid / id attributes rendered by the field components.
 */
function resolveFieldSelector(backendKey: string): string | null {
    // Direct simple field mapping
    if (backendKey in SIMPLE_FIELD_MAP) {
        return SIMPLE_FIELD_MAP[backendKey];
    }

    // For "titles" without index, point to main title
    if (backendKey === 'titles') {
        return '[data-testid="main-title-input"]';
    }

    // For "descriptions" without index, point to abstract
    if (backendKey === 'descriptions') {
        return '[data-testid="abstract-textarea"]';
    }

    // For "licenses" without index, point to primary license
    if (backendKey === 'licenses') {
        return '[data-testid="license-select-0"]';
    }

    // For "authors" without index, return null to trigger accordion section fallback
    if (backendKey === 'authors') {
        return null;
    }

    // Parse array-indexed keys: "field.INDEX.subfield"
    const arrayMatch = backendKey.match(/^(\w+)\.(\d+)(?:\.(\w+))?$/);
    if (arrayMatch) {
        const [, prefix, index, subfield] = arrayMatch;

        switch (prefix) {
            case 'titles':
                // Main title (index 0) has a stable data-testid; secondary titles use the section
                return subfield === 'title' && index === '0'
                    ? '[data-testid="main-title-input"]'
                    : null;
            case 'authors':
                return `[data-testid="author-${index}-fields-grid"]`;
            case 'contributors':
                return `[data-testid="contributor-${index}-type-field"]`;
            case 'descriptions':
                return `[data-testid="description-${index}-textarea"]`;
            case 'licenses':
                return `[data-testid="license-select-${index}"]`;
            case 'fundingReferences':
                return `[data-testid="funding-reference-${index}"]`;
            case 'relatedIdentifiers':
                return `[data-testid="related-identifier-${index}"]`;
            case 'spatialTemporalCoverages':
                return `[data-testid="coverage-entry-${index}"]`;
            case 'dates':
                return `[data-testid="date-entry-${index}"]`;
            case 'instruments':
                return `[data-testid="instrument-entry-${index}"]`;
            case 'datacenters':
                return '#datacenter';
            default:
                return null;
        }
    }

    return null;
}

/**
 * Resolves a form validation field ID for a given backend validation key.
 *
 * Returns the key that matches `useFormValidation` state (e.g. 'year', 'doi'),
 * or null for complex array fields where inline validation is not yet supported.
 */
function resolveFieldId(backendKey: string): string | null {
    if (backendKey in FIELD_ID_MAP) {
        return FIELD_ID_MAP[backendKey];
    }
    return null;
}

/**
 * Maps backend validation errors to structured MappedError objects.
 *
 * @param errors - The errors object from a Laravel 422 response: `{ "key": ["message", ...], ... }`
 * @returns Array of MappedError objects, sorted by section for grouped display.
 */
export function mapBackendErrors(errors: Record<string, string[]>): MappedError[] {
    const mappedErrors: MappedError[] = [];

    for (const [backendKey, messages] of Object.entries(errors)) {
        const prefix = extractKeyPrefix(backendKey);
        const section = SECTION_MAP[prefix];

        if (!section) {
            // Unknown key prefix — use a generic fallback section
            for (const message of messages) {
                mappedErrors.push({
                    backendKey,
                    message: stripSectionPrefix(message),
                    sectionId: 'unknown',
                    sectionName: 'Other',
                    fieldSelector: null,
                    fieldId: null,
                });
            }
            continue;
        }

        const fieldSelector = resolveFieldSelector(backendKey);
        const fieldId = resolveFieldId(backendKey);

        for (const message of messages) {
            // Use section name from message prefix if available (more accurate),
            // otherwise fall back to key-based mapping
            const messageSectionName = extractSectionFromMessage(message);

            mappedErrors.push({
                backendKey,
                message: stripSectionPrefix(message),
                sectionId: section.sectionId,
                sectionName: messageSectionName ?? section.sectionName,
                fieldSelector,
                fieldId,
            });
        }
    }

    // Sort by section order (use SECTION_MAP insertion order)
    const sectionOrder = Object.values(SECTION_MAP).map((s) => s.sectionId);
    mappedErrors.sort((a, b) => {
        const aIndex = sectionOrder.indexOf(a.sectionId);
        const bIndex = sectionOrder.indexOf(b.sectionId);
        return (aIndex === -1 ? 999 : aIndex) - (bIndex === -1 ? 999 : bIndex);
    });

    return mappedErrors;
}

/**
 * Groups mapped errors by section for display in the clickable validation alert.
 */
export function groupErrorsBySection(errors: MappedError[]): Map<string, { sectionName: string; sectionId: string; errors: MappedError[] }> {
    const groups = new Map<string, { sectionName: string; sectionId: string; errors: MappedError[] }>();

    for (const error of errors) {
        const existing = groups.get(error.sectionId);
        if (existing) {
            existing.errors.push(error);
        } else {
            groups.set(error.sectionId, {
                sectionName: error.sectionName,
                sectionId: error.sectionId,
                errors: [error],
            });
        }
    }

    return groups;
}
