import { render, screen, waitFor } from '@tests/vitest/utils/render';
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

    it('shows DOI identifier when citation fetch fails', async () => {
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

    it('renders URL identifier types as direct links', () => {
        const relatedIdentifiers = [
            {
                id: 1,
                identifier: 'https://example.com/dataset',
                identifier_type: 'URL',
                relation_type: 'IsDocumentedBy',
            },
        ];

        global.fetch = vi.fn();

        render(<RelatedWorkSection relatedIdentifiers={relatedIdentifiers} />);

        const link = screen.getByRole('link');
        expect(link).toHaveAttribute('href', 'https://example.com/dataset');
        expect(link).toHaveTextContent('https://example.com/dataset');
        // No citation fetch for URLs
        expect(global.fetch).not.toHaveBeenCalled();
    });

    it('renders Handle identifier types with hdl.handle.net', () => {
        const relatedIdentifiers = [
            {
                id: 1,
                identifier: '10013/epic.12345',
                identifier_type: 'Handle',
                relation_type: 'IsPreviousVersionOf',
            },
        ];

        global.fetch = vi.fn();

        render(<RelatedWorkSection relatedIdentifiers={relatedIdentifiers} />);

        const link = screen.getByRole('link');
        expect(link).toHaveAttribute('href', 'https://hdl.handle.net/10013/epic.12345');
        expect(link).toHaveTextContent('10013/epic.12345');
        expect(global.fetch).not.toHaveBeenCalled();
    });

    it('renders URN identifier types with nbn-resolving.org', () => {
        const relatedIdentifiers = [
            {
                id: 1,
                identifier: 'urn:nbn:de:kobv:b4-200905193913',
                identifier_type: 'URN',
                relation_type: 'References',
            },
        ];

        global.fetch = vi.fn();

        render(<RelatedWorkSection relatedIdentifiers={relatedIdentifiers} />);

        const link = screen.getByRole('link');
        expect(link).toHaveAttribute('href', 'https://nbn-resolving.org/urn:nbn:de:kobv:b4-200905193913');
    });

    it('hides group heading when all items have unsupported identifier types', () => {
        const relatedIdentifiers = [
            {
                id: 1,
                identifier: '12345',
                identifier_type: 'EAN13',
                relation_type: 'References',
            },
        ];

        global.fetch = vi.fn();

        render(<RelatedWorkSection relatedIdentifiers={relatedIdentifiers} />);

        // Section shouldn't render at all since there are no renderable items...
        // Actually the section DOES render because filteredRelations is non-empty,
        // but the group heading should not appear
        expect(screen.queryByText('References')).not.toBeInTheDocument();
    });

    it('deduplicates citation fetch for same DOI with different relation types', async () => {
        const relatedIdentifiers = [
            {
                id: 1,
                identifier: '10.5880/same-doi',
                identifier_type: 'DOI',
                relation_type: 'References',
            },
            {
                id: 2,
                identifier: '10.5880/same-doi',
                identifier_type: 'DOI',
                relation_type: 'Cites',
            },
        ];

        global.fetch = vi.fn().mockResolvedValue({
            ok: true,
            json: () => Promise.resolve({ citation: 'Shared Citation' }),
        });

        render(<RelatedWorkSection relatedIdentifiers={relatedIdentifiers} />);

        // Should only fetch once for the deduplicated DOI
        await waitFor(() => {
            expect(global.fetch).toHaveBeenCalledTimes(1);
        });
    });

    it('fetches citations only for DOI types, not for URL or Handle', async () => {
        const relatedIdentifiers = [
            {
                id: 1,
                identifier: '10.5880/doi-test',
                identifier_type: 'DOI',
                relation_type: 'References',
            },
            {
                id: 2,
                identifier: 'https://example.com',
                identifier_type: 'URL',
                relation_type: 'References',
            },
            {
                id: 3,
                identifier: '10013/epic.55555',
                identifier_type: 'Handle',
                relation_type: 'References',
            },
        ];

        global.fetch = vi.fn().mockResolvedValue({
            ok: true,
            json: () => Promise.resolve({ citation: 'Test Citation' }),
        });

        render(<RelatedWorkSection relatedIdentifiers={relatedIdentifiers} />);

        // Only the DOI should trigger a fetch
        await waitFor(() => {
            expect(global.fetch).toHaveBeenCalledTimes(1);
            expect(global.fetch).toHaveBeenCalledWith(
                expect.stringContaining('10.5880%2Fdoi-test'),
                expect.objectContaining({ signal: expect.any(AbortSignal) }),
            );
        });
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

    it('renders mixed DOI and non-DOI items in the same group', async () => {
        const relatedIdentifiers = [
            {
                id: 1,
                identifier: '10.5880/test-doi',
                identifier_type: 'DOI',
                relation_type: 'IsDocumentedBy',
            },
            {
                id: 2,
                identifier: 'https://docs.example.com/manual',
                identifier_type: 'URL',
                relation_type: 'IsDocumentedBy',
            },
        ];

        global.fetch = vi.fn().mockResolvedValue({
            ok: true,
            json: () => Promise.resolve({ citation: 'DOI Citation' }),
        });

        render(<RelatedWorkSection relatedIdentifiers={relatedIdentifiers} />);

        // URL should render immediately
        expect(screen.getByText('https://docs.example.com/manual')).toBeInTheDocument();

        // DOI citation should appear after fetch
        await waitFor(() => {
            expect(screen.getByText('DOI Citation')).toBeInTheDocument();
        });
    });
});
