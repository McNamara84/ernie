import { describe, expect, it } from 'vitest';

import { mergeLandingPageCredits } from '@/pages/LandingPages/lib/mergeLandingPageCredits';
import type { LandingPageAffiliation, LandingPageContributor, LandingPageCreator, LandingPageCreatorable } from '@/types/landing-page';

const makeEntity = (id: number | null, overrides: Partial<LandingPageCreatorable> = {}): LandingPageCreatorable => ({
    id,
    type: 'Person',
    given_name: 'Alex',
    family_name: 'Example',
    name: null,
    name_identifier: null,
    name_identifier_scheme: null,
    ...overrides,
});

const makeAffiliation = (id: number, name: string, identifier: string | null = null, scheme: string | null = null): LandingPageAffiliation => ({
    id,
    name,
    affiliation_identifier: identifier,
    affiliation_identifier_scheme: scheme,
});

const makeCreator = (id: number, entityId: number | null, overrides: Partial<LandingPageCreator> = {}): LandingPageCreator => ({
    id,
    position: id,
    creatorable: makeEntity(entityId),
    affiliations: [],
    ...overrides,
});

const makeContributor = (
    id: number,
    entityId: number | null,
    roles: string[] = [],
    overrides: Partial<LandingPageContributor> = {},
): LandingPageContributor => ({
    id,
    position: id,
    contributorable: makeEntity(entityId),
    contributor_types: roles,
    affiliations: [],
    ...overrides,
});

