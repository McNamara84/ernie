import { describe, expect, it } from 'vitest';

import type { ContributorInfo } from '@/pages/LandingPages/components/relation-browser/use-contributor-nodes';
import {
    buildContributorId,
    buildContributorLabel,
    fromLandingPageContributor,
    humanizeContributorType,
    mergeContributor,
    normalizeNameKey,
} from '@/pages/LandingPages/components/relation-browser/use-contributor-nodes';

describe('use-contributor-nodes utilities', () => {
    describe('normalizeNameKey', () => {
        it('normalizes to lowercase and trims', () => {
            expect(normalizeNameKey('Smith', 'John')).toBe('smith|john');
        });

        it('handles null values', () => {
            expect(normalizeNameKey(null, null)).toBe('|');
        });

        it('trims whitespace', () => {
            expect(normalizeNameKey('  Smith  ', '  John  ')).toBe('smith|john');
        });
    });

    describe('buildContributorLabel', () => {
        it('builds "FamilyName, GivenName" label', () => {
            const info: ContributorInfo = {
                givenName: 'John',
                familyName: 'Smith',
                institutionName: null,
                orcid: null,
                contributorTypes: ['Editor'],
                datasetNodeIds: new Set(['central']),
            };
            expect(buildContributorLabel(info)).toBe('Smith, John');
        });

        it('returns family name only when no given name', () => {
            const info: ContributorInfo = {
                givenName: null,
                familyName: 'Smith',
                institutionName: null,
                orcid: null,
                contributorTypes: ['Editor'],
                datasetNodeIds: new Set(['central']),
            };
            expect(buildContributorLabel(info)).toBe('Smith');
        });

        it('falls back to institution name', () => {
            const info: ContributorInfo = {
                givenName: null,
                familyName: null,
                institutionName: 'GFZ Potsdam',
                orcid: null,
                contributorTypes: ['HostingInstitution'],
                datasetNodeIds: new Set(['central']),
            };
            expect(buildContributorLabel(info)).toBe('GFZ Potsdam');
        });

        it('returns "Unknown" when no name parts available', () => {
            const info: ContributorInfo = {
                givenName: null,
                familyName: null,
                institutionName: null,
                orcid: null,
                contributorTypes: ['Other'],
                datasetNodeIds: new Set(['central']),
            };
            expect(buildContributorLabel(info)).toBe('Unknown');
        });
    });

    describe('buildContributorId', () => {
        it('uses ORCID when available', () => {
            const info: ContributorInfo = {
                givenName: 'John',
                familyName: 'Smith',
                institutionName: null,
                orcid: '0000-0001-2345-6789',
                contributorTypes: ['Editor'],
                datasetNodeIds: new Set(['central']),
            };
            expect(buildContributorId(info)).toBe('contributor-0000-0001-2345-6789');
        });

        it('uses normalized name when no ORCID', () => {
            const info: ContributorInfo = {
                givenName: 'John',
                familyName: 'Smith',
                institutionName: null,
                orcid: null,
                contributorTypes: ['DataCollector'],
                datasetNodeIds: new Set(['central']),
            };
            expect(buildContributorId(info)).toBe('contributor-smith|john');
        });

        it('uses institution name for institutions', () => {
            const info: ContributorInfo = {
                givenName: null,
                familyName: null,
                institutionName: 'GFZ Potsdam',
                orcid: null,
                contributorTypes: ['HostingInstitution'],
                datasetNodeIds: new Set(['central']),
            };
            expect(buildContributorId(info)).toBe('contributor-gfz potsdam');
        });
    });

    describe('humanizeContributorType', () => {
        it('splits DataCollector to "Data Collector"', () => {
            expect(humanizeContributorType('DataCollector')).toBe('Data Collector');
        });

        it('splits ContactPerson to "Contact Person"', () => {
            expect(humanizeContributorType('ContactPerson')).toBe('Contact Person');
        });

        it('splits HostingInstitution to "Hosting Institution"', () => {
            expect(humanizeContributorType('HostingInstitution')).toBe('Hosting Institution');
        });

        it('keeps single-word types like Editor unchanged', () => {
            expect(humanizeContributorType('Editor')).toBe('Editor');
        });

        it('splits WorkPackageLeader to "Work Package Leader"', () => {
            expect(humanizeContributorType('WorkPackageLeader')).toBe('Work Package Leader');
        });
    });

    describe('mergeContributor', () => {
        it('adds a new contributor to the map', () => {
            const map = new Map<string, ContributorInfo>();
            const orcidIndex = new Map<string, string>();

            mergeContributor(map, orcidIndex, {
                givenName: 'John',
                familyName: 'Smith',
                institutionName: null,
                orcid: null,
                contributorTypes: ['Editor'],
            }, 'central');

            expect(map.size).toBe(1);
            const entry = map.get('smith|john')!;
            expect(entry.familyName).toBe('Smith');
            expect(entry.contributorTypes).toEqual(['Editor']);
            expect(entry.datasetNodeIds.has('central')).toBe(true);
        });

        it('deduplicates by ORCID', () => {
            const map = new Map<string, ContributorInfo>();
            const orcidIndex = new Map<string, string>();

            mergeContributor(map, orcidIndex, {
                givenName: 'John',
                familyName: 'Smith',
                institutionName: null,
                orcid: '0000-0001-2345-6789',
                contributorTypes: ['Editor'],
            }, 'central');

            mergeContributor(map, orcidIndex, {
                givenName: 'J.',
                familyName: 'Smith',
                institutionName: null,
                orcid: '0000-0001-2345-6789',
                contributorTypes: ['DataCollector'],
            }, 'central');

            expect(map.size).toBe(1);
            const entry = [...map.values()][0];
            expect(entry.contributorTypes).toEqual(['Editor', 'DataCollector']);
        });

        it('deduplicates by name and merges contributor types', () => {
            const map = new Map<string, ContributorInfo>();
            const orcidIndex = new Map<string, string>();

            mergeContributor(map, orcidIndex, {
                givenName: 'John',
                familyName: 'Smith',
                institutionName: null,
                orcid: null,
                contributorTypes: ['Editor'],
            }, 'central');

            mergeContributor(map, orcidIndex, {
                givenName: 'John',
                familyName: 'Smith',
                institutionName: null,
                orcid: null,
                contributorTypes: ['DataCurator'],
            }, 'central');

            expect(map.size).toBe(1);
            const entry = [...map.values()][0];
            expect(entry.contributorTypes).toEqual(['Editor', 'DataCurator']);
        });

        it('does not duplicate contributor types', () => {
            const map = new Map<string, ContributorInfo>();
            const orcidIndex = new Map<string, string>();

            mergeContributor(map, orcidIndex, {
                givenName: 'John',
                familyName: 'Smith',
                institutionName: null,
                orcid: null,
                contributorTypes: ['Editor'],
            }, 'central');

            mergeContributor(map, orcidIndex, {
                givenName: 'John',
                familyName: 'Smith',
                institutionName: null,
                orcid: null,
                contributorTypes: ['Editor'],
            }, 'central');

            expect(map.size).toBe(1);
            const entry = [...map.values()][0];
            expect(entry.contributorTypes).toEqual(['Editor']);
        });

        it('upgrades ORCID when merging by name', () => {
            const map = new Map<string, ContributorInfo>();
            const orcidIndex = new Map<string, string>();

            mergeContributor(map, orcidIndex, {
                givenName: 'John',
                familyName: 'Smith',
                institutionName: null,
                orcid: null,
                contributorTypes: ['Editor'],
            }, 'central');

            mergeContributor(map, orcidIndex, {
                givenName: 'John',
                familyName: 'Smith',
                institutionName: null,
                orcid: '0000-0001-2345-6789',
                contributorTypes: ['DataCollector'],
            }, 'central');

            const entry = [...map.values()][0];
            expect(entry.orcid).toBe('0000-0001-2345-6789');
            expect(orcidIndex.get('0000-0001-2345-6789')).toBe('smith|john');
        });
    });

    describe('fromLandingPageContributor', () => {
        it('extracts person data with ORCID', () => {
            const result = fromLandingPageContributor({
                id: 1,
                position: 1,
                contributor_types: ['Editor', 'DataCollector'],
                affiliations: [],
                contributorable: {
                    type: 'Person',
                    id: 10,
                    given_name: 'John',
                    family_name: 'Smith',
                    name_identifier: 'https://orcid.org/0000-0001-2345-6789',
                    name_identifier_scheme: 'ORCID',
                    name: null,
                },
            });

            expect(result.givenName).toBe('John');
            expect(result.familyName).toBe('Smith');
            expect(result.orcid).toBe('0000-0001-2345-6789');
            expect(result.contributorTypes).toEqual(['Editor', 'DataCollector']);
        });

        it('extracts institution data', () => {
            const result = fromLandingPageContributor({
                id: 2,
                position: 2,
                contributor_types: ['HostingInstitution'],
                affiliations: [],
                contributorable: {
                    type: 'Institution',
                    id: 20,
                    given_name: null,
                    family_name: null,
                    name_identifier: null,
                    name_identifier_scheme: null,
                    name: 'GFZ Potsdam',
                },
            });

            expect(result.givenName).toBeNull();
            expect(result.familyName).toBeNull();
            expect(result.institutionName).toBe('GFZ Potsdam');
            expect(result.orcid).toBeNull();
            expect(result.contributorTypes).toEqual(['HostingInstitution']);
        });

        it('returns null ORCID for non-ORCID identifier schemes', () => {
            const result = fromLandingPageContributor({
                id: 3,
                position: 3,
                contributor_types: ['Researcher'],
                affiliations: [],
                contributorable: {
                    type: 'Person',
                    id: 30,
                    given_name: 'Jane',
                    family_name: 'Doe',
                    name_identifier: 'some-other-id',
                    name_identifier_scheme: 'ResearcherID',
                    name: null,
                },
            });

            expect(result.orcid).toBeNull();
        });
    });
});
