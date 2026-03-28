/**
 * Controlled Vocabulary Types (GCMD, MSL, ICS Chronostratigraphy, etc.)
 */

export interface VocabularyKeyword {
    id: string;
    text: string;
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

export type VocabularyType = 'science' | 'platforms' | 'instruments' | 'msl' | 'chronostratigraphy' | 'gemet';

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

    if (normalized.includes('science')) return 'science';
    if (normalized.includes('platform')) return 'platforms';
    if (normalized.includes('instrument')) return 'instruments';
    if (normalized.includes('msl') || normalized.includes('epos')) return 'msl';
    if (normalized.includes('chronostratigraphic') || normalized.includes('chronostrat')) return 'chronostratigraphy';
    if (normalized.includes('gemet')) return 'gemet';

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
}
