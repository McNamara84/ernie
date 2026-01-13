import { describe, expect, it } from 'vitest';

import { inferContributorTypeFromRoles, normaliseContributorRoleLabel } from '@/lib/contributors';

describe('contributors library', () => {
    describe('normaliseContributorRoleLabel', () => {
        it('normalises known contributor roles', () => {
            expect(normaliseContributorRoleLabel('  Research Group  ')).toBe('Research Group');
            expect(normaliseContributorRoleLabel('contactperson')).toBe('Contact Person');
            expect(normaliseContributorRoleLabel('DataCollector')).toBe('Data Collector');
            expect(normaliseContributorRoleLabel('DATA MANAGER')).toBe('Data Manager');
        });

        it('returns original value for unknown roles', () => {
            expect(normaliseContributorRoleLabel('Custom Role')).toBe('Custom Role');
            expect(normaliseContributorRoleLabel('Unknown')).toBe('Unknown');
        });

        it('returns empty string for empty input', () => {
            expect(normaliseContributorRoleLabel('')).toBe('');
            expect(normaliseContributorRoleLabel('   ')).toBe('');
        });

        it('handles roles with special characters', () => {
            expect(normaliseContributorRoleLabel('Project-Leader')).toBe('Project Leader');
            expect(normaliseContributorRoleLabel('work_package_leader')).toBe('Work Package Leader');
        });

        it('handles roles with diacritics/accented characters', () => {
            // NFKD normalization removes diacritics
            expect(normaliseContributorRoleLabel('éditor')).toBe('Editor');
        });

        it('normalizes all known contributor roles correctly', () => {
            expect(normaliseContributorRoleLabel('datacurator')).toBe('Data Curator');
            expect(normaliseContributorRoleLabel('distributor')).toBe('Distributor');
            expect(normaliseContributorRoleLabel('editor')).toBe('Editor');
            expect(normaliseContributorRoleLabel('hostinginstitution')).toBe('Hosting Institution');
            expect(normaliseContributorRoleLabel('producer')).toBe('Producer');
            expect(normaliseContributorRoleLabel('projectmanager')).toBe('Project Manager');
            expect(normaliseContributorRoleLabel('projectmember')).toBe('Project Member');
            expect(normaliseContributorRoleLabel('registrationagency')).toBe('Registration Agency');
            expect(normaliseContributorRoleLabel('registrationauthority')).toBe('Registration Authority');
            expect(normaliseContributorRoleLabel('relatedperson')).toBe('Related Person');
            expect(normaliseContributorRoleLabel('researcher')).toBe('Researcher');
            expect(normaliseContributorRoleLabel('rightsholder')).toBe('Rights Holder');
            expect(normaliseContributorRoleLabel('sponsor')).toBe('Sponsor');
            expect(normaliseContributorRoleLabel('supervisor')).toBe('Supervisor');
            expect(normaliseContributorRoleLabel('translator')).toBe('Translator');
            expect(normaliseContributorRoleLabel('other')).toBe('Other');
        });
    });

    describe('inferContributorTypeFromRoles', () => {
        it('treats institution-only contributor roles as institutional contributors', () => {
            expect(inferContributorTypeFromRoles(null, ['Sponsor'])).toBe('institution');
            expect(inferContributorTypeFromRoles('person', ['Research Group'])).toBe('institution');
        });

        it('returns institution when rawType is explicitly institution', () => {
            expect(inferContributorTypeFromRoles('institution', [])).toBe('institution');
            expect(inferContributorTypeFromRoles('Institution', [])).toBe('institution');
            expect(inferContributorTypeFromRoles('  institution  ', [])).toBe('institution');
            expect(inferContributorTypeFromRoles('INSTITUTION', ['Editor'])).toBe('institution');
        });

        it('keeps mixed roles with person-only entries classified as person contributors', () => {
            expect(inferContributorTypeFromRoles(undefined, ['Research Group', 'Editor'])).toBe('person');
            expect(inferContributorTypeFromRoles(null, ['Sponsor', 'Supervisor'])).toBe('person');
        });

        it('returns person for empty roles array', () => {
            expect(inferContributorTypeFromRoles(null, [])).toBe('person');
            expect(inferContributorTypeFromRoles(undefined, [])).toBe('person');
            expect(inferContributorTypeFromRoles('person', [])).toBe('person');
        });

        it('returns person when rawType is not institution', () => {
            expect(inferContributorTypeFromRoles('person', [])).toBe('person');
            expect(inferContributorTypeFromRoles('Person', [])).toBe('person');
            expect(inferContributorTypeFromRoles('other', [])).toBe('person');
        });

        it('identifies all institution-only roles correctly', () => {
            expect(inferContributorTypeFromRoles(null, ['Distributor'])).toBe('institution');
            expect(inferContributorTypeFromRoles(null, ['Hosting Institution'])).toBe('institution');
            expect(inferContributorTypeFromRoles(null, ['Registration Agency'])).toBe('institution');
            expect(inferContributorTypeFromRoles(null, ['Registration Authority'])).toBe('institution');
            expect(inferContributorTypeFromRoles(null, ['Research Group'])).toBe('institution');
            expect(inferContributorTypeFromRoles(null, ['Sponsor'])).toBe('institution');
        });

        it('returns institution when all roles are institution-only', () => {
            expect(inferContributorTypeFromRoles(null, ['Sponsor', 'Distributor'])).toBe('institution');
            expect(inferContributorTypeFromRoles(null, ['Research Group', 'Hosting Institution'])).toBe('institution');
        });

        it('returns person for person-specific roles', () => {
            expect(inferContributorTypeFromRoles(null, ['Contact Person'])).toBe('person');
            expect(inferContributorTypeFromRoles(null, ['Researcher'])).toBe('person');
            expect(inferContributorTypeFromRoles(null, ['Project Leader'])).toBe('person');
            expect(inferContributorTypeFromRoles(null, ['Supervisor'])).toBe('person');
        });
    });
});
