import { describe, expect, it } from 'vitest';

import {
    contributorRoleTagSchema,
    contributorSchema,
    contributorsArraySchema,
    institutionContributorSchema,
    personContributorSchema,
} from '@/schemas/contributor.schema';

describe('Contributor Schemas', () => {
    describe('contributorRoleTagSchema', () => {
        it('accepts valid role', () => {
            expect(contributorRoleTagSchema.safeParse({ value: 'ContactPerson' }).success).toBe(true);
        });

        it('rejects empty role', () => {
            expect(contributorRoleTagSchema.safeParse({ value: '' }).success).toBe(false);
        });
    });

    describe('personContributorSchema', () => {
        it('accepts valid person contributor', () => {
            const result = personContributorSchema.safeParse({
                id: '1',
                type: 'person',
                firstName: 'Jane',
                lastName: 'Doe',
                roles: [{ value: 'DataCurator' }],
            });
            expect(result.success).toBe(true);
        });

        it('requires at least one role', () => {
            const result = personContributorSchema.safeParse({
                id: '1',
                type: 'person',
                firstName: 'Jane',
                lastName: 'Doe',
                roles: [],
            });
            expect(result.success).toBe(false);
        });

        it('requires firstName and lastName', () => {
            const result = personContributorSchema.safeParse({
                id: '1',
                type: 'person',
                firstName: '',
                lastName: '',
                roles: [{ value: 'DataCurator' }],
            });
            expect(result.success).toBe(false);
        });

        it('validates ORCID format', () => {
            const result = personContributorSchema.safeParse({
                id: '1',
                type: 'person',
                firstName: 'Jane',
                lastName: 'Doe',
                orcid: 'invalid',
                roles: [{ value: 'DataCurator' }],
            });
            expect(result.success).toBe(false);
        });
    });

    describe('institutionContributorSchema', () => {
        it('accepts valid institution', () => {
            const result = institutionContributorSchema.safeParse({
                id: '1',
                type: 'institution',
                institutionName: 'GFZ Potsdam',
                roles: [{ value: 'HostingInstitution' }],
            });
            expect(result.success).toBe(true);
        });

        it('requires institutionName', () => {
            const result = institutionContributorSchema.safeParse({
                id: '1',
                type: 'institution',
                institutionName: '',
                roles: [{ value: 'HostingInstitution' }],
            });
            expect(result.success).toBe(false);
        });
    });

    describe('contributorSchema (discriminated union)', () => {
        it('accepts person contributor', () => {
            expect(contributorSchema.safeParse({
                id: '1', type: 'person', firstName: 'Jane', lastName: 'Doe', roles: [{ value: 'DataCurator' }],
            }).success).toBe(true);
        });

        it('accepts institution contributor', () => {
            expect(contributorSchema.safeParse({
                id: '1', type: 'institution', institutionName: 'GFZ', roles: [{ value: 'HostingInstitution' }],
            }).success).toBe(true);
        });

        it('rejects unknown type', () => {
            expect(contributorSchema.safeParse({
                id: '1', type: 'unknown', firstName: 'Jane', lastName: 'Doe', roles: [],
            }).success).toBe(false);
        });
    });

    describe('contributorsArraySchema', () => {
        it('defaults to empty array', () => {
            const result = contributorsArraySchema.safeParse(undefined);
            expect(result.success).toBe(true);
            if (result.success) expect(result.data).toEqual([]);
        });

        it('accepts empty array (contributors are optional)', () => {
            expect(contributorsArraySchema.safeParse([]).success).toBe(true);
        });
    });
});
