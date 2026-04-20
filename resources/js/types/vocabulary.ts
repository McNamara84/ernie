/**
 * Controlled Vocabulary Types (GCMD, MSL, ICS Chronostratigraphy, etc.)
 */

export interface VocabularyKeyword {
    id: string;
    text: string;
    notation?: string;
    language: string;
    scheme: string;
    schemeURI: string;
    description: string;
    children: VocabularyKeyword[];
}

export interface VocabularyData {
    lastUpdated: string;
    data: VocabularyKeyword[];
}

export type VocabularyType = 'science' | 'platforms' | 'instruments' | 'msl' | 'chronostratigraphy' | 'gemet' | 'analytical_methods' | 'euroscivoc';

export interface SelectedKeyword {
    id: string;
    text: string;
    path: string; // Breadcrumb path, e.g., "EARTH SCIENCE > DATA MANAGEMENT > DATA MINING"
    language: string;
    scheme: string;
    schemeURI: string;
    classificationCode?: string;
    isLegacy?: boolean; // True if keyword doesn't exist in current vocabulary (from old database)
}

// Helper function to map scheme to vocabulary type for UI tabs
export function getVocabularyTypeFromScheme(scheme: string): VocabularyType {
    // Normalize scheme for comparison
    const normalized = scheme.toLowerCase();

    // EuroSciVoc must be checked before 'science' because the scheme name contains 'Science'
    if (normalized.includes('euroscivoc') || normalized.includes('european science vocabulary')) return 'euroscivoc';
    if (normalized.includes('science')) return 'science';
    if (normalized.includes('platform')) return 'platforms';
    if (normalized.includes('instrument')) return 'instruments';
    if (normalized.includes('msl') || normalized.includes('epos')) return 'msl';
    if (normalized.includes('chronostratigraphic') || normalized.includes('chronostrat')) return 'chronostratigraphy';
    if (normalized.includes('gemet')) return 'gemet';
    if (normalized.includes('analytical') && normalized.includes('method')) return 'analytical_methods';
    if (normalized.includes('geochem') && normalized.includes('method')) return 'analytical_methods';

    return 'science'; // Default fallback
}

// Helper function to map vocabulary type to scheme name
export function getSchemeFromVocabularyType(type: VocabularyType): string {
    switch (type) {
        case 'science':
            return 'Science Keywords';
        case 'platforms':
            return 'Platforms';
        case 'instruments':
            return 'Instruments';
        case 'msl':
            return 'EPOS MSL vocabulary';
        case 'chronostratigraphy':
            return 'International Chronostratigraphic Chart';
        case 'gemet':
            return 'GEMET - GEneral Multilingual Environmental Thesaurus';
        case 'analytical_methods':
            return 'Analytical Methods for Geochemistry and Cosmochemistry';
        case 'euroscivoc':
            return 'European Science Vocabulary (EuroSciVoc)';
        default:
            return 'Science Keywords';
    }
}

export interface VocabularyCollection {
    science: VocabularyData;
    platforms: VocabularyData;
    instruments: VocabularyData;
    msl: VocabularyData;
    chronostratigraphy: VocabularyData;
    gemet: VocabularyData;
    analytical_methods: VocabularyData;
    euroscivoc: VocabularyData;
}
