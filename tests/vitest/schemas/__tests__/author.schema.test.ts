import { describe, expect, it } from 'vitest';

import {
    authorsArraySchema,
    authorSchema,
    authorsWithContactSchema,
    institutionAuthorSchema,
    personAuthorSchema,
    validateContactAuthor,
} from '@/schemas/author.schema';

describe('Author Schemas', () => {
    describe('personAuthorSchema', () => {
        it('accepts valid person author', () => {
            const result = personAuthorSchema.safeParse({
                id: '1',
                type: 'person',
                orcid: '0000-0001-2345-6789',
                firstName: 'Jane',
                lastName: 'Doe',
                email: 'jane@example.com',
                website: 'https://jane.example.com',
                isContact: true,
                affiliations: [{ value: 'GFZ', rorId: null }],
            });
            expect(result.success).toBe(true);
        });

        it('requires firstName and lastName', () => {
            const result = personAuthorSchema.safeParse({
                id: '1',
                type: 'person',
                firstName: '',
                lastName: '',
            });
            expect(result.success).toBe(false);
        });

        it('validates email format', () => {
            const result = personAuthorSchema.safeParse({
                id: '1',
                type: 'person',
                firstName: 'Jane',
                lastName: 'Doe',
                email: 'invalid',
            });
            expect(result.success).toBe(false);
        });

        it('accepts empty optional fields', () => {
            const result = personAuthorSchema.safeParse({
                id: '1',
                type: 'person',
                firstName: 'Jane',
                lastName: 'Doe',
                email: '',
                website: '',
                orcid: '',
            });
            expect(result.success).toBe(true);
        });
    });

    describe('institutionAuthorSchema', () => {
        it('accepts valid institution author', () => {
            const result = institutionAuthorSchema.safeParse({
                id: '1',
                type: 'institution',
                institutionName: 'GFZ Potsdam',
                affiliations: [],
            });
            expect(result.success).toBe(true);
        });

        it('requires institutionName', () => {
            const result = institutionAuthorSchema.safeParse({
                id: '1',
                type: 'institution',
                institutionName: '',
            });
            expect(result.success).toBe(false);
        });
    });

    describe('authorSchema (discriminated union)', () => {
        it('accepts person author', () => {
            const result = authorSchema.safeParse({
                id: '1',
                type: 'person',
                firstName: 'Jane',
                lastName: 'Doe',
            });
            expect(result.success).toBe(true);
        });

        it('accepts institution author', () => {
            const result = authorSchema.safeParse({
                id: '1',
                type: 'institution',
                institutionName: 'GFZ',
            });
            expect(result.success).toBe(true);
        });

        it('rejects unknown type', () => {
            const result = authorSchema.safeParse({
                id: '1',
                type: 'unknown',
                firstName: 'Jane',
                lastName: 'Doe',
            });
            expect(result.success).toBe(false);
        });
    });

    describe('authorsArraySchema', () => {
        it('requires at least one author', () => {
            const result = authorsArraySchema.safeParse([]);
            expect(result.success).toBe(false);
        });

        it('accepts array with authors', () => {
            const result = authorsArraySchema.safeParse([
                { id: '1', type: 'person', firstName: 'Jane', lastName: 'Doe' },
            ]);
            expect(result.success).toBe(true);
        });
    });

    describe('validateContactAuthor', () => {
        it('returns true when a contact author exists', () => {
            expect(
                validateContactAuthor([
                    { id: '1', type: 'person', firstName: 'Jane', lastName: 'Doe', isContact: true, affiliations: [], affiliationsInput: '' },
                ]),
            ).toBe(true);
        });

        it('returns false when no contact author exists', () => {
            expect(
                validateContactAuthor([
                    { id: '1', type: 'person', firstName: 'Jane', lastName: 'Doe', isContact: false, affiliations: [], affiliationsInput: '' },
                ]),
            ).toBe(false);
        });

        it('returns false for institution-only authors', () => {
            expect(
                validateContactAuthor([
                    { id: '1', type: 'institution', institutionName: 'GFZ', affiliations: [], affiliationsInput: '' },
                ]),
            ).toBe(false);
        });
    });

    describe('authorsWithContactSchema', () => {
        it('rejects authors without contact', () => {
            const result = authorsWithContactSchema.safeParse([
                { id: '1', type: 'person', firstName: 'Jane', lastName: 'Doe', isContact: false },
            ]);
            expect(result.success).toBe(false);
        });

        it('accepts authors with contact', () => {
            const result = authorsWithContactSchema.safeParse([
                { id: '1', type: 'person', firstName: 'Jane', lastName: 'Doe', isContact: true },
            ]);
            expect(result.success).toBe(true);
        });
    });
});
