import { describe, expect, it } from 'vitest';
import { inferContributorTypeFromRoles, normaliseContributorRoleLabel } from '@/lib/contributors';

describe('contributors library', () => {
    it('normalises known contributor roles without altering unknown values', () => {
        expect(normaliseContributorRoleLabel('  Research Group  ')).toBe('Research Group');
        expect(normaliseContributorRoleLabel('Custom Role')).toBe('Custom Role');
    });

    it('treats institution-only contributor roles as institutional contributors', () => {
        expect(inferContributorTypeFromRoles(null, ['Sponsor'])).toBe('institution');
        expect(inferContributorTypeFromRoles('person', ['Research Group'])).toBe('institution');
    });

    it('keeps mixed roles with person-only entries classified as person contributors', () => {
        expect(inferContributorTypeFromRoles(undefined, ['Research Group', 'Editor'])).toBe('person');
    });
});
