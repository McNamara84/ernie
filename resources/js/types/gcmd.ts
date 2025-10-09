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

export type GCMDVocabularyType = 'science' | 'platforms' | 'instruments';

export interface SelectedKeyword {
    id: string;
    text: string;
    path: string; // Breadcrumb path, e.g., "EARTH SCIENCE > DATA MANAGEMENT > DATA MINING"
    vocabularyType: GCMDVocabularyType;
}

export interface GCMDVocabularies {
    science: GCMDVocabulary;
    platforms: GCMDVocabulary;
    instruments: GCMDVocabulary;
}
