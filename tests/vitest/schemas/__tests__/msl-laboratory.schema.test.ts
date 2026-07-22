import { describe, expect, it } from 'vitest';

import { mslLaboratoriesResponseSchema, mslLaboratorySchema, mslLaboratoryVocabularyEntrySchema } from '@/schemas/msl-laboratory.schema';

const ENTRY = {
    identifier: 'lab:one',
    name: 'Rock Lab',
    display_name: 'Rock Lab (Example University)',
    affiliation_name: 'Example University',
    affiliation_ror: 'https://ror.org/04pp8hn57',
    scientific_domain: 'Rock physics',
    country: 'Netherlands',
};

describe('MSL laboratory schemas', () => {
    it('keeps the persisted resource snapshot limited to four fields', () => {
        expect(mslLaboratorySchema.parse(ENTRY)).toEqual({
            identifier: 'lab:one',
            name: 'Rock Lab',
            affiliation_name: 'Example University',
            affiliation_ror: 'https://ror.org/04pp8hn57',
        });
    });

    it('accepts nullable ROR values in both persisted and vocabulary data', () => {
        expect(mslLaboratorySchema.safeParse({ ...ENTRY, affiliation_ror: null }).success).toBe(true);
        expect(mslLaboratoryVocabularyEntrySchema.safeParse({ ...ENTRY, affiliation_ror: null }).success).toBe(true);
    });

    it.each(['', 'http://ror.org/04pp8hn57', 'https://ror.org/04pp8hn57/', 'https://example.test/04pp8hn57', 'javascript:alert(1)'])(
        'rejects a non-canonical vocabulary ROR URL: %s',
        (affiliationRor) => {
            expect(mslLaboratoryVocabularyEntrySchema.safeParse({ ...ENTRY, affiliation_ror: affiliationRor }).success).toBe(false);
        },
    );

    it('keeps the persisted schema tolerant of a historical non-canonical ROR value', () => {
        expect(mslLaboratorySchema.safeParse({ ...ENTRY, affiliation_ror: 'http://ror.org/04pp8hn57' }).success).toBe(true);
    });

    it('allows empty historical affiliations only in persisted resource snapshots', () => {
        expect(mslLaboratorySchema.safeParse({ ...ENTRY, affiliation_name: '' }).success).toBe(true);
        expect(mslLaboratoryVocabularyEntrySchema.safeParse({ ...ENTRY, affiliation_name: null }).success).toBe(false);
        expect(mslLaboratoryVocabularyEntrySchema.safeParse({ ...ENTRY, affiliation_name: '' }).success).toBe(false);
    });

    it.each(['identifier', 'name', 'display_name', 'affiliation_name', 'scientific_domain', 'country'])(
        'requires the non-empty vocabulary field %s',
        (field) => {
            expect(mslLaboratoryVocabularyEntrySchema.safeParse({ ...ENTRY, [field]: '' }).success).toBe(false);
        },
    );

    it('validates response metadata and matching totals', () => {
        const valid = {
            version: '1.1',
            lastUpdated: '2026-07-21T12:00:00+00:00',
            total: 1,
            data: [ENTRY],
        };

        expect(mslLaboratoriesResponseSchema.safeParse(valid).success).toBe(true);
        expect(mslLaboratoriesResponseSchema.safeParse({ ...valid, total: 2 }).success).toBe(false);
        expect(mslLaboratoriesResponseSchema.safeParse({ ...valid, version: '' }).success).toBe(false);
        expect(mslLaboratoriesResponseSchema.safeParse({ ...valid, lastUpdated: '' }).success).toBe(false);
    });

    it('accepts an empty vocabulary response', () => {
        expect(
            mslLaboratoriesResponseSchema.safeParse({
                version: '1.1',
                lastUpdated: '2026-07-21T12:00:00+00:00',
                total: 0,
                data: [],
            }).success,
        ).toBe(true);
    });
});
