import { fireEvent, render, screen, waitFor } from '@tests/vitest/utils/render';
import { beforeEach,describe, expect, it, vi } from 'vitest';

import { RelatedWorkSection } from '@/pages/LandingPages/components/RelatedWorkSection';
import type { LandingPageResource } from '@/types/landing-page';


// Mock matchMedia for prefers-reduced-motion
Object.defineProperty(window, 'matchMedia', {
    writable: true,
    value: vi.fn().mockImplementation((query: string) => ({
        matches: true,
        media: query,
        onchange: null,
        addListener: vi.fn(),
        removeListener: vi.fn(),
        addEventListener: vi.fn(),
        removeEventListener: vi.fn(),
        dispatchEvent: vi.fn(),
    })),
});

const mockResource: LandingPageResource = {
    id: 1,
    identifier: '10.5880/GFZ.1.2.2024.001',
    publication_year: 2024,
    version: '1.0',
    language: 'en',
    creators: [
        {
            id: 1,
            position: 1,
            affiliations: [],
            creatorable: {
                type: 'Person',
                id: 1,
                given_name: 'John',
                family_name: 'Doe',
                name_identifier: null,
                name_identifier_scheme: null,
                name: null,
            },
        },
    ],
    titles: [
        { id: 1, title: 'Test Dataset', title_type: null },
    ],
};

