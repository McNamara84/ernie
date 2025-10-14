/**
 * GCMD (Global Change Master Directory) Controlled Vocabulary Types
 */

export interface GCMDKeyword {
    id: string;
    text: string;
    language: string;
    scheme: string;
    schemeURI: string;
    description: string;
    children: GCMDKeyword[];
}

export interface GCMDVocabulary {
    lastUpdated: string;
    data: GCMDKeyword[];
}

export type GCMDVocabularyType = 'science' | 'platforms' | 'instruments' | 'msl';

export interface SelectedKeyword {
    id: string;
    text: string;
    path: string; // Breadcrumb path, e.g., "EARTH SCIENCE > DATA MANAGEMENT > DATA MINING"
    language: string;
    scheme: string;
    schemeURI: string;
    isLegacy?: boolean; // True if keyword doesn't exist in current vocabulary (from old database)
}

// Helper function to map scheme to vocabulary type for UI tabs
export function getVocabularyTypeFromScheme(scheme: string): GCMDVocabularyType {
    // Normalize scheme for comparison
    const normalized = scheme.toLowerCase();
    
    if (normalized.includes('science')) return 'science';
    if (normalized.includes('platform')) return 'platforms';
    if (normalized.includes('instrument')) return 'instruments';
    if (normalized.includes('msl') || normalized.includes('epos')) return 'msl';
    
    return 'science'; // Default fallback
}

// Helper function to map vocabulary type to scheme name
export function getSchemeFromVocabularyType(type: GCMDVocabularyType): string {
    switch (type) {
        case 'science':
            return 'Science Keywords';
        case 'platforms':
            return 'Platforms';
        case 'instruments':
            return 'Instruments';
        case 'msl':
            return 'EPOS MSL vocabulary';
        default:
            return 'Science Keywords';
    }
}

export interface GCMDVocabularies {
    science: GCMDVocabulary;
    platforms: GCMDVocabulary;
    instruments: GCMDVocabulary;
}
