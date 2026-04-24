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

    it('formats a non-container book without container segment', () => {
        const out = formatCitation(
            baseItem({
                related_item_type: 'Book',
                publisher: 'Springer',
                publication_year: 2019,
                volume: null,
                issue: null,
                first_page: null,
                last_page: null,
                identifier: null,
                identifier_type: null,
            }),
            'ieee',
        );
        expect(out).toContain('Springer,');
        expect(out).toContain('2019.');
        expect(out).not.toContain('vol.');
        expect(out).not.toContain('pp.');
    });

    it('omits creator segment when no creators present', () => {
        const out = formatCitation(
            baseItem({ creators: [], related_item_type: 'Book', publisher: 'X', identifier: null, identifier_type: null }),
            'ieee',
        );
        expect(out.startsWith('"')).toBe(true);
    });
});

describe('formatCitation (APA edge cases)', () => {
    it('wraps volume-only in parentheses when no container', () => {
        const out = formatCitation(
            baseItem({
                related_item_type: 'Report',
                volume: '5',
                issue: null,
                first_page: null,
                last_page: null,
                publisher: null,
                identifier: null,
                identifier_type: null,
                titles: [{ title: 'Report Title', title_type: 'MainTitle', position: 0 }],
            }),
            'apa',
        );
        expect(out).toContain('Report Title (5).');
    });

    it('uses only firstPage when lastPage missing', () => {
        const out = formatCitation(
            baseItem({ first_page: '42', last_page: null }),
            'apa',
        );
        expect(out).toContain(', 42.');
        expect(out).not.toContain('42-');
    });

    it('keeps DOI identifier already starting with https://', () => {
        const out = formatCitation(
            baseItem({ identifier: 'https://doi.org/10.9999/custom', identifier_type: 'DOI' }),
            'apa',
        );
        expect(out).toContain('https://doi.org/10.9999/custom');
    });

    it('strips leading slashes from DOI identifier', () => {
        const out = formatCitation(
            baseItem({ identifier: '//10.1234/x', identifier_type: 'DOI' }),
            'apa',
        );
        expect(out).toContain('https://doi.org/10.1234/x');
        expect(out).not.toMatch(/\/\/+10\.1234/);
    });

    it('emits (YEAR). alone when no creators present', () => {
        const out = formatCitation(
            baseItem({ creators: [], publication_year: 2024 }),
            'apa',
        );
        expect(out.startsWith('(2024).')).toBe(true);
    });

    it('falls back to name when family/given absent', () => {
        const out = formatCitation(
            baseItem({
                creators: [
                    {
                        name: 'Madonna',
                        name_type: 'Personal',
                        position: 0,
                        affiliations: [],
                    },
                ],
            }),
            'apa',
        );
        expect(out).toContain('Madonna (2024).');
    });

    it('splits hyphenated given names into separate initials', () => {
        const out = formatCitation(
            baseItem({
                creators: [
                    {
                        name: 'Picard, Jean-Luc',
                        name_type: 'Personal',
                        given_name: 'Jean-Luc',
                        family_name: 'Picard',
                        position: 0,
                        affiliations: [],
                    },
                ],
            }),
            'apa',
        );
        expect(out).toContain('Picard, J. L.');
    });

    it('uppercases multibyte initials', () => {
        const out = formatCitation(
            baseItem({
                creators: [
                    {
                        name: 'Ågren, Örjan',
                        name_type: 'Personal',
                        given_name: 'örjan',
                        family_name: 'Ågren',
                        position: 0,
                        affiliations: [],
                    },
                ],
            }),
            'apa',
        );
        expect(out).toContain('Ågren, Ö.');
    });

    it('omits publisher segment when empty string', () => {
        const out = formatCitation(
            baseItem({
                related_item_type: 'Book',
                publisher: '',
                identifier: null,
                identifier_type: null,
            }),
            'apa',
        );
        expect(out).not.toMatch(/\.\s+\./);
        expect(out).not.toContain('  ');
    });
});
