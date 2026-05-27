import { describe, expect, it } from 'vitest';

import {
    DESCRIPTION_SECTION_KEYS,
    expandMetadataOrder,
    filterDescriptionsBySection,
    isDescriptionSectionKey,
    normalizeDescriptionType,
} from '@/pages/LandingPages/lib/metadata-sections';
import {
    IGSN_LEFT_COLUMN_SECTIONS,
    normalizeLeftColumnOrder,
    normalizeRightColumnOrder,
    RESOURCE_LEFT_COLUMN_SECTIONS,
    RIGHT_COLUMN_SECTIONS,
} from '@/pages/LandingPages/lib/section-catalog';

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

describe('section-catalog helpers', () => {
    it('keeps canonical section orders aligned with landing page templates', () => {
        expect(RIGHT_COLUMN_SECTIONS).toEqual([
            ...DESCRIPTION_SECTION_KEYS,
            'creators',
            'contributors',
            'funders',
            'keywords',
            'metadata_download',
            'location',
        ]);
        expect(RESOURCE_LEFT_COLUMN_SECTIONS).toEqual(['files', 'contact', 'model_description', 'related_work']);
        expect(IGSN_LEFT_COLUMN_SECTIONS).toEqual(['general', 'acquisition', 'contact', 'model_description', 'related_work']);
    });

    it('normalizes right column order and expands the legacy descriptions section', () => {
        expect(normalizeRightColumnOrder(['location', 'descriptions', 'keywords'] as never)).toEqual([
            'location',
            ...DESCRIPTION_SECTION_KEYS,
            'keywords',
            'creators',
            'contributors',
            'funders',
            'metadata_download',
        ]);
    });

    it('drops duplicate and unknown right column sections while appending missing canonical sections', () => {
        expect(normalizeRightColumnOrder(['unknown', 'abstract', 'abstract', 'location'] as never)).toEqual([
            'abstract',
            'methods',
            'technical_info',
            'series_information',
            'table_of_contents',
            'other',
            'creators',
            'contributors',
            'funders',
            'keywords',
            'metadata_download',
            'location',
        ]);
    });

    it('normalizes left column order by template type', () => {
        expect(normalizeLeftColumnOrder(['contact', 'files', 'unknown'] as never, 'resource')).toEqual([
            'contact',
            'files',
            'model_description',
            'related_work',
        ]);
        expect(normalizeLeftColumnOrder(['contact', 'general', 'files'] as never, 'igsn')).toEqual([
            'contact',
            'general',
            'acquisition',
            'model_description',
            'related_work',
        ]);
    });
});