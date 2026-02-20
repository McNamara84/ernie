import { describe, expect, it } from 'vitest';

import {
    funderIdentifierTypeSchema,
    fundingReferenceSchema,
    fundingReferencesArraySchema,
    rorFunderSchema,
} from '@/schemas/funding-reference.schema';

describe('Funding Reference Schemas', () => {
    describe('funderIdentifierTypeSchema', () => {
        it('accepts valid types', () => {
            expect(funderIdentifierTypeSchema.safeParse('ROR').success).toBe(true);
            expect(funderIdentifierTypeSchema.safeParse('Crossref Funder ID').success).toBe(true);
            expect(funderIdentifierTypeSchema.safeParse('ISNI').success).toBe(true);
            expect(funderIdentifierTypeSchema.safeParse('GRID').success).toBe(true);
            expect(funderIdentifierTypeSchema.safeParse('Other').success).toBe(true);
        });

        it('accepts null', () => {
            expect(funderIdentifierTypeSchema.safeParse(null).success).toBe(true);
        });

        it('rejects invalid type', () => {
            expect(funderIdentifierTypeSchema.safeParse('INVALID').success).toBe(false);
        });
    });

    describe('fundingReferenceSchema', () => {
        it('accepts valid funding reference', () => {
            const result = fundingReferenceSchema.safeParse({
                id: '1',
                funderName: 'DFG',
                funderIdentifier: 'https://doi.org/10.13039/501100001659',
                funderIdentifierType: 'Crossref Funder ID',
                awardNumber: 'ABC-123',
                awardUri: 'https://gepris.dfg.de/gepris/projekt/123',
                awardTitle: 'Some Project',
            });
            expect(result.success).toBe(true);
        });

        it('requires funder name', () => {
            const result = fundingReferenceSchema.safeParse({
                id: '1',
                funderName: '',
                funderIdentifierType: null,
            });
            expect(result.success).toBe(false);
        });

        it('validates award URI', () => {
            const result = fundingReferenceSchema.safeParse({
                id: '1',
                funderName: 'DFG',
                funderIdentifierType: null,
                awardUri: 'not-a-url',
            });
            expect(result.success).toBe(false);
        });

        it('accepts empty optional fields', () => {
            const result = fundingReferenceSchema.safeParse({
                id: '1',
                funderName: 'DFG',
                funderIdentifier: '',
                funderIdentifierType: null,
                awardNumber: '',
                awardUri: '',
                awardTitle: '',
            });
            expect(result.success).toBe(true);
        });
    });

    describe('fundingReferencesArraySchema', () => {
        it('defaults to empty array', () => {
            const result = fundingReferencesArraySchema.safeParse(undefined);
            expect(result.success).toBe(true);
            if (result.success) expect(result.data).toEqual([]);
        });
    });

    describe('rorFunderSchema', () => {
        it('accepts valid ROR funder', () => {
            const result = rorFunderSchema.safeParse({
                prefLabel: 'German Research Foundation',
                rorId: 'https://ror.org/018mejw64',
                otherLabel: ['DFG', 'Deutsche Forschungsgemeinschaft'],
            });
            expect(result.success).toBe(true);
        });

        it('defaults otherLabel to empty array', () => {
            const result = rorFunderSchema.safeParse({
                prefLabel: 'DFG',
                rorId: 'https://ror.org/018mejw64',
            });
            expect(result.success).toBe(true);
            if (result.success) expect(result.data.otherLabel).toEqual([]);
        });
    });
});
