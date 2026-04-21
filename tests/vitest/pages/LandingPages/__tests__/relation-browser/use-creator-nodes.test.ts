import { describe, expect, it } from 'vitest';

import type { CreatorInfo } from '@/pages/LandingPages/components/relation-browser/use-creator-nodes';
import {
    buildCreatorId,
    buildCreatorLabel,
    fromApiAuthor,
    fromLandingPageCreator,
    mergeCreator,
    normalizeNameKey,
} from '@/pages/LandingPages/components/relation-browser/use-creator-nodes';

describe('use-creator-nodes utilities', () => {
    describe('normalizeNameKey', () => {
        it('normalizes names to lowercase and trimmed pipe-separated key', () => {
            expect(normalizeNameKey('Doe', 'John')).toBe('doe|john');
        });

        it('handles null given name', () => {
            expect(normalizeNameKey('Doe', null)).toBe('doe|');
        });

        it('handles null family name', () => {
            expect(normalizeNameKey(null, 'John')).toBe('|john');
        });

        it('handles both null', () => {
            expect(normalizeNameKey(null, null)).toBe('|');
        });

        it('trims whitespace', () => {
            expect(normalizeNameKey(' Doe ', ' John ')).toBe('doe|john');
        });
    });

    describe('buildCreatorLabel', () => {
        it('returns "FamilyName, GivenName" for person', () => {
            const info: CreatorInfo = {
                givenName: 'Gundula',
                familyName: 'Geo',
                institutionName: null,
                orcid: null,
                datasetNodeIds: new Set(['central']),
            };
            expect(buildCreatorLabel(info)).toBe('Geo, Gundula');
        });

        it('returns family name only when given name is null', () => {
            const info: CreatorInfo = {
                givenName: null,
                familyName: 'Doe',
                institutionName: null,
                orcid: null,
                datasetNodeIds: new Set(['central']),
            };
            expect(buildCreatorLabel(info)).toBe('Doe');
        });

        it('returns institution name when no person name', () => {
            const info: CreatorInfo = {
                givenName: null,
                familyName: null,
                institutionName: 'GFZ Helmholtz Centre',
                orcid: null,
                datasetNodeIds: new Set(['central']),
            };
            expect(buildCreatorLabel(info)).toBe('GFZ Helmholtz Centre');
        });

        it('returns "Unknown" when no name data', () => {
            const info: CreatorInfo = {
                givenName: null,
                familyName: null,
                institutionName: null,
                orcid: null,
                datasetNodeIds: new Set(['central']),
            };
            expect(buildCreatorLabel(info)).toBe('Unknown');
        });
    });

    describe('buildCreatorId', () => {
        it('uses ORCID when available', () => {
            const info: CreatorInfo = {
                givenName: 'John',
                familyName: 'Doe',
                institutionName: null,
                orcid: '0000-0002-1234-5678',
                datasetNodeIds: new Set(['central']),
            };
            expect(buildCreatorId(info, { value: 0 })).toBe('creator-0000-0002-1234-5678');
        });

        it('uses normalized name when no ORCID', () => {
            const info: CreatorInfo = {
                givenName: 'John',
                familyName: 'Doe',
                institutionName: null,
                orcid: null,
                datasetNodeIds: new Set(['central']),
            };
            expect(buildCreatorId(info, { value: 0 })).toBe('creator-doe|john');
        });

        it('uses institution name when no person name or ORCID', () => {
            const info: CreatorInfo = {
                givenName: null,
                familyName: null,
                institutionName: 'GFZ Centre',
                orcid: null,
                datasetNodeIds: new Set(['central']),
            };
            expect(buildCreatorId(info, { value: 0 })).toBe('creator-gfz centre');
        });
    });

    describe('mergeCreator', () => {
        it('adds new creator to empty map', () => {
            const map = new Map<string, CreatorInfo>();
            const orcidIndex = new Map<string, string>();

            mergeCreator(map, orcidIndex, {
                givenName: 'John',
                familyName: 'Doe',
                institutionName: null,
                orcid: null,
            }, 'central', { value: 0 });

            expect(map.size).toBe(1);
            const entry = map.get('doe|john')!;
            expect(entry.familyName).toBe('Doe');
            expect(entry.datasetNodeIds.has('central')).toBe(true);
        });

        it('deduplicates by ORCID', () => {
            const map = new Map<string, CreatorInfo>();
            const orcidIndex = new Map<string, string>();

            mergeCreator(map, orcidIndex, {
                givenName: 'John',
                familyName: 'Doe',
                institutionName: null,
                orcid: '0000-0002-1234-5678',
            }, 'central', { value: 0 });

            mergeCreator(map, orcidIndex, {
                givenName: 'J.',
                familyName: 'Doe',
                institutionName: null,
                orcid: '0000-0002-1234-5678',
            }, 'related-1', { value: 0 });

            expect(map.size).toBe(1);
            const entry = [...map.values()][0];
            expect(entry.datasetNodeIds.size).toBe(2);
            expect(entry.datasetNodeIds.has('central')).toBe(true);
            expect(entry.datasetNodeIds.has('related-1')).toBe(true);
        });

        it('deduplicates by normalized name when no ORCID', () => {
            const map = new Map<string, CreatorInfo>();
            const orcidIndex = new Map<string, string>();

            mergeCreator(map, orcidIndex, {
                givenName: 'John',
                familyName: 'Doe',
                institutionName: null,
                orcid: null,
            }, 'central', { value: 0 });

            mergeCreator(map, orcidIndex, {
                givenName: 'John',
                familyName: 'Doe',
                institutionName: null,
                orcid: null,
            }, 'related-1', { value: 0 });

            expect(map.size).toBe(1);
            const entry = [...map.values()][0];
            expect(entry.datasetNodeIds.size).toBe(2);
        });

        it('upgrades ORCID for existing name-matched entry', () => {
            const map = new Map<string, CreatorInfo>();
            const orcidIndex = new Map<string, string>();

            mergeCreator(map, orcidIndex, {
                givenName: 'John',
                familyName: 'Doe',
                institutionName: null,
                orcid: null,
            }, 'central', { value: 0 });

            mergeCreator(map, orcidIndex, {
                givenName: 'John',
                familyName: 'Doe',
                institutionName: null,
                orcid: '0000-0002-1234-5678',
            }, 'related-1', { value: 0 });

            expect(map.size).toBe(1);
            const entry = [...map.values()][0];
            expect(entry.orcid).toBe('0000-0002-1234-5678');
        });

        it('treats different names as separate creators', () => {
            const map = new Map<string, CreatorInfo>();
            const orcidIndex = new Map<string, string>();

            mergeCreator(map, orcidIndex, {
                givenName: 'John',
                familyName: 'Doe',
                institutionName: null,
                orcid: null,
            }, 'central', { value: 0 });

            mergeCreator(map, orcidIndex, {
                givenName: 'Jane',
                familyName: 'Smith',
                institutionName: null,
                orcid: null,
            }, 'central', { value: 0 });

            expect(map.size).toBe(2);
        });
    });

    describe('fromLandingPageCreator', () => {
        it('extracts ORCID from name_identifier', () => {
            const result = fromLandingPageCreator({
                id: 1,
                position: 1,
                affiliations: [],
                creatorable: {
                    type: 'Person',
                    id: 1,
                    given_name: 'John',
                    family_name: 'Doe',
                    name: null,
                    name_identifier: 'https://orcid.org/0000-0002-1234-5678',
                    name_identifier_scheme: 'ORCID',
                },
            });

            expect(result.givenName).toBe('John');
            expect(result.familyName).toBe('Doe');
            expect(result.orcid).toBe('0000-0002-1234-5678');
        });

        it('returns null ORCID when scheme is not ORCID', () => {
            const result = fromLandingPageCreator({
                id: 1,
                position: 1,
                affiliations: [],
                creatorable: {
                    type: 'Person',
                    id: 1,
                    given_name: 'John',
                    family_name: 'Doe',
                    name: null,
                    name_identifier: 'some-id',
                    name_identifier_scheme: 'ISNI',
                },
            });

            expect(result.orcid).toBeNull();
        });

        it('handles institution creators', () => {
            const result = fromLandingPageCreator({
                id: 1,
                position: 1,
                affiliations: [],
                creatorable: {
                    type: 'Institution',
                    id: 1,
                    given_name: null,
                    family_name: null,
                    name: 'GFZ Helmholtz Centre',
                    name_identifier: null,
                    name_identifier_scheme: null,
                },
            });

            expect(result.institutionName).toBe('GFZ Helmholtz Centre');
            expect(result.givenName).toBeNull();
            expect(result.familyName).toBeNull();
            expect(result.orcid).toBeNull();
        });
    });

    describe('fromApiAuthor', () => {
        it('passes through all fields', () => {
            const result = fromApiAuthor({
                given_name: 'John',
                family_name: 'Doe',
                name: null,
                orcid: '0000-0002-1234-5678',
            });

            expect(result.givenName).toBe('John');
            expect(result.familyName).toBe('Doe');
            expect(result.institutionName).toBeNull();
            expect(result.orcid).toBe('0000-0002-1234-5678');
        });

        it('handles institution (literal) authors', () => {
            const result = fromApiAuthor({
                given_name: null,
                family_name: null,
                name: 'GFZ',
                orcid: null,
            });

            expect(result.institutionName).toBe('GFZ');
            expect(result.givenName).toBeNull();
            expect(result.familyName).toBeNull();
        });
    });
});
