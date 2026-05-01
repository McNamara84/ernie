import { describe, expect, it } from 'vitest';

import {
    DESCRIPTION_SECTION_KEYS,
    expandMetadataOrder,
    filterDescriptionsBySection,
    isDescriptionSectionKey,
    normalizeDescriptionType,
} from '@/pages/LandingPages/lib/metadata-sections';

describe('metadata-sections helpers', () => {
    it('normalizes description types defensively', () => {
        expect(normalizeDescriptionType('Technical Information')).toBe('technicalinformation');
        expect(normalizeDescriptionType('Table of Contents')).toBe('tableofcontents');
        expect(normalizeDescriptionType(undefined)).toBe('');
        expect(normalizeDescriptionType(null)).toBe('');
    });

    it('filters descriptions by normalized section aliases', () => {
        const descriptions = [
            { id: 1, value: 'Tech A', description_type: 'TechnicalInfo' },
            { id: 2, value: 'Tech B', description_type: 'Technical Information' },
            { id: 3, value: 'Ignored', description_type: null },
        ];

        expect(filterDescriptionsBySection(descriptions, 'technical_info')).toEqual([
            descriptions[0],
            descriptions[1],
        ]);
        expect(filterDescriptionsBySection(descriptions, 'methods')).toEqual([]);
    });

    it('identifies description section keys correctly', () => {
        expect(isDescriptionSectionKey('abstract')).toBe(true);
        expect(isDescriptionSectionKey('other')).toBe(true);
        expect(isDescriptionSectionKey('creators')).toBe(false);
        expect(isDescriptionSectionKey('descriptions')).toBe(false);
    });

    it('expands legacy descriptions and deduplicates repeated modules', () => {
        expect(expandMetadataOrder(['descriptions', 'methods', 'creators', 'descriptions', 'keywords'])).toEqual([
            ...DESCRIPTION_SECTION_KEYS,
            'creators',
            'keywords',
        ]);
    });
});