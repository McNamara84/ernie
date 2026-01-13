import { describe, expect, it } from 'vitest';

import {
    canAddDate,
    canAddLicense,
    canAddTitle,
    createEmptyInstitutionAuthor,
    createEmptyInstitutionContributor,
    createEmptyPersonAuthor,
    createEmptyPersonContributor,
    mapInitialAuthorToEntry,
    mapInitialContributorToEntry,
    normaliseInitialAffiliations,
    normaliseInitialContributorRoles,
    normalizeOrcid,
    normalizeTitleTypeSlug,
    normalizeWebsiteUrl,
    serializeAffiliations,
} from '@/components/curation/utils/form-helpers';

describe('normalizeOrcid', () => {
    it('returns empty string for null or undefined', () => {
        expect(normalizeOrcid(null)).toBe('');
        expect(normalizeOrcid(undefined)).toBe('');
    });

    it('returns empty string for non-string values', () => {
        // @ts-expect-error Testing runtime behavior
        expect(normalizeOrcid(123)).toBe('');
    });

    it('returns trimmed input when no URL prefix', () => {
        expect(normalizeOrcid('0000-0002-1825-0097')).toBe('0000-0002-1825-0097');
        expect(normalizeOrcid('  0000-0002-1825-0097  ')).toBe('0000-0002-1825-0097');
    });

    it('strips https://orcid.org/ prefix', () => {
        expect(normalizeOrcid('https://orcid.org/0000-0002-1825-0097')).toBe('0000-0002-1825-0097');
    });

    it('strips http://orcid.org/ prefix', () => {
        expect(normalizeOrcid('http://orcid.org/0000-0002-1825-0097')).toBe('0000-0002-1825-0097');
    });

    it('strips www.orcid.org prefix', () => {
        expect(normalizeOrcid('https://www.orcid.org/0000-0002-1825-0097')).toBe('0000-0002-1825-0097');
    });

    it('handles orcid.org without protocol', () => {
        expect(normalizeOrcid('orcid.org/0000-0002-1825-0097')).toBe('0000-0002-1825-0097');
    });
});

describe('normalizeWebsiteUrl', () => {
    it('returns empty string for null or undefined', () => {
        expect(normalizeWebsiteUrl(null)).toBe('');
        expect(normalizeWebsiteUrl(undefined)).toBe('');
    });

    it('returns empty string for non-string values', () => {
        // @ts-expect-error Testing runtime behavior
        expect(normalizeWebsiteUrl(123)).toBe('');
    });

    it('preserves URLs with https://', () => {
        expect(normalizeWebsiteUrl('https://example.com')).toBe('https://example.com');
    });

    it('preserves URLs with http://', () => {
        expect(normalizeWebsiteUrl('http://example.com')).toBe('http://example.com');
    });

    it('adds https:// to URLs without protocol', () => {
        expect(normalizeWebsiteUrl('example.com')).toBe('https://example.com');
        expect(normalizeWebsiteUrl('www.example.com')).toBe('https://www.example.com');
    });

    it('trims whitespace', () => {
        expect(normalizeWebsiteUrl('  https://example.com  ')).toBe('https://example.com');
    });
});

describe('normalizeTitleTypeSlug', () => {
    it('returns empty string for null or undefined', () => {
        expect(normalizeTitleTypeSlug(null)).toBe('');
        expect(normalizeTitleTypeSlug(undefined)).toBe('');
    });

    it('returns empty string for whitespace-only input', () => {
        expect(normalizeTitleTypeSlug('   ')).toBe('');
    });

    it('converts underscores to hyphens', () => {
        expect(normalizeTitleTypeSlug('main_title')).toBe('main-title');
    });

    it('converts spaces to hyphens', () => {
        expect(normalizeTitleTypeSlug('main title')).toBe('main-title');
    });

    it('converts TitleCase to kebab-case', () => {
        expect(normalizeTitleTypeSlug('MainTitle')).toBe('main-title');
        expect(normalizeTitleTypeSlug('AlternativeTitle')).toBe('alternative-title');
    });

    it('removes non-alphanumeric characters', () => {
        expect(normalizeTitleTypeSlug('main.title!')).toBe('main-title');
    });

    it('collapses multiple hyphens', () => {
        expect(normalizeTitleTypeSlug('main---title')).toBe('main-title');
    });

    it('removes leading and trailing hyphens', () => {
        expect(normalizeTitleTypeSlug('-main-title-')).toBe('main-title');
    });

    it('converts to lowercase', () => {
        expect(normalizeTitleTypeSlug('MAIN-TITLE')).toBe('main-title');
    });
});

describe('normaliseInitialAffiliations', () => {
    it('returns empty array for null or undefined', () => {
        expect(normaliseInitialAffiliations(null)).toEqual([]);
        expect(normaliseInitialAffiliations(undefined)).toEqual([]);
    });

    it('returns empty array for non-array input', () => {
        // @ts-expect-error Testing runtime behavior
        expect(normaliseInitialAffiliations('not an array')).toEqual([]);
    });

    it('filters out null entries', () => {
        const result = normaliseInitialAffiliations([null, { value: 'Test Uni' }]);
        expect(result).toHaveLength(1);
        expect(result[0].value).toBe('Test Uni');
    });

    it('handles value property', () => {
        const result = normaliseInitialAffiliations([{ value: 'University A' }]);
        expect(result).toEqual([{ value: 'University A', rorId: null }]);
    });

    it('handles name property as fallback for value', () => {
        const result = normaliseInitialAffiliations([{ name: 'University B' }]);
        expect(result).toEqual([{ value: 'University B', rorId: null }]);
    });

    it('handles rorId property', () => {
        const result = normaliseInitialAffiliations([
            { value: 'University C', rorId: 'https://ror.org/123' },
        ]);
        expect(result).toEqual([{ value: 'University C', rorId: 'https://ror.org/123' }]);
    });

    it('handles rorid property (lowercase alternative)', () => {
        const result = normaliseInitialAffiliations([
            { value: 'University D', rorid: 'https://ror.org/456' },
        ]);
        expect(result).toEqual([{ value: 'University D', rorId: 'https://ror.org/456' }]);
    });

    it('handles identifier property as rorId fallback', () => {
        const result = normaliseInitialAffiliations([
            { value: 'University E', identifier: 'https://ror.org/789' },
        ]);
        expect(result).toEqual([{ value: 'University E', rorId: 'https://ror.org/789' }]);
    });

    it('uses rorId as value when value is empty', () => {
        const result = normaliseInitialAffiliations([{ rorId: 'https://ror.org/123' }]);
        expect(result).toEqual([{ value: 'https://ror.org/123', rorId: 'https://ror.org/123' }]);
    });

    it('filters out empty affiliations', () => {
        const result = normaliseInitialAffiliations([
            { value: '' },
            { value: '   ' },
            { value: 'Valid' },
        ]);
        expect(result).toHaveLength(1);
        expect(result[0].value).toBe('Valid');
    });
});

describe('normaliseInitialContributorRoles', () => {
    it('returns empty array for null or undefined', () => {
        expect(normaliseInitialContributorRoles(null)).toEqual([]);
        expect(normaliseInitialContributorRoles(undefined)).toEqual([]);
    });

    it('handles array of strings', () => {
        const result = normaliseInitialContributorRoles(['DataCollector', 'Editor']);
        // normaliseContributorRoleLabel adds spaces in camelCase (DataCollector -> Data Collector)
        expect(result).toEqual([{ value: 'Data Collector' }, { value: 'Editor' }]);
    });

    it('handles single string', () => {
        const result = normaliseInitialContributorRoles('DataCollector');
        expect(result).toEqual([{ value: 'Data Collector' }]);
    });

    it('handles object with values', () => {
        const result = normaliseInitialContributorRoles({ 0: 'DataCollector', 1: 'Editor' });
        expect(result).toEqual([{ value: 'Data Collector' }, { value: 'Editor' }]);
    });

    it('deduplicates roles', () => {
        const result = normaliseInitialContributorRoles(['Editor', 'Editor', 'DataCollector']);
        expect(result).toEqual([{ value: 'Editor' }, { value: 'Data Collector' }]);
    });

    it('filters out empty and null roles', () => {
        const result = normaliseInitialContributorRoles(['Editor', '', null, 'DataCollector']);
        expect(result).toEqual([{ value: 'Editor' }, { value: 'Data Collector' }]);
    });
});

describe('serializeAffiliations', () => {
    it('serializes person author affiliations', () => {
        const entry = {
            id: '1',
            type: 'person' as const,
            orcid: '',
            firstName: 'John',
            lastName: 'Doe',
            email: '',
            website: '',
            isContact: false,
            affiliations: [
                { value: 'University A', rorId: 'https://ror.org/123' },
                { value: 'University B', rorId: null },
            ],
            affiliationsInput: '',
            orcidVerified: false,
        };

        const result = serializeAffiliations(entry);
        expect(result).toEqual([
            { value: 'University A', rorId: 'https://ror.org/123' },
            { value: 'University B', rorId: null },
        ]);
    });

    it('deduplicates affiliations', () => {
        const entry = {
            id: '1',
            type: 'person' as const,
            orcid: '',
            firstName: 'John',
            lastName: 'Doe',
            email: '',
            website: '',
            isContact: false,
            affiliations: [
                { value: 'University A', rorId: 'https://ror.org/123' },
                { value: 'University A', rorId: 'https://ror.org/123' },
            ],
            affiliationsInput: '',
            orcidVerified: false,
        };

        const result = serializeAffiliations(entry);
        expect(result).toHaveLength(1);
    });

    it('filters out empty affiliations', () => {
        const entry = {
            id: '1',
            type: 'person' as const,
            orcid: '',
            firstName: 'John',
            lastName: 'Doe',
            email: '',
            website: '',
            isContact: false,
            affiliations: [
                { value: '', rorId: '' },
                { value: 'Valid University', rorId: null },
            ],
            affiliationsInput: '',
            orcidVerified: false,
        };

        const result = serializeAffiliations(entry);
        expect(result).toHaveLength(1);
        expect(result[0].value).toBe('Valid University');
    });

    it('uses rorId as value when value is empty but rorId exists', () => {
        const entry = {
            id: '1',
            type: 'person' as const,
            orcid: '',
            firstName: 'John',
            lastName: 'Doe',
            email: '',
            website: '',
            isContact: false,
            affiliations: [{ value: '', rorId: 'https://ror.org/123' }],
            affiliationsInput: '',
            orcidVerified: false,
        };

        const result = serializeAffiliations(entry);
        expect(result).toEqual([{ value: 'https://ror.org/123', rorId: 'https://ror.org/123' }]);
    });
});

describe('empty entry creators', () => {
    it('createEmptyPersonAuthor returns correct structure', () => {
        const entry = createEmptyPersonAuthor();

        expect(entry.type).toBe('person');
        expect(entry.orcid).toBe('');
        expect(entry.firstName).toBe('');
        expect(entry.lastName).toBe('');
        expect(entry.email).toBe('');
        expect(entry.website).toBe('');
        expect(entry.isContact).toBe(false);
        expect(entry.affiliations).toEqual([]);
        expect(entry.affiliationsInput).toBe('');
        expect(entry.orcidVerified).toBe(false);
        expect(entry.id).toBeDefined();
    });

    it('createEmptyInstitutionAuthor returns correct structure', () => {
        const entry = createEmptyInstitutionAuthor();

        expect(entry.type).toBe('institution');
        expect(entry.institutionName).toBe('');
        expect(entry.affiliations).toEqual([]);
        expect(entry.affiliationsInput).toBe('');
        expect(entry.id).toBeDefined();
    });

    it('createEmptyPersonContributor returns correct structure', () => {
        const entry = createEmptyPersonContributor();

        expect(entry.type).toBe('person');
        expect(entry.orcid).toBe('');
        expect(entry.firstName).toBe('');
        expect(entry.lastName).toBe('');
        expect(entry.roles).toEqual([]);
        expect(entry.rolesInput).toBe('');
        expect(entry.affiliations).toEqual([]);
        expect(entry.affiliationsInput).toBe('');
        expect(entry.orcidVerified).toBe(false);
        expect(entry.id).toBeDefined();
    });

    it('createEmptyInstitutionContributor returns correct structure', () => {
        const entry = createEmptyInstitutionContributor();

        expect(entry.type).toBe('institution');
        expect(entry.institutionName).toBe('');
        expect(entry.roles).toEqual([]);
        expect(entry.rolesInput).toBe('');
        expect(entry.affiliations).toEqual([]);
        expect(entry.affiliationsInput).toBe('');
        expect(entry.id).toBeDefined();
    });

    it('creates unique IDs for each entry', () => {
        const entry1 = createEmptyPersonAuthor();
        const entry2 = createEmptyPersonAuthor();

        expect(entry1.id).not.toBe(entry2.id);
    });
});

describe('mapInitialAuthorToEntry', () => {
    it('returns null for null or undefined input', () => {
        expect(mapInitialAuthorToEntry(null as unknown as Parameters<typeof mapInitialAuthorToEntry>[0])).toBeNull();
        expect(mapInitialAuthorToEntry(undefined as unknown as Parameters<typeof mapInitialAuthorToEntry>[0])).toBeNull();
    });

    it('maps person author correctly', () => {
        const initial = {
            type: 'person' as const,
            orcid: 'https://orcid.org/0000-0001-2345-6789',
            firstName: ' John ',
            lastName: ' Doe ',
            email: 'john@example.com',
            website: 'example.com',
            isContact: true,
            affiliations: [{ value: 'University A' }],
        };

        const result = mapInitialAuthorToEntry(initial);

        expect(result).not.toBeNull();
        expect(result?.type).toBe('person');
        expect(result?.orcid).toBe('0000-0001-2345-6789');
        expect(result?.firstName).toBe('John');
        expect(result?.lastName).toBe('Doe');
        expect(result?.email).toBe('john@example.com');
        expect(result?.website).toBe('https://example.com');
        expect(result?.isContact).toBe(true);
        expect(result?.affiliations).toHaveLength(1);
    });

    it('maps institution author correctly', () => {
        const initial = {
            type: 'institution' as const,
            institutionName: ' GFZ Potsdam ',
            affiliations: [],
        };

        const result = mapInitialAuthorToEntry(initial);

        expect(result).not.toBeNull();
        expect(result?.type).toBe('institution');
        expect((result as { institutionName: string }).institutionName).toBe('GFZ Potsdam');
    });

    it('handles isContact as string "true"', () => {
        const initial = {
            type: 'person' as const,
            firstName: 'Test',
            lastName: 'User',
            isContact: 'true',
        };

        const result = mapInitialAuthorToEntry(initial);
        expect(result?.isContact).toBe(true);
    });

    it('defaults to person type when type is missing', () => {
        const initial = {
            firstName: 'Test',
            lastName: 'User',
        };

        const result = mapInitialAuthorToEntry(initial as Parameters<typeof mapInitialAuthorToEntry>[0]);
        expect(result?.type).toBe('person');
    });
});

describe('mapInitialContributorToEntry', () => {
    it('returns null for null or undefined input', () => {
        expect(mapInitialContributorToEntry(null as unknown as Parameters<typeof mapInitialContributorToEntry>[0])).toBeNull();
        expect(mapInitialContributorToEntry(undefined as unknown as Parameters<typeof mapInitialContributorToEntry>[0])).toBeNull();
    });

    it('maps person contributor correctly', () => {
        const initial = {
            type: 'person' as const,
            orcid: '0000-0001-2345-6789',
            firstName: ' Jane ',
            lastName: ' Smith ',
            roles: ['Editor', 'DataCollector'],
            affiliations: [{ value: 'University B' }],
        };

        const result = mapInitialContributorToEntry(initial);

        expect(result).not.toBeNull();
        expect(result?.type).toBe('person');
        expect(result?.orcid).toBe('0000-0001-2345-6789');
        expect(result?.firstName).toBe('Jane');
        expect(result?.lastName).toBe('Smith');
        expect(result?.roles).toHaveLength(2);
        expect(result?.affiliations).toHaveLength(1);
    });

    it('maps institution contributor correctly', () => {
        const initial = {
            type: 'institution' as const,
            institutionName: ' Research Center ',
            roles: ['HostingInstitution'],
        };

        const result = mapInitialContributorToEntry(initial);

        expect(result).not.toBeNull();
        expect(result?.type).toBe('institution');
        expect((result as { institutionName: string }).institutionName).toBe('Research Center');
        expect(result?.roles).toHaveLength(1);
    });

    it('infers institution type from HostingInstitution role', () => {
        const initial = {
            type: 'person' as const,
            firstName: 'Should be',
            lastName: 'Institution',
            roles: ['HostingInstitution'],
        };

        const result = mapInitialContributorToEntry(initial);
        expect(result?.type).toBe('institution');
    });
});

describe('canAddTitle', () => {
    it('returns false when at max titles', () => {
        const titles = [{ title: 'Title 1' }, { title: 'Title 2' }, { title: 'Title 3' }];
        expect(canAddTitle(titles as Parameters<typeof canAddTitle>[0], 3)).toBe(false);
    });

    it('returns false when titles array is empty', () => {
        expect(canAddTitle([], 5)).toBe(false);
    });

    it('returns false when last title is empty', () => {
        const titles = [{ title: 'Title 1' }, { title: '' }];
        expect(canAddTitle(titles as Parameters<typeof canAddTitle>[0], 5)).toBe(false);
    });

    it('returns true when can add more titles', () => {
        const titles = [{ title: 'Title 1' }];
        expect(canAddTitle(titles as Parameters<typeof canAddTitle>[0], 5)).toBe(true);
    });
});

describe('canAddLicense', () => {
    it('returns false when at max licenses', () => {
        const licenses = [{ license: 'CC-BY-4.0' }, { license: 'CC0' }];
        expect(canAddLicense(licenses as Parameters<typeof canAddLicense>[0], 2)).toBe(false);
    });

    it('returns false when licenses array is empty', () => {
        expect(canAddLicense([], 5)).toBe(false);
    });

    it('returns false when last license is empty', () => {
        const licenses = [{ license: 'CC-BY-4.0' }, { license: '' }];
        expect(canAddLicense(licenses as Parameters<typeof canAddLicense>[0], 5)).toBe(false);
    });

    it('returns true when can add more licenses', () => {
        const licenses = [{ license: 'CC-BY-4.0' }];
        expect(canAddLicense(licenses as Parameters<typeof canAddLicense>[0], 5)).toBe(true);
    });
});

describe('canAddDate', () => {
    it('returns false when at max dates', () => {
        const dates = [{ startDate: '2024-01-01' }, { startDate: '2024-06-01' }];
        expect(canAddDate(dates as Parameters<typeof canAddDate>[0], 2)).toBe(false);
    });

    it('returns false when dates array is empty', () => {
        expect(canAddDate([], 5)).toBe(false);
    });

    it('returns false when last date has no start or end date', () => {
        const dates = [{ startDate: '2024-01-01' }, { startDate: '', endDate: '' }];
        expect(canAddDate(dates as Parameters<typeof canAddDate>[0], 5)).toBe(false);
    });

    it('returns true when last date has startDate', () => {
        const dates = [{ startDate: '2024-01-01', endDate: '' }];
        expect(canAddDate(dates as Parameters<typeof canAddDate>[0], 5)).toBe(true);
    });

    it('returns true when last date has endDate', () => {
        const dates = [{ startDate: '', endDate: '2024-12-31' }];
        expect(canAddDate(dates as Parameters<typeof canAddDate>[0], 5)).toBe(true);
    });
});
