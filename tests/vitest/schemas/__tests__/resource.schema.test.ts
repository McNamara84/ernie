import { describe, expect, it } from 'vitest';

import {
    dateEntrySchema,
    descriptionSchema,
    gcmdKeywordSchema,
    licenseSchema,
    mslLaboratorySchema,
    partialResourceSchema,
    resourceSchema,
    titlesArraySchema,
    titleSchema,
} from '@/schemas/resource.schema';

describe('Resource Schemas', () => {
    describe('titleSchema', () => {
        it('accepts valid title', () => {
            expect(titleSchema.safeParse({ id: '1', title: 'Test Dataset', titleType: 'main-title' }).success).toBe(true);
        });

        it('rejects empty title', () => {
            expect(titleSchema.safeParse({ id: '1', title: '', titleType: 'main-title' }).success).toBe(false);
        });
    });

    describe('titlesArraySchema', () => {
        it('requires at least one title', () => {
            expect(titlesArraySchema.safeParse([]).success).toBe(false);
        });
    });

    describe('licenseSchema', () => {
        it('accepts valid license', () => {
            expect(licenseSchema.safeParse({ id: '1', license: 'CC-BY-4.0' }).success).toBe(true);
        });

        it('rejects empty license', () => {
            expect(licenseSchema.safeParse({ id: '1', license: '' }).success).toBe(false);
        });
    });

    describe('dateEntrySchema', () => {
        it('accepts valid date entry', () => {
            const result = dateEntrySchema.safeParse({
                id: '1',
                startDate: '2024-01-01',
                endDate: '2024-12-31',
                dateType: 'Created',
            });
            expect(result.success).toBe(true);
        });

        it('accepts null dates', () => {
            const result = dateEntrySchema.safeParse({
                id: '1',
                startDate: null,
                endDate: null,
                dateType: 'Created',
            });
            expect(result.success).toBe(true);
        });

        it('requires dateType', () => {
            expect(dateEntrySchema.safeParse({ id: '1', startDate: null, endDate: null, dateType: '' }).success).toBe(false);
        });
    });

    describe('descriptionSchema', () => {
        it('accepts valid description', () => {
            const result = descriptionSchema.safeParse({
                id: '1',
                type: 'Abstract',
                description: 'A test abstract',
            });
            expect(result.success).toBe(true);
        });

        it('rejects empty description', () => {
            expect(descriptionSchema.safeParse({ id: '1', type: 'Abstract', description: '' }).success).toBe(false);
        });
    });

    describe('gcmdKeywordSchema', () => {
        it('accepts valid keyword', () => {
            const result = gcmdKeywordSchema.safeParse({
                id: '1',
                path: 'EARTH SCIENCE > ATMOSPHERE',
                text: 'Atmosphere',
                scheme: 'GCMD',
            });
            expect(result.success).toBe(true);
        });
    });

    describe('mslLaboratorySchema', () => {
        it('accepts valid lab', () => {
            const result = mslLaboratorySchema.safeParse({
                identifier: 'lab-1',
                name: 'Rock Mechanics Lab',
                affiliation_name: 'GFZ Potsdam',
                affiliation_ror: 'https://ror.org/04z8jg394',
            });
            expect(result.success).toBe(true);
        });
    });

    describe('resourceSchema', () => {
        it('accepts minimal valid resource', () => {
            const result = resourceSchema.safeParse({
                resourceType: 'Dataset',
                language: 'en',
                titles: [{ id: '1', title: 'Test', titleType: 'main-title' }],
                authors: [{ id: '1', type: 'person', firstName: 'Jane', lastName: 'Doe' }],
                contributors: [],
                licenses: [{ id: '1', license: 'CC-BY-4.0' }],
            });
            expect(result.success).toBe(true);
        });

        it('requires resource type', () => {
            const result = resourceSchema.safeParse({
                resourceType: '',
                language: 'en',
                titles: [{ id: '1', title: 'Test', titleType: 'main-title' }],
                authors: [{ id: '1', type: 'person', firstName: 'Jane', lastName: 'Doe' }],
                contributors: [],
                licenses: [{ id: '1', license: 'CC-BY-4.0' }],
            });
            expect(result.success).toBe(false);
        });
    });

    describe('partialResourceSchema', () => {
        it('accepts empty object for drafts', () => {
            expect(partialResourceSchema.safeParse({}).success).toBe(true);
        });

        it('accepts partial data', () => {
            const result = partialResourceSchema.safeParse({
                resourceType: 'Dataset',
                titles: [{ id: '1', title: 'Draft', titleType: 'main-title' }],
            });
            expect(result.success).toBe(true);
        });
    });
});
