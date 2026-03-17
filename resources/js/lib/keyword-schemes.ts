/**
 * Central definitions for keyword / subject scheme identifiers.
 *
 * Import these constants wherever scheme strings are needed so that
 * a rename or addition only requires a single change.
 */

/** GCMD Science Keywords scheme identifier */
export const SCHEME_GCMD_SCIENCE = 'Science Keywords';

/** GCMD Platforms scheme identifier */
export const SCHEME_GCMD_PLATFORMS = 'Platforms';

/** GCMD Instruments scheme identifier */
export const SCHEME_GCMD_INSTRUMENTS = 'Instruments';

/** EPOS Multi-Scale Laboratories vocabulary scheme identifier */
export const SCHEME_MSL = 'EPOS MSL vocabulary';

/** GEMET (GEneral Multilingual Environmental Thesaurus) scheme identifier */
export const SCHEME_GEMET = 'GEMET - GEneral Multilingual Environmental Thesaurus';

/** ICS International Chronostratigraphic Chart scheme identifier */
export const SCHEME_ICS_CHRONOSTRAT = 'International Chronostratigraphic Chart';

/** User-friendly display labels for each scheme */
export const SCHEME_LABELS: Record<string, string> = {
    '': 'Free Keywords',
    [SCHEME_GCMD_SCIENCE]: 'GCMD Science Keywords',
    [SCHEME_GCMD_PLATFORMS]: 'GCMD Platforms',
    [SCHEME_GCMD_INSTRUMENTS]: 'GCMD Instruments',
    [SCHEME_MSL]: 'MSL Vocabularies',
    [SCHEME_GEMET]: 'GEMET Thesaurus',
    [SCHEME_ICS_CHRONOSTRAT]: 'ICS Chronostratigraphy',
};

/**
 * Get a user-friendly label for a keyword scheme.
 */
export function getSchemeLabel(scheme: string | null): string {
    return SCHEME_LABELS[scheme ?? ''] ?? (scheme || 'Free Keywords');
}