describe('mergeLandingPageCredits', () => {
    it('keeps distinct contributors in first-occurrence order', () => {
        const contributors = [makeContributor(8, 80, ['Producer']), makeContributor(3, 30, ['Researcher'])];

        const result = mergeLandingPageCredits([], contributors);

        expect(result.contributors.map((contributor) => contributor.id)).toEqual([8, 3]);
        expect(result.contributors.map((contributor) => contributor.contributor_types)).toEqual([['Producer'], ['Researcher']]);
    });

    it('merges repeated contributors and combines their roles in first-occurrence order', () => {
        const contributors = [
            makeContributor(1, 10, ['Producer']),
            makeContributor(2, 10, ['Contact Person']),
            makeContributor(3, 10, ['Project Leader']),
        ];

        const result = mergeLandingPageCredits([], contributors);

        expect(result.contributors).toHaveLength(1);
        expect(result.contributors[0]).toMatchObject({
            id: 1,
            contributor_types: ['Producer', 'Contact Person', 'Project Leader'],
        });
    });

    it('trims roles and removes empty or case-insensitive duplicates', () => {
        const contributors = [
            makeContributor(1, 10, [' Producer ', '', 'Contact   Person']),
            makeContributor(2, 10, ['producer', ' contact person ', 'Researcher']),
        ];

        const result = mergeLandingPageCredits([], contributors);

        expect(result.contributors[0].contributor_types).toEqual(['Producer', 'Contact Person', 'Researcher']);
    });

    it('moves matching contributor roles and affiliations to the creator', () => {
        const creators = [
            makeCreator(1, 10, {
                affiliations: [makeAffiliation(1, 'GFZ Potsdam')],
            }),
        ];
        const contributors = [
            makeContributor(2, 10, ['Producer'], {
                affiliations: [makeAffiliation(2, 'ETH Zürich')],
            }),
            makeContributor(3, 10, ['Contact Person']),
        ];

        const result = mergeLandingPageCredits(creators, contributors);

        expect(result.contributors).toEqual([]);
        expect(result.creators).toHaveLength(1);
        expect(result.creators[0].contributor_types).toEqual(['Producer', 'Contact Person']);
        expect(result.creators[0].affiliations.map((affiliation) => affiliation.name)).toEqual(['GFZ Potsdam', 'ETH Zürich']);
    });

    it('uses a display identity to merge different legacy person entities', () => {
        const creators = [
            makeCreator(1, 10, {
                display_identity_key: 'entity:person:10',
                creatorable: makeEntity(10, {
                    given_name: 'Juan Camilo',
                    family_name: 'Gomez-Zapata',
                }),
                affiliations: [makeAffiliation(1, 'GFZ Potsdam')],
            }),
        ];
        const contributors = [
            makeContributor(2, 20, ['Contact Person', 'Producer'], {
                display_identity_key: 'entity:person:10',
                contributorable: makeEntity(20, {
                    given_name: 'Gomez Zapata Juan',
                    family_name: 'Camilo',
                }),
                affiliations: [makeAffiliation(2, 'University of Potsdam')],
            }),
        ];

        const result = mergeLandingPageCredits(creators, contributors);

        expect(result.contributors).toEqual([]);
        expect(result.creators).toHaveLength(1);
        expect(result.creators[0].creatorable).toMatchObject({
            id: 10,
            given_name: 'Juan Camilo',
            family_name: 'Gomez-Zapata',
        });
        expect(result.creators[0].contributor_types).toEqual(['Contact Person', 'Producer']);
        expect(result.creators[0].affiliations.map((affiliation) => affiliation.name)).toEqual(['GFZ Potsdam', 'University of Potsdam']);
    });

    it('combines roles from legacy contributor entities with the same display identity', () => {
        const contributors = [
            makeContributor(1, 10, ['Producer'], { display_identity_key: 'orcid:0000-0002-1825-0097' }),
            makeContributor(2, 20, ['Contact Person'], { display_identity_key: 'orcid:0000-0002-1825-0097' }),
            makeContributor(3, 30, ['Project Leader'], { display_identity_key: 'orcid:0000-0002-1825-0097' }),
        ];

        const result = mergeLandingPageCredits([], contributors);

        expect(result.contributors).toHaveLength(1);
        expect(result.contributors[0]).toMatchObject({
            id: 1,
            contributor_types: ['Producer', 'Contact Person', 'Project Leader'],
        });
    });

    it('falls back to entity ids when a display identity is blank', () => {
        const creators = [makeCreator(1, 10, { display_identity_key: '   ' })];
        const contributors = [makeContributor(2, 20, ['Contact Person'], { display_identity_key: '' })];

        const result = mergeLandingPageCredits(creators, contributors);

        expect(result.creators[0].contributor_types).toEqual([]);
        expect(result.contributors).toHaveLength(1);
    });

    it('does not merge a person and institution that have the same numeric entity id', () => {
        const creators = [makeCreator(1, 10)];
        const contributors = [
            makeContributor(2, 10, ['Hosting Institution'], {
                contributorable: makeEntity(10, {
                    type: 'Institution',
                    given_name: null,
                    family_name: null,
                    name: 'Example Institute',
                }),
            }),
        ];

        const result = mergeLandingPageCredits(creators, contributors);

        expect(result.creators).toHaveLength(1);
        expect(result.creators[0].contributor_types).toEqual([]);
        expect(result.contributors).toHaveLength(1);
    });

    it('does not merge unresolved entities by matching names', () => {
        const creators = [makeCreator(1, null)];
        const contributors = [makeContributor(2, null, ['Producer']), makeContributor(3, null, ['Contact Person'])];

        const result = mergeLandingPageCredits(creators, contributors);

        expect(result.creators).toHaveLength(1);
        expect(result.creators[0].contributor_types).toEqual([]);
        expect(result.contributors).toHaveLength(2);
    });

    it('deduplicates affiliations by normalized identifier and preserves the leading display name', () => {
        const contributors = [
            makeContributor(1, 10, ['Producer'], {
                affiliations: [makeAffiliation(1, 'Leading Institute', 'https://ror.org/04Z8JG394/', 'ROR')],
            }),
            makeContributor(2, 10, ['Researcher'], {
                affiliations: [makeAffiliation(2, 'Renamed Institute', 'www.ror.org/04z8jg394', 'ror')],
            }),
        ];

        const result = mergeLandingPageCredits([], contributors);

        expect(result.contributors[0].affiliations).toEqual([makeAffiliation(1, 'Leading Institute', 'https://ror.org/04Z8JG394/', 'ROR')]);
    });

    it('deduplicates affiliations by normalized name and adopts a later identifier', () => {
        const contributors = [
            makeContributor(1, 10, ['Producer'], {
                affiliations: [makeAffiliation(1, '  GFZ   Potsdam  ')],
            }),
            makeContributor(2, 10, ['Contact Person'], {
                affiliations: [makeAffiliation(2, 'gfz potsdam', '04z8jg394', 'ROR')],
            }),
        ];

        const result = mergeLandingPageCredits([], contributors);

        expect(result.contributors[0].affiliations).toEqual([makeAffiliation(1, '  GFZ   Potsdam  ', '04z8jg394', 'ROR')]);
    });

    it('fills a missing affiliation name when a later row has the same identifier', () => {
        const contributors = [
            makeContributor(1, 10, ['Producer'], {
                affiliations: [makeAffiliation(1, '', '04z8jg394', 'ROR')],
            }),
            makeContributor(2, 10, ['Contact Person'], {
                affiliations: [makeAffiliation(2, 'GFZ Potsdam', 'https://ror.org/04Z8JG394/', 'ror')],
            }),
        ];

        const result = mergeLandingPageCredits([], contributors);

        expect(result.contributors[0].affiliations).toEqual([makeAffiliation(1, 'GFZ Potsdam', '04z8jg394', 'ROR')]);
    });

    it('indexes a name adopted during an identifier merge for later name-only rows', () => {
        const contributor = makeContributor(1, 10, ['Producer'], {
            affiliations: [
                makeAffiliation(1, '', '04z8jg394', 'ROR'),
                makeAffiliation(2, 'GFZ Potsdam', 'https://ror.org/04Z8JG394/', 'ror'),
                makeAffiliation(3, ' gfz   potsdam '),
            ],
        });

        const result = mergeLandingPageCredits([], [contributor]);

        expect(result.contributors[0].affiliations).toEqual([makeAffiliation(1, 'GFZ Potsdam', '04z8jg394', 'ROR')]);
    });

    it('deduplicates names without identifiers and preserves an unidentifiable affiliation row', () => {
        const contributor = makeContributor(1, 10, ['Producer'], {
            affiliations: [makeAffiliation(1, 'Example Institute'), makeAffiliation(2, ' example   institute '), makeAffiliation(3, ' ')],
        });

        const result = mergeLandingPageCredits([], [contributor]);

        expect(result.contributors[0].affiliations).toEqual([makeAffiliation(1, 'Example Institute'), makeAffiliation(3, ' ')]);
    });

    it('keeps same-named affiliations separate when they have different identifiers', () => {
        const contributor = makeContributor(1, 10, ['Producer'], {
            affiliations: [
                makeAffiliation(1, 'Example Institute', 'https://ror.org/01abcde23', 'ROR'),
                makeAffiliation(2, 'Example Institute', 'https://ror.org/02abcde34', 'ROR'),
            ],
        });

        const result = mergeLandingPageCredits([], [contributor]);

        expect(result.contributors[0].affiliations).toHaveLength(2);
    });

    it('merges repeated creators and preserves the first creator row', () => {
        const creators = [
            makeCreator(7, 10, { affiliations: [makeAffiliation(1, 'First Institute')] }),
            makeCreator(8, 10, { affiliations: [makeAffiliation(2, 'Second Institute')] }),
        ];

        const result = mergeLandingPageCredits(creators, []);

        expect(result.creators).toHaveLength(1);
        expect(result.creators[0].id).toBe(7);
        expect(result.creators[0].affiliations.map((affiliation) => affiliation.name)).toEqual(['First Institute', 'Second Institute']);
    });

    it('does not mutate creators, contributors, entities, roles, or affiliations', () => {
        const creators = [makeCreator(1, 10, { affiliations: [makeAffiliation(1, 'Creator Institute')] })];
        const contributors = [
            makeContributor(2, 10, [' Producer '], {
                affiliations: [makeAffiliation(2, 'Contributor Institute')],
            }),
        ];
        const originalCreators = structuredClone(creators);
        const originalContributors = structuredClone(contributors);

        const result = mergeLandingPageCredits(creators, contributors);

        expect(creators).toEqual(originalCreators);
        expect(contributors).toEqual(originalContributors);
        expect(result.creators[0]).not.toBe(creators[0]);
        expect(result.creators[0].creatorable).not.toBe(creators[0].creatorable);
        expect(result.creators[0].affiliations).not.toBe(creators[0].affiliations);
    });
});
