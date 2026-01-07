import { describe, expect, it } from 'vitest';

import { buildCitation } from '@/pages/LandingPages/lib/buildCitation';

describe('buildCitation', () => {
    it('builds citation with Person creator (new structure)', () => {
        const resource = {
            creators: [
                {
                    id: 1,
                    position: 1,
                    creatorable: {
                        type: 'Person',
                        given_name: 'Holger',
                        family_name: 'Ehrmann',
                    },
                },
            ],
            titles: [
                {
                    title: 'TESTTITLE',
                    title_type: 'MainTitle',
                },
            ],
            year: 2024,
            publisher: 'GFZ Data Services',
            doi: '10.5880/GFZ1243',
        };

        const citation = buildCitation(resource);

        expect(citation).toBe(
            'Ehrmann, Holger (2024): TESTTITLE. GFZ Data Services. https://doi.org/10.5880/GFZ1243'
        );
    });

    it('builds citation with Institution creator (new structure)', () => {
        const resource = {
            creators: [
                {
                    id: 1,
                    position: 1,
                    creatorable: {
                        type: 'Institution',
                        name: 'GFZ German Research Centre for Geosciences',
                    },
                },
            ],
            titles: [
                {
                    title: 'Test Dataset',
                    title_type: 'MainTitle',
                },
            ],
            year: 2023,
            publisher: 'GFZ Data Services',
            doi: '10.5880/GFZ.TEST.2023',
        };

        const citation = buildCitation(resource);

        expect(citation).toBe(
            'GFZ German Research Centre for Geosciences (2023): Test Dataset. GFZ Data Services. https://doi.org/10.5880/GFZ.TEST.2023'
        );
    });

    it('builds citation with multiple creators', () => {
        const resource = {
            creators: [
                {
                    id: 1,
                    position: 1,
                    creatorable: {
                        type: 'Person',
                        given_name: 'John',
                        family_name: 'Doe',
                    },
                },
                {
                    id: 2,
                    position: 2,
                    creatorable: {
                        type: 'Person',
                        given_name: 'Jane',
                        family_name: 'Smith',
                    },
                },
            ],
            titles: [
                {
                    title: 'Collaborative Research',
                    title_type: 'MainTitle',
                },
            ],
            year: 2025,
            publisher: 'GFZ Data Services',
            doi: '10.5880/GFZ.TEST.2025',
        };

        const citation = buildCitation(resource);

        expect(citation).toBe(
            'Doe, John; Smith, Jane (2025): Collaborative Research. GFZ Data Services. https://doi.org/10.5880/GFZ.TEST.2025'
        );
    });

    it('handles missing year with n.d.', () => {
        const resource = {
            creators: [
                {
                    id: 1,
                    position: 1,
                    creatorable: {
                        type: 'Person',
                        given_name: 'Test',
                        family_name: 'Author',
                    },
                },
            ],
            titles: [
                {
                    title: 'Undated Dataset',
                    title_type: 'MainTitle',
                },
            ],
            publisher: 'GFZ Data Services',
            doi: '10.5880/GFZ.TEST',
        };

        const citation = buildCitation(resource);

        expect(citation).toBe(
            'Author, Test (n.d.): Undated Dataset. GFZ Data Services. https://doi.org/10.5880/GFZ.TEST'
        );
    });

    it('handles missing creators with Unknown Creator', () => {
        const resource = {
            creators: [],
            titles: [
                {
                    title: 'Anonymous Dataset',
                    title_type: 'MainTitle',
                },
            ],
            year: 2024,
            publisher: 'GFZ Data Services',
            doi: '10.5880/GFZ.TEST',
        };

        const citation = buildCitation(resource);

        expect(citation).toBe(
            'Unknown Creator (2024): Anonymous Dataset. GFZ Data Services. https://doi.org/10.5880/GFZ.TEST'
        );
    });

    it('handles Person with only family name', () => {
        const resource = {
            creators: [
                {
                    id: 1,
                    position: 1,
                    creatorable: {
                        type: 'Person',
                        family_name: 'SingleName',
                    },
                },
            ],
            titles: [
                {
                    title: 'Test',
                    title_type: 'MainTitle',
                },
            ],
            year: 2024,
            publisher: 'GFZ Data Services',
            doi: '10.5880/GFZ.TEST',
        };

        const citation = buildCitation(resource);

        expect(citation).toBe(
            'SingleName (2024): Test. GFZ Data Services. https://doi.org/10.5880/GFZ.TEST'
        );
    });

    it('falls back to old structure for backward compatibility', () => {
        const resource = {
            creators: [
                {
                    id: 1,
                    position: 1,
                    given_name: 'Legacy',
                    family_name: 'User',
                },
            ],
            titles: [
                {
                    title: 'Legacy Dataset',
                    title_type: 'MainTitle',
                },
            ],
            year: 2020,
            publisher: 'GFZ Data Services',
            doi: '10.5880/GFZ.LEGACY',
        };

        const citation = buildCitation(resource);

        expect(citation).toBe(
            'User, Legacy (2020): Legacy Dataset. GFZ Data Services. https://doi.org/10.5880/GFZ.LEGACY'
        );
    });

    it('uses publication_year if year is not present', () => {
        const resource = {
            creators: [
                {
                    id: 1,
                    position: 1,
                    creatorable: {
                        type: 'Person',
                        given_name: 'Test',
                        family_name: 'Author',
                    },
                },
            ],
            titles: [
                {
                    title: 'Test Dataset',
                    title_type: 'MainTitle',
                },
            ],
            publication_year: 2022,
            publisher: 'GFZ Data Services',
            doi: '10.5880/GFZ.TEST',
        };

        const citation = buildCitation(resource);

        expect(citation).toBe(
            'Author, Test (2022): Test Dataset. GFZ Data Services. https://doi.org/10.5880/GFZ.TEST'
        );
    });
});
