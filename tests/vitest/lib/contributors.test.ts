import { describe, expect, it } from 'vitest';

import { inferContributorTypeFromRoles, normaliseContributorRoleLabel } from '@/lib/contributors';

describe('normaliseContributorRoleLabel', () => {
    it('normalizes known role labels', () => {
        expect(normaliseContributorRoleLabel('ContactPerson')).toBe('Contact Person');
        expect(normaliseContributorRoleLabel('datacollector')).toBe('Data Collector');
        expect(normaliseContributorRoleLabel('DataCurator')).toBe('Data Curator');
        expect(normaliseContributorRoleLabel('editor')).toBe('Editor');
        expect(normaliseContributorRoleLabel('Other')).toBe('Other');
    });

    it('returns original when not recognized', () => {
        expect(normaliseContributorRoleLabel('UnknownRole')).toBe('UnknownRole');
    });

    it('returns empty string for empty input', () => {
        expect(normaliseContributorRoleLabel('')).toBe('');
        expect(normaliseContributorRoleLabel('   ')).toBe('');
    });

    it('handles accented characters by stripping diacritics', () => {
        // The normalizeKey strips diacritics, so 'éditor' should resolve to 'editor'
        expect(normaliseContributorRoleLabel('éditor')).toBe('Editor');
    });
});

describe('inferContributorTypeFromRoles', () => {
    it('returns institution when rawType is institution', () => {
        expect(inferContributorTypeFromRoles('Institution', [])).toBe('institution');
        expect(inferContributorTypeFromRoles('institution', [])).toBe('institution');
        expect(inferContributorTypeFromRoles(' Institution ', [])).toBe('institution');
    });

    it('returns institution when all roles are institution-only', () => {
        expect(inferContributorTypeFromRoles(null, ['Distributor'])).toBe('institution');
        expect(inferContributorTypeFromRoles(null, ['HostingInstitution'])).toBe('institution');
        expect(inferContributorTypeFromRoles(null, ['Sponsor', 'Distributor'])).toBe('institution');
    });

    it('returns person when roles mix person and institution types', () => {
        expect(inferContributorTypeFromRoles(null, ['Researcher', 'Distributor'])).toBe('person');
    });

    it('returns person when roles are person-only', () => {
        expect(inferContributorTypeFromRoles(null, ['Researcher'])).toBe('person');
        expect(inferContributorTypeFromRoles(null, ['ProjectLeader', 'Supervisor'])).toBe('person');
    });

    it('returns person for empty roles and null type', () => {
        expect(inferContributorTypeFromRoles(null, [])).toBe('person');
        expect(inferContributorTypeFromRoles(undefined, [])).toBe('person');
    });

    it('returns institution when roles require it even with non-institution rawType', () => {
        expect(inferContributorTypeFromRoles('Person', ['Distributor'])).toBe('institution');
    });
});
