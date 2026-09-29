import { describe, expect, it } from 'vitest';

import { mergeImportedEntries } from '@/lib/imported-metadata';
import { toImportedFormParts } from '@/lib/imported-metadata-form';
import type { Language, License } from '@/types';

const languages: Language[] = [{ id: 1, code: 'en', name: 'English' }];
const licenses: License[] = [{ id: 1, identifier: 'MIT', name: 'MIT License' }];

describe('DataCite metadata merge', () => {
    it('replaces an empty main-title row while keeping a typed main title and its local ID', () => {
        const imported = toImportedFormParts(
            {
                titles: [
                    { title: 'From XML', titleType: 'main-title' },
                    { title: 'Subtitle', titleType: 'subtitle' },
                ],
            },
            languages,
            licenses,
        );
        const current = [{ id: 'typed-id', title: 'Typed first', titleType: 'main-title' }];
        const identity = (entry: (typeof current)[number]) => (entry.titleType === 'main-title' ? 'main-title' : `${entry.titleType}:${entry.title}`);

        const once = mergeImportedEntries(current, imported.titles, identity, (entry) => entry.title.trim() === '');
        const twice = mergeImportedEntries(once, imported.titles, identity, (entry) => entry.title.trim() === '');

        expect(twice).toHaveLength(2);
        expect(twice[0]).toMatchObject({ id: 'typed-id', title: 'Typed first', titleType: 'main-title' });
        expect(twice[1]).toMatchObject({ title: 'Subtitle', titleType: 'subtitle' });
        expect(
            mergeImportedEntries(
                [{ id: 'empty', title: '', titleType: 'main-title' }],
                imported.titles,
                identity,
                (entry) => entry.title.trim() === '',
            )[0].title,
        ).toBe('From XML');
    });

    it('adds missing details to an existing person without changing entered values or duplicating the person', () => {
        const existing = [{ id: 'local', orcid: '0000-0001', lastName: 'Typed', email: '', affiliations: [{ value: 'GFZ' }] }];
        const incoming = [
            {
                id: 'imported',
                orcid: '0000-0001',
                lastName: 'From file',
                email: 'person@example.test',
                affiliations: [{ value: 'GFZ' }, { value: 'Partner' }],
            },
        ];

        const merged = mergeImportedEntries(existing, incoming, (entry) => entry.orcid);

        expect(merged).toEqual([
            {
                id: 'local',
                orcid: '0000-0001',
                lastName: 'Typed',
                email: 'person@example.test',
                affiliations: [{ value: 'GFZ' }, { value: 'Partner' }],
            },
        ]);
        expect(mergeImportedEntries(merged, incoming, (entry) => entry.orcid)).toEqual(merged);
    });

    it('maps all importable collection types without introducing duplicate rights from one catalog license', () => {
        const imported = toImportedFormParts(
            {
                doi: '10.5880/example',
                year: '2025',
                language: 'en',
                licenses: ['MIT'],
                rawRights: [
                    { rights: 'MIT License', rightsIdentifier: 'MIT' },
                    { rights: 'Custom right', rightsUri: 'https://example.test/right' },
                ],
                authors: [{ type: 'person', lastName: 'Doe', firstName: 'Jane' }],
                contributors: [{ type: 'institution', institutionName: 'GFZ', roles: ['Distributor'] }],
                descriptions: [{ type: 'Abstract', description: 'Imported abstract' }],
                dates: [{ dateType: 'Collected', startDate: '2020-01-01', endDate: '' }],
                controlledKeywords: [{ id: 'earth', text: 'Earth', path: 'Earth', scheme: 'Science Keywords', schemeURI: '', language: 'en' }],
                freeKeywords: ['volcano'],
                relatedWorks: [{ identifier: '10.1000/related', identifier_type: 'DOI', relation_type: 'References' }],
                relatedItems: [{ relatedItemIdentifier: '10.1000/citation' }],
                fundingReferences: [
                    { funderName: 'Funder', funderIdentifier: '', funderIdentifierType: null, awardNumber: 'A1', awardUri: '', awardTitle: '' },
                ],
                mslLaboratories: [{ identifier: 'lab-1', name: 'Lab', affiliation_name: 'GFZ', affiliation_ror: null }],
                instruments: [{ pid: '20.500/test', pidType: 'Handle', name: 'Instrument' }],
            },
            languages,
            licenses,
        );

        expect(imported.scalar).toMatchObject({ doi: '10.5880/example', year: '2025', language: 'en' });
        expect(imported.licenses).toHaveLength(2);
        expect(imported.authors).toHaveLength(1);
        expect(imported.contributors).toHaveLength(1);
        expect(imported.descriptions[0].value).toBe('Imported abstract');
        expect(imported.dates[0].startDate).toBe('2020-01-01');
        expect(imported.keywords).toHaveLength(1);
        expect(imported.freeKeywords[0].value).toBe('volcano');
        expect(imported.relatedWorks).toHaveLength(1);
        expect(imported.relatedItems).toHaveLength(1);
        expect(imported.fundingReferences[0]).toMatchObject({ id: expect.any(String), awardNumber: 'A1' });
        expect(imported.mslLaboratories).toHaveLength(1);
        expect(imported.instruments).toHaveLength(1);
    });
});
