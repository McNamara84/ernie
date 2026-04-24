import { describe, expect, it } from 'vitest';

import { formatCitation } from '@/lib/citation-formatter';
import type { RelatedItem } from '@/types/related-item';

function baseItem(overrides: Partial<RelatedItem> = {}): RelatedItem {
    return {
        related_item_type: 'JournalArticle',
        relation_type_id: 1,
        publication_year: 2024,
        volume: '10',
        issue: '3',
        first_page: '101',
        last_page: '115',
        publisher: 'Journal of Earth Science',
        identifier: '10.1234/abcd',
        identifier_type: 'DOI',
        position: 0,
        titles: [
            { title: 'A Study on Tectonics', title_type: 'MainTitle', position: 0 },
        ],
        creators: [
            {
                name: 'Doe, Jane',
                name_type: 'Personal',
                given_name: 'Jane',
                family_name: 'Doe',
                position: 0,
                affiliations: [],
            },
            {
                name: 'Smith, John',
                name_type: 'Personal',
                given_name: 'John R.',
                family_name: 'Smith',
                position: 1,
                affiliations: [],
            },
        ],
        contributors: [],
        ...overrides,
    };
}

describe('formatCitation (APA)', () => {
    it('formats a standard journal article', () => {
        const out = formatCitation(baseItem(), 'apa');
        expect(out).toContain('Doe, J., & Smith, J. R. (2024).');
        expect(out).toContain('A Study on Tectonics.');
        expect(out).toContain('Journal of Earth Science, 10(3), 101-115.');
        expect(out).toContain('https://doi.org/10.1234/abcd');
    });

    it('uses (n.d.) when publication_year is missing', () => {
        const out = formatCitation(baseItem({ publication_year: null }), 'apa');
        expect(out).toContain('(n.d.)');
    });

    it('uses et al. behaviour is APA-20+ rule', () => {
        const creators = Array.from({ length: 22 }, (_, i) => ({
            name: `Author${i}, A.`,
            name_type: 'Personal' as const,
            given_name: 'A.',
            family_name: `Author${i}`,
            position: i,
            affiliations: [],
        }));
        const out = formatCitation(baseItem({ creators }), 'apa');
        expect(out).toContain('…');
        expect(out).toContain('Author21');
    });

    it('falls back to [untitled] when MainTitle is missing', () => {
        const out = formatCitation(baseItem({ titles: [] }), 'apa');
        expect(out).toContain('[untitled]');
    });

    it('handles a single creator without trailing &', () => {
        const out = formatCitation(
            baseItem({
                creators: [
                    {
                        name: 'Doe, Jane',
                        name_type: 'Personal',
                        given_name: 'Jane',
                        family_name: 'Doe',
                        position: 0,
                        affiliations: [],
                    },
                ],
            }),
            'apa',
        );
        expect(out).toMatch(/^Doe, J\. \(2024\)\./);
        expect(out).not.toContain('&');
    });

    it('renders organizational creators unchanged', () => {
        const out = formatCitation(
            baseItem({
                creators: [
                    {
                        name: 'GFZ Helmholtz Centre',
                        name_type: 'Organizational',
                        position: 0,
                        affiliations: [],
                    },
                ],
            }),
            'apa',
        );
        expect(out).toContain('GFZ Helmholtz Centre');
    });

    it('treats non-container types without container', () => {
        const out = formatCitation(
            baseItem({ related_item_type: 'Book', publisher: 'Springer' }),
            'apa',
        );
        expect(out).toContain('Springer.');
    });
});

describe('formatCitation (IEEE)', () => {
    it('formats journal article with volume/issue/pages', () => {
        const out = formatCitation(baseItem(), 'ieee');
        expect(out).toContain('J. Doe, and J. R. Smith');
        expect(out).toContain('"A Study on Tectonics,"');
        expect(out).toContain('vol. 10');
        expect(out).toContain('no. 3');
        expect(out).toContain('pp. 101-115');
        expect(out).toContain('2024');
        expect(out).toContain('doi: https://doi.org/10.1234/abcd');
    });

    it('uses et al. rule for >6 creators', () => {
        const creators = Array.from({ length: 8 }, (_, i) => ({
            name: `Author${i}, A.`,
            name_type: 'Personal' as const,
            given_name: 'A.',
            family_name: `Author${i}`,
            position: i,
            affiliations: [],
        }));
        const out = formatCitation(baseItem({ creators }), 'ieee');
        expect(out).toContain('et al.');
    });
});
