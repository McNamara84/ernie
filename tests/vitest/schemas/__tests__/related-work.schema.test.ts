import { describe, expect, it } from 'vitest';

import {
    identifierTypeSchema,
    relatedIdentifierSchema,
    relatedIdentifiersArraySchema,
    relatedWorkFormSchema,
    relationTypeSchema,
} from '@/schemas/related-work.schema';

describe('Related Work Schemas', () => {
    describe('identifierTypeSchema', () => {
        it('accepts valid identifier types', () => {
            expect(identifierTypeSchema.safeParse('DOI').success).toBe(true);
            expect(identifierTypeSchema.safeParse('URL').success).toBe(true);
            expect(identifierTypeSchema.safeParse('IGSN').success).toBe(true);
            expect(identifierTypeSchema.safeParse('Handle').success).toBe(true);
        });

        it('rejects invalid identifier type', () => {
            expect(identifierTypeSchema.safeParse('INVALID').success).toBe(false);
        });
    });

    describe('relationTypeSchema', () => {
        it('accepts valid relation types', () => {
            expect(relationTypeSchema.safeParse('Cites').success).toBe(true);
            expect(relationTypeSchema.safeParse('IsCitedBy').success).toBe(true);
            expect(relationTypeSchema.safeParse('IsPartOf').success).toBe(true);
            expect(relationTypeSchema.safeParse('HasPart').success).toBe(true);
        });

        it('rejects invalid relation type', () => {
            expect(relationTypeSchema.safeParse('InvalidType').success).toBe(false);
        });
    });

    describe('relatedIdentifierSchema', () => {
        it('accepts valid related identifier', () => {
            const result = relatedIdentifierSchema.safeParse({
                identifier: '10.5880/test.2024.001',
                identifier_type: 'DOI',
                relation_type: 'Cites',
            });
            expect(result.success).toBe(true);
        });

        it('requires identifier', () => {
            const result = relatedIdentifierSchema.safeParse({
                identifier: '',
                identifier_type: 'DOI',
                relation_type: 'Cites',
            });
            expect(result.success).toBe(false);
        });

        it('accepts optional fields', () => {
            const result = relatedIdentifierSchema.safeParse({
                id: 1,
                identifier: '10.5880/test',
                identifier_type: 'DOI',
                relation_type: 'Cites',
                position: 0,
                related_title: 'Some Title',
                related_metadata: { key: 'value' },
            });
            expect(result.success).toBe(true);
        });
    });

    describe('relatedIdentifiersArraySchema', () => {
        it('defaults to empty array', () => {
            const result = relatedIdentifiersArraySchema.safeParse(undefined);
            expect(result.success).toBe(true);
            if (result.success) expect(result.data).toEqual([]);
        });
    });

    describe('relatedWorkFormSchema', () => {
        it('accepts valid form data', () => {
            const result = relatedWorkFormSchema.safeParse({
                identifier: 'https://example.com',
                identifierType: 'URL',
                relationType: 'References',
            });
            expect(result.success).toBe(true);
        });

        it('requires all fields', () => {
            const result = relatedWorkFormSchema.safeParse({
                identifier: '',
                identifierType: 'DOI',
                relationType: 'Cites',
            });
            expect(result.success).toBe(false);
        });
    });
});
