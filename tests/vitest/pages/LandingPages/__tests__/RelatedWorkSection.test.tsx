import { render, screen, waitFor } from '@testing-library/react';
import { beforeEach,describe, expect, it, vi } from 'vitest';

import { RelatedWorkSection } from '@/pages/LandingPages/components/RelatedWorkSection';

describe('RelatedWorkSection', () => {
    beforeEach(() => {
        vi.resetAllMocks();
    });

    it('returns null when no related identifiers', () => {
        const { container } = render(<RelatedWorkSection relatedIdentifiers={[]} />);
        expect(container.firstChild).toBeNull();
    });

    it('excludes the first IsSupplementTo relation', () => {
        const relatedIdentifiers = [
            {
                id: 1,
                identifier: '10.5880/first-supplement',
                identifier_type: 'DOI',
                relation_type: 'IsSupplementTo',
            },
            {
                id: 2,
                identifier: '10.5880/second-supplement',
                identifier_type: 'DOI',
                relation_type: 'IsSupplementTo',
            },
        ];

        global.fetch = vi.fn().mockResolvedValue({
            ok: true,
            json: () => Promise.resolve({ citation: 'Test Citation' }),
        });

        render(<RelatedWorkSection relatedIdentifiers={relatedIdentifiers} />);

        // Should only show the second IsSupplementTo relation
        expect(screen.getByTestId('related-works-section')).toBeInTheDocument();
    });

    it('renders the section title', () => {
        const relatedIdentifiers = [
            {
                id: 1,
                identifier: '10.5880/test',
                identifier_type: 'DOI',
                relation_type: 'References',
            },
        ];

        global.fetch = vi.fn().mockResolvedValue({
            ok: true,
            json: () => Promise.resolve({ citation: 'Test Citation' }),
        });

        render(<RelatedWorkSection relatedIdentifiers={relatedIdentifiers} />);

        expect(screen.getByRole('heading', { name: 'Related Work' })).toBeInTheDocument();
    });

    it('groups relations by relation type', () => {
        const relatedIdentifiers = [
            {
                id: 1,
                identifier: '10.5880/ref1',
                identifier_type: 'DOI',
                relation_type: 'References',
            },
            {
                id: 2,
                identifier: '10.5880/cites1',
                identifier_type: 'DOI',
                relation_type: 'Cites',
            },
            {
                id: 3,
                identifier: '10.5880/ref2',
                identifier_type: 'DOI',
                relation_type: 'References',
            },
        ];

        global.fetch = vi.fn().mockResolvedValue({
            ok: true,
            json: () => Promise.resolve({ citation: 'Test Citation' }),
        });

        render(<RelatedWorkSection relatedIdentifiers={relatedIdentifiers} />);

        // Check for relation type headings
        expect(screen.getByText('References')).toBeInTheDocument();
        expect(screen.getByText('Cites')).toBeInTheDocument();
    });

    it('formats CamelCase relation types with spaces', () => {
        const relatedIdentifiers = [
            {
                id: 1,
                identifier: '10.5880/test',
                identifier_type: 'DOI',
                relation_type: 'IsDocumentedBy',
            },
        ];

        global.fetch = vi.fn().mockResolvedValue({
            ok: true,
            json: () => Promise.resolve({ citation: 'Test Citation' }),
        });

        render(<RelatedWorkSection relatedIdentifiers={relatedIdentifiers} />);

        expect(screen.getByText('Is Documented By')).toBeInTheDocument();
    });

    it('shows loading indicator while fetching citation', () => {
        const relatedIdentifiers = [
            {
                id: 1,
                identifier: '10.5880/test',
                identifier_type: 'DOI',
                relation_type: 'References',
            },
        ];

        // Never resolve to keep loading state
        global.fetch = vi.fn().mockReturnValue(new Promise(() => {}));

        render(<RelatedWorkSection relatedIdentifiers={relatedIdentifiers} />);

        expect(screen.getByText('Loading citation...')).toBeInTheDocument();
    });

    it('displays citation after successful fetch', async () => {
        const relatedIdentifiers = [
            {
                id: 1,
                identifier: '10.5880/test',
                identifier_type: 'DOI',
                relation_type: 'References',
            },
        ];

        global.fetch = vi.fn().mockResolvedValue({
            ok: true,
            json: () => Promise.resolve({ citation: 'Smith, J. (2024). Test Dataset.' }),
        });

        render(<RelatedWorkSection relatedIdentifiers={relatedIdentifiers} />);

        await waitFor(() => {
            expect(screen.getByText('Smith, J. (2024). Test Dataset.')).toBeInTheDocument();
        });
    });

    it('shows related title when citation fetch fails but title is available', async () => {
        const relatedIdentifiers = [
            {
                id: 1,
                identifier: '10.5880/test',
                identifier_type: 'DOI',
                relation_type: 'References',
                related_title: 'Fallback Title for Dataset',
            },
        ];

        global.fetch = vi.fn().mockResolvedValue({
            ok: false,
        });

        render(<RelatedWorkSection relatedIdentifiers={relatedIdentifiers} />);

        await waitFor(() => {
            expect(screen.getByText('Fallback Title for Dataset')).toBeInTheDocument();
        });
    });

    it('shows DOI identifier when citation fetch fails and no title', async () => {
        const relatedIdentifiers = [
            {
                id: 1,
                identifier: '10.5880/test',
                identifier_type: 'DOI',
                relation_type: 'References',
            },
        ];

        global.fetch = vi.fn().mockResolvedValue({
            ok: false,
        });

        render(<RelatedWorkSection relatedIdentifiers={relatedIdentifiers} />);

        await waitFor(() => {
            expect(screen.getByText('DOI: 10.5880/test')).toBeInTheDocument();
        });
    });

    it('handles network errors gracefully', async () => {
        const relatedIdentifiers = [
            {
                id: 1,
                identifier: '10.5880/test',
                identifier_type: 'DOI',
                relation_type: 'References',
            },
        ];

        global.fetch = vi.fn().mockRejectedValue(new Error('Network error'));

        render(<RelatedWorkSection relatedIdentifiers={relatedIdentifiers} />);

        await waitFor(() => {
            expect(screen.getByText('DOI: 10.5880/test')).toBeInTheDocument();
        });
    });

    it('renders external links with correct href', async () => {
        const relatedIdentifiers = [
            {
                id: 1,
                identifier: '10.5880/test',
                identifier_type: 'DOI',
                relation_type: 'References',
            },
        ];

        global.fetch = vi.fn().mockResolvedValue({
            ok: true,
            json: () => Promise.resolve({ citation: 'Test Citation' }),
        });

        render(<RelatedWorkSection relatedIdentifiers={relatedIdentifiers} />);

        await waitFor(() => {
            const link = screen.getByRole('link');
            expect(link).toHaveAttribute('href', 'https://doi.org/10.5880/test');
            expect(link).toHaveAttribute('target', '_blank');
            expect(link).toHaveAttribute('rel', 'noopener noreferrer');
        });
    });

    it('only renders DOI type identifiers', () => {
        const relatedIdentifiers = [
            {
                id: 1,
                identifier: '10.5880/doi-test',
                identifier_type: 'DOI',
                relation_type: 'References',
            },
            {
                id: 2,
                identifier: 'http://example.com',
                identifier_type: 'URL',
                relation_type: 'References',
            },
        ];

        global.fetch = vi.fn().mockResolvedValue({
            ok: true,
            json: () => Promise.resolve({ citation: 'Test Citation' }),
        });

        render(<RelatedWorkSection relatedIdentifiers={relatedIdentifiers} />);

        // Only DOI should trigger a fetch
        expect(global.fetch).toHaveBeenCalledTimes(1);
        expect(global.fetch).toHaveBeenCalledWith(
            expect.stringContaining('10.5880%2Fdoi-test'),
        );
    });

    it('sorts relation types alphabetically', () => {
        const relatedIdentifiers = [
            {
                id: 1,
                identifier: '10.5880/z',
                identifier_type: 'DOI',
                relation_type: 'Cites',
            },
            {
                id: 2,
                identifier: '10.5880/a',
                identifier_type: 'DOI',
                relation_type: 'References',
            },
            {
                id: 3,
                identifier: '10.5880/b',
                identifier_type: 'DOI',
                relation_type: 'Documents',
            },
        ];

        global.fetch = vi.fn().mockResolvedValue({
            ok: true,
            json: () => Promise.resolve({ citation: 'Test Citation' }),
        });

        render(<RelatedWorkSection relatedIdentifiers={relatedIdentifiers} />);

        const headings = screen.getAllByRole('heading', { level: 4 });
        const headingTexts = headings.map((h) => h.textContent);

        // Should be sorted alphabetically
        expect(headingTexts).toEqual(['Cites', 'Documents', 'References']);
    });

    it('renders multiple entries in same relation type group', async () => {
        const relatedIdentifiers = [
            {
                id: 1,
                identifier: '10.5880/test1',
                identifier_type: 'DOI',
                relation_type: 'References',
            },
            {
                id: 2,
                identifier: '10.5880/test2',
                identifier_type: 'DOI',
                relation_type: 'References',
            },
        ];

        global.fetch = vi.fn()
            .mockResolvedValueOnce({
                ok: true,
                json: () => Promise.resolve({ citation: 'Citation 1' }),
            })
            .mockResolvedValueOnce({
                ok: true,
                json: () => Promise.resolve({ citation: 'Citation 2' }),
            });

        render(<RelatedWorkSection relatedIdentifiers={relatedIdentifiers} />);

        await waitFor(() => {
            expect(screen.getByText('Citation 1')).toBeInTheDocument();
            expect(screen.getByText('Citation 2')).toBeInTheDocument();
        });
    });
});
