/**
 * Unit tests for GCMD Controlled Vocabularies types and validation
 * These tests ensure type safety and data structure integrity without database dependencies
 * 
 * Run with: npm run test -- controlled-vocabularies
 */

import { describe, expect, it } from 'vitest';

import type { GCMDVocabularyType, SelectedKeyword } from '@/types/gcmd';

describe('SelectedKeyword Type', () => {
    it('should have all required fields', () => {
        const keyword: SelectedKeyword = {
            id: 'https://gcmd.earthdata.nasa.gov/kms/concept/12345678-1234-1234-1234-123456789012',
            text: 'CALCIUM',
            path: 'EARTH SCIENCE > AGRICULTURE > SOILS > CALCIUM',
            language: 'en',
            scheme: 'Earth Science',
            schemeURI: 'https://gcmd.earthdata.nasa.gov/kms/concepts/concept_scheme/sciencekeywords',
            vocabularyType: 'science',
        };

        expect(keyword).toHaveProperty('id');
        expect(keyword).toHaveProperty('text');
        expect(keyword).toHaveProperty('path');
        expect(keyword).toHaveProperty('language');
        expect(keyword).toHaveProperty('scheme');
        expect(keyword).toHaveProperty('schemeURI');
        expect(keyword).toHaveProperty('vocabularyType');
    });

    it('should validate science vocabulary type', () => {
        const keyword: SelectedKeyword = {
            id: 'https://gcmd.earthdata.nasa.gov/kms/concept/test-uuid',
            text: 'TEST',
            path: 'EARTH SCIENCE > TEST',
            language: 'en',
            scheme: 'Earth Science',
            schemeURI: 'https://gcmd.earthdata.nasa.gov/kms/concepts/concept_scheme/sciencekeywords',
            vocabularyType: 'science',
        };

        expect(keyword.vocabularyType).toBe('science');
    });

    it('should validate platforms vocabulary type', () => {
        const keyword: SelectedKeyword = {
            id: 'https://gcmd.earthdata.nasa.gov/kms/concept/test-uuid',
            text: 'TEST PLATFORM',
            path: 'EARTH OBSERVATION SATELLITES > TEST PLATFORM',
            language: 'en',
            scheme: 'Platforms',
            schemeURI: 'https://gcmd.earthdata.nasa.gov/kms/concepts/concept_scheme/platforms',
            vocabularyType: 'platforms',
        };

        expect(keyword.vocabularyType).toBe('platforms');
    });

    it('should validate instruments vocabulary type', () => {
        const keyword: SelectedKeyword = {
            id: 'https://gcmd.earthdata.nasa.gov/kms/concept/test-uuid',
            text: 'TEST INSTRUMENT',
            path: 'EARTH REMOTE SENSING INSTRUMENTS > TEST INSTRUMENT',
            language: 'en',
            scheme: 'Instruments',
            schemeURI: 'https://gcmd.earthdata.nasa.gov/kms/concepts/concept_scheme/instruments',
            vocabularyType: 'instruments',
        };

        expect(keyword.vocabularyType).toBe('instruments');
    });

    it('should store full hierarchical path', () => {
        const keyword: SelectedKeyword = {
            id: 'https://gcmd.earthdata.nasa.gov/kms/concept/test',
            text: 'CALCIUM',
            path: 'EARTH SCIENCE > AGRICULTURE > SOILS > SOIL CHEMISTRY > CALCIUM',
            language: 'en',
            scheme: 'Earth Science',
            schemeURI: 'https://gcmd.earthdata.nasa.gov/kms/concepts/concept_scheme/sciencekeywords',
            vocabularyType: 'science',
        };

        expect(keyword.path).toContain(' > ');
        expect(keyword.path.split(' > ')).toHaveLength(5);
        expect(keyword.path.endsWith(keyword.text)).toBe(true);
    });

    it('should handle different languages', () => {
        const languages = ['en', 'de', 'fr', 'es'];

        languages.forEach((lang) => {
            const keyword: SelectedKeyword = {
                id: 'https://gcmd.earthdata.nasa.gov/kms/concept/test',
                text: 'TEST',
                path: 'TEST',
                language: lang,
                scheme: 'Test',
                schemeURI: 'https://test.com',
                vocabularyType: 'science',
            };

            expect(keyword.language).toBe(lang);
        });
    });

    it('should store full GCMD URI (not just UUID)', () => {
        const fullUri = 'https://gcmd.earthdata.nasa.gov/kms/concept/12345678-1234-5678-9012-123456789012';
        
        const keyword: SelectedKeyword = {
            id: fullUri,
            text: 'TEST',
            path: 'TEST',
            language: 'en',
            scheme: 'Test',
            schemeURI: 'https://test.com',
            vocabularyType: 'science',
        };

        expect(keyword.id).toBe(fullUri);
        expect(keyword.id.length).toBeGreaterThan(36); // Longer than UUID alone
        expect(keyword.id).toContain('https://');
    });
});

describe('GCMDVocabularyType', () => {
    it('should only allow valid vocabulary types', () => {
        const validTypes: GCMDVocabularyType[] = ['science', 'platforms', 'instruments'];

        validTypes.forEach((type) => {
            expect(['science', 'platforms', 'instruments']).toContain(type);
        });

        expect(validTypes).toHaveLength(3);
    });
});