describe('RelatedWorkSection', () => {
    beforeEach(() => {
        vi.resetAllMocks();

        Object.defineProperty(window, 'matchMedia', {
            writable: true,
            value: vi.fn().mockImplementation((query: string) => ({
                matches: true,
                media: query,
                onchange: null,
                addListener: vi.fn(),
                removeListener: vi.fn(),
                addEventListener: vi.fn(),
                removeEventListener: vi.fn(),
                dispatchEvent: vi.fn(),
            })),
        });
    });

    it('returns null when no related identifiers', () => {
        const { container } = render(<RelatedWorkSection resource={mockResource} relatedIdentifiers={[]} />);
        expect(container.firstChild).toBeNull();
    });

    it('renders inline relatedItems with Inline metadata badge', () => {
        const relatedItems = [
            {
                id: 10,
                related_item_type: 'JournalArticle',
                relation_type: 'IsCitedBy',
                relation_type_slug: 'iscitedby',
                publication_year: 2024,
                volume: '42',
                issue: '3',
                number: null,
                number_type: null,
                first_page: '1',
                last_page: '20',
                publisher: 'Acme Press',
                edition: null,
                identifier: '10.1234/cited',
                identifier_type: 'DOI',
                related_metadata_scheme: null,
                scheme_uri: null,
                scheme_type: null,
                position: 1,
                titles: [
                    { id: 1, title: 'Cited Paper Title', title_type: 'MainTitle', language: 'en' },
                ],
                creators: [
                    {
                        id: 1,
                        name_type: 'Personal' as const,
                        name: 'Doe, Jane',
                        given_name: 'Jane',
                        family_name: 'Doe',
                        name_identifier: null,
                        name_identifier_scheme: null,
                        scheme_uri: null,
                        position: 1,
                        affiliations: [],
                    },
                ],
                contributors: [],
            },
        ];

        render(
            <RelatedWorkSection
                resource={mockResource}
                relatedIdentifiers={[]}
                relatedItems={relatedItems}
            />,
        );

        expect(screen.getByTestId('related-items-list')).toBeInTheDocument();
        expect(screen.getByText('Inline metadata')).toBeInTheDocument();
        expect(screen.getByText('Cited Paper Title')).toBeInTheDocument();
        expect(screen.getByTestId('related-item-10')).toBeInTheDocument();
        // Descriptor combines author, year, publisher, volume/issue/pages
        expect(screen.getByText(/Doe/)).toBeInTheDocument();
        expect(screen.getByText(/2024/)).toBeInTheDocument();
    });

    it('returns null when all identifiers have unsupported types', () => {
        const relatedIdentifiers = [
            { id: 1, identifier: '12345', identifier_type: 'PMID', relation_type: 'References' },
            { id: 2, identifier: '67890', identifier_type: 'EAN13', relation_type: 'IsCitedBy' },
        ];

        const { container } = render(<RelatedWorkSection resource={mockResource} relatedIdentifiers={relatedIdentifiers} />);
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

        render(<RelatedWorkSection resource={mockResource} relatedIdentifiers={relatedIdentifiers} />);

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

        render(<RelatedWorkSection resource={mockResource} relatedIdentifiers={relatedIdentifiers} />);

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

        render(<RelatedWorkSection resource={mockResource} relatedIdentifiers={relatedIdentifiers} />);

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

        render(<RelatedWorkSection resource={mockResource} relatedIdentifiers={relatedIdentifiers} />);

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

        render(<RelatedWorkSection resource={mockResource} relatedIdentifiers={relatedIdentifiers} />);

        // Loading state now uses Skeleton components with aria-busy
        const busyEl = document.querySelector('[aria-busy="true"]');
        expect(busyEl).toBeInTheDocument();
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

        render(<RelatedWorkSection resource={mockResource} relatedIdentifiers={relatedIdentifiers} />);

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

        render(<RelatedWorkSection resource={mockResource} relatedIdentifiers={relatedIdentifiers} />);

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

        render(<RelatedWorkSection resource={mockResource} relatedIdentifiers={relatedIdentifiers} />);

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

        render(<RelatedWorkSection resource={mockResource} relatedIdentifiers={relatedIdentifiers} />);

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

        render(<RelatedWorkSection resource={mockResource} relatedIdentifiers={relatedIdentifiers} />);

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

        render(<RelatedWorkSection resource={mockResource} relatedIdentifiers={relatedIdentifiers} />);

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

        render(<RelatedWorkSection resource={mockResource} relatedIdentifiers={relatedIdentifiers} />);

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

        render(<RelatedWorkSection resource={mockResource} relatedIdentifiers={relatedIdentifiers} />);

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

        render(<RelatedWorkSection resource={mockResource} relatedIdentifiers={relatedIdentifiers} />);

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

        render(<RelatedWorkSection resource={mockResource} relatedIdentifiers={relatedIdentifiers} />);

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

        render(<RelatedWorkSection resource={mockResource} relatedIdentifiers={relatedIdentifiers} />);

        const headings = screen.getAllByRole('heading', { level: 3 });
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

        render(<RelatedWorkSection resource={mockResource} relatedIdentifiers={relatedIdentifiers} />);

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

        render(<RelatedWorkSection resource={mockResource} relatedIdentifiers={relatedIdentifiers} />);

        // URL should render immediately
        expect(screen.getByText('https://docs.example.com/manual')).toBeInTheDocument();

        // DOI citation should appear after fetch
        await waitFor(() => {
            expect(screen.getByText('DOI Citation')).toBeInTheDocument();
        });
    });

    describe('Relation Browser button', () => {
        it('renders the Relation Browser icon button', () => {
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

            render(<RelatedWorkSection resource={mockResource} relatedIdentifiers={relatedIdentifiers} />);

            expect(screen.getByTestId('relation-browser-button')).toBeInTheDocument();
            expect(screen.getByRole('button', { name: 'Open Relation Browser' })).toBeInTheDocument();
        });

        it('opens Relation Browser modal on button click', async () => {
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

            render(<RelatedWorkSection resource={mockResource} relatedIdentifiers={relatedIdentifiers} />);

            const button = screen.getByTestId('relation-browser-button');
            button.click();

            await waitFor(() => {
                expect(screen.getByText('Relation Browser')).toBeInTheDocument();
            });
        });

        it('passes resource and filtered identifiers to modal', async () => {
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

            render(<RelatedWorkSection resource={mockResource} relatedIdentifiers={relatedIdentifiers} />);

            const button = screen.getByTestId('relation-browser-button');
            button.click();

            await waitFor(() => {
                expect(screen.getByTestId('relation-browser-modal')).toBeInTheDocument();
                expect(screen.getByTestId('relation-browser-graph')).toBeInTheDocument();
            });
        });
    });

    describe('accessibility', () => {
        it('renders as a section element with aria-labelledby', () => {
            const relatedIdentifiers = [
                { id: 1, identifier: '10.5880/test', identifier_type: 'DOI', relation_type: 'References' },
            ];

            global.fetch = vi.fn().mockResolvedValue({
                ok: true,
                json: () => Promise.resolve({ citation: 'Test' }),
            });

            render(<RelatedWorkSection resource={mockResource} relatedIdentifiers={relatedIdentifiers} />);

            const section = screen.getByRole('region', { name: 'Related Work' });
            expect(section).toBeInTheDocument();
        });

        it('renders heading as h2 and group headings as h3', () => {
            const relatedIdentifiers = [
                { id: 1, identifier: '10.5880/test', identifier_type: 'DOI', relation_type: 'References' },
            ];

            global.fetch = vi.fn().mockResolvedValue({
                ok: true,
                json: () => Promise.resolve({ citation: 'Test' }),
            });

            render(<RelatedWorkSection resource={mockResource} relatedIdentifiers={relatedIdentifiers} />);

            expect(screen.getByRole('heading', { level: 2, name: 'Related Work' })).toBeInTheDocument();
            expect(screen.getByRole('heading', { level: 3, name: 'References' })).toBeInTheDocument();
        });

        it('renders Skeleton with aria-busy when citations are loading', () => {
            const relatedIdentifiers = [
                { id: 1, identifier: '10.5880/test', identifier_type: 'DOI', relation_type: 'References' },
            ];

            global.fetch = vi.fn().mockReturnValue(new Promise(() => {}));

            render(<RelatedWorkSection resource={mockResource} relatedIdentifiers={relatedIdentifiers} />);

            const busyEls = document.querySelectorAll('[aria-busy="true"]');
            expect(busyEls.length).toBeGreaterThan(0);
        });

        it('renders Relation Browser button with minimum touch target', () => {
            const relatedIdentifiers = [
                { id: 1, identifier: '10.5880/test', identifier_type: 'DOI', relation_type: 'References' },
            ];

            global.fetch = vi.fn().mockResolvedValue({
                ok: true,
                json: () => Promise.resolve({ citation: 'Test' }),
            });

            render(<RelatedWorkSection resource={mockResource} relatedIdentifiers={relatedIdentifiers} />);

            const button = screen.getByTestId('relation-browser-button');
            expect(button).toHaveClass('min-h-11', 'min-w-11');
        });
    });

    describe('collapsible on mobile', () => {
        it('does not show expand button when entries <= 9', () => {
            const relatedIdentifiers = Array.from({ length: 5 }, (_, i) => ({
                id: i + 1,
                identifier: `https://example.com/${i}`,
                identifier_type: 'URL',
                relation_type: 'References',
            }));

            global.fetch = vi.fn();

            render(<RelatedWorkSection resource={mockResource} relatedIdentifiers={relatedIdentifiers} />);

            expect(screen.queryByRole('button', { name: /Show all/i })).not.toBeInTheDocument();
        });

        it('shows expand button when entries > 9', () => {
            const relatedIdentifiers = Array.from({ length: 12 }, (_, i) => ({
                id: i + 1,
                identifier: `https://example.com/${i}`,
                identifier_type: 'URL',
                relation_type: 'References',
            }));

            global.fetch = vi.fn();

            render(<RelatedWorkSection resource={mockResource} relatedIdentifiers={relatedIdentifiers} />);

            const expandButton = screen.getByRole('button', { name: /Show all \(12\)/i });
            expect(expandButton).toBeInTheDocument();
            expect(expandButton).toHaveAttribute('aria-expanded', 'false');
            expect(expandButton).toHaveAttribute('aria-controls', 'related-work-list');
        });

        it('toggles expanded state on button click', async () => {
            const relatedIdentifiers = Array.from({ length: 12 }, (_, i) => ({
                id: i + 1,
                identifier: `https://example.com/${i}`,
                identifier_type: 'URL',
                relation_type: 'References',
            }));

            global.fetch = vi.fn();

            render(<RelatedWorkSection resource={mockResource} relatedIdentifiers={relatedIdentifiers} />);

            const expandButton = screen.getByRole('button', { name: /Show all/i });
            fireEvent.click(expandButton);

            await waitFor(() => {
                expect(screen.getByRole('button', { name: /Show less/i })).toBeInTheDocument();
                expect(screen.getByRole('button', { name: /Show less/i })).toHaveAttribute('aria-expanded', 'true');
            });
        });
    });
});