describe('Controlled Vocabularies Data Flow', () => {
    it('should transform old database format to new format', () => {
        // Simulating transformation from old database
        const oldKeyword = {
            id: 'https://gcmd.earthdata.nasa.gov/kms/concept/old-uuid',
            text: 'SOIL MOISTURE/WATER CONTENT',
            vocabulary: 'gcmd-science-keywords',
            path: 'EARTH SCIENCE > AGRICULTURE > SOILS > SOIL MOISTURE/WATER CONTENT',
        };

        // Transform to SelectedKeyword
        const newKeyword: SelectedKeyword = {
            id: oldKeyword.id,
            text: oldKeyword.text,
            path: oldKeyword.path,
            language: 'en', // Default for old DB
            scheme: oldKeyword.vocabulary, // Use vocabulary name as scheme
            schemeURI: oldKeyword.id.replace(/\/concept\/[^/]+$/, '/concepts/concept_scheme'),
            vocabularyType: 'science', // Mapped from 'gcmd-science-keywords'
        };

        expect(newKeyword.id).toBe(oldKeyword.id);
        expect(newKeyword.text).toBe(oldKeyword.text);
        expect(newKeyword.path).toBe(oldKeyword.path);
        expect(newKeyword.vocabularyType).toBe('science');
        expect(newKeyword.language).toBe('en');
    });

    it('should prepare data for backend submission', () => {
        const keyword: SelectedKeyword = {
            id: 'https://gcmd.earthdata.nasa.gov/kms/concept/test-uuid',
            text: 'CALCIUM',
            path: 'EARTH SCIENCE > AGRICULTURE > SOILS > CALCIUM',
            language: 'en',
            scheme: 'Earth Science',
            schemeURI: 'https://gcmd.earthdata.nasa.gov/kms/concepts/concept_scheme/sciencekeywords',
            vocabularyType: 'science',
        };

        // Prepare for API submission
        const payload = {
            id: keyword.id,
            text: keyword.text,
            path: keyword.path,
            language: keyword.language,
            scheme: keyword.scheme,
            schemeURI: keyword.schemeURI,
            vocabularyType: keyword.vocabularyType,
        };

        expect(payload).toEqual(keyword);
        expect(Object.keys(payload)).toHaveLength(7);
    });

    it('should handle multiple keywords of different types', () => {
        const keywords: SelectedKeyword[] = [
            {
                id: 'https://gcmd.earthdata.nasa.gov/kms/concept/science-1',
                text: 'CALCIUM',
                path: 'EARTH SCIENCE > AGRICULTURE > SOILS > CALCIUM',
                language: 'en',
                scheme: 'Earth Science',
                schemeURI: 'https://gcmd.earthdata.nasa.gov/kms/concepts/concept_scheme/sciencekeywords',
                vocabularyType: 'science',
            },
            {
                id: 'https://gcmd.earthdata.nasa.gov/kms/concept/platform-1',
                text: 'TERRA',
                path: 'EARTH OBSERVATION SATELLITES > TERRA',
                language: 'en',
                scheme: 'Platforms',
                schemeURI: 'https://gcmd.earthdata.nasa.gov/kms/concepts/concept_scheme/platforms',
                vocabularyType: 'platforms',
            },
            {
                id: 'https://gcmd.earthdata.nasa.gov/kms/concept/instrument-1',
                text: 'MODIS',
                path: 'EARTH REMOTE SENSING INSTRUMENTS > PASSIVE REMOTE SENSING > MODIS',
                language: 'en',
                scheme: 'Instruments',
                schemeURI: 'https://gcmd.earthdata.nasa.gov/kms/concepts/concept_scheme/instruments',
                vocabularyType: 'instruments',
            },
        ];

        expect(keywords).toHaveLength(3);
        expect(keywords.filter((k) => k.vocabularyType === 'science')).toHaveLength(1);
        expect(keywords.filter((k) => k.vocabularyType === 'platforms')).toHaveLength(1);
        expect(keywords.filter((k) => k.vocabularyType === 'instruments')).toHaveLength(1);
    });
});

describe('Vocabulary Type Grouping', () => {
    it('should group keywords by vocabulary type', () => {
        const keywords: SelectedKeyword[] = [
            {
                id: 'science-1',
                text: 'KEYWORD 1',
                path: 'PATH 1',
                language: 'en',
                scheme: 'Science',
                schemeURI: 'https://test.com',
                vocabularyType: 'science',
            },
            {
                id: 'science-2',
                text: 'KEYWORD 2',
                path: 'PATH 2',
                language: 'en',
                scheme: 'Science',
                schemeURI: 'https://test.com',
                vocabularyType: 'science',
            },
            {
                id: 'platform-1',
                text: 'PLATFORM 1',
                path: 'PATH 3',
                language: 'en',
                scheme: 'Platforms',
                schemeURI: 'https://test.com',
                vocabularyType: 'platforms',
            },
        ];

        const grouped: Record<GCMDVocabularyType, SelectedKeyword[]> = {
            science: [],
            platforms: [],
            instruments: [],
        };

        keywords.forEach((keyword) => {
            grouped[keyword.vocabularyType].push(keyword);
        });

        expect(grouped.science).toHaveLength(2);
        expect(grouped.platforms).toHaveLength(1);
        expect(grouped.instruments).toHaveLength(0);
    });

    it('should check if vocabulary type has keywords', () => {
        const keywords: SelectedKeyword[] = [
            {
                id: 'science-1',
                text: 'TEST',
                path: 'TEST',
                language: 'en',
                scheme: 'Science',
                schemeURI: 'https://test.com',
                vocabularyType: 'science',
            },
        ];

        const hasKeywords = (type: GCMDVocabularyType): boolean => {
            return keywords.some((k) => k.vocabularyType === type);
        };

        expect(hasKeywords('science')).toBe(true);
        expect(hasKeywords('platforms')).toBe(false);
        expect(hasKeywords('instruments')).toBe(false);
    });
});
