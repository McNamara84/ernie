import userEvent from '@testing-library/user-event';
import { fireEvent, render, screen } from '@tests/vitest/utils/render';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { RelatedWorkSection } from '@/pages/LandingPages/components/RelatedWorkSection';
import type { LandingPageRelatedIdentifier, LandingPageResource } from '@/types/landing-page';

vi.mock('@/pages/LandingPages/components/relation-browser/RelationBrowserGraph', () => ({
    RelationBrowserGraph: ({ relatedIdentifiers }: { relatedIdentifiers: LandingPageRelatedIdentifier[] }) => (
        <div data-testid="relation-browser-graph">{relatedIdentifiers.length}</div>
    ),
}));

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
    titles: [{ id: 1, title: 'Test Dataset', title_type: null }],
};

function makeRelatedIdentifier(overrides: Partial<LandingPageRelatedIdentifier> = {}): LandingPageRelatedIdentifier {
    return {
        id: 1,
        identifier: '10.5880/test',
        identifier_type: 'DOI',
        relation_type: 'References',
        citation_label: null,
        related_title: null,
        ...overrides,
    };
}

describe('RelatedWorkSection', () => {
    beforeEach(() => {
        vi.resetAllMocks();
        global.fetch = vi.fn();

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

    it('returns null when there are no renderable related identifiers or related items', () => {
        const { container } = render(<RelatedWorkSection resource={mockResource} relatedIdentifiers={[]} />);

        expect(container.firstChild).toBeNull();
    });

    it('renders inline related items with the metadata badge', () => {
        render(
            <RelatedWorkSection
                resource={mockResource}
                relatedIdentifiers={[]}
                relatedItems={[
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
                        titles: [{ id: 1, title: 'Cited Paper Title', title_type: 'MainTitle', language: 'en' }],
                        creators: [
                            {
                                id: 1,
                                name_type: 'Personal',
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
                ]}
            />,
        );

        expect(screen.getByTestId('related-items-list')).toBeInTheDocument();
        expect(screen.getByText('Inline metadata')).toBeInTheDocument();
        expect(screen.getByText('Cited Paper Title')).toBeInTheDocument();
        expect(screen.getByText(/Doe/)).toBeInTheDocument();
    });

    it('does not render when all identifiers use unsupported types', () => {
        const { container } = render(
            <RelatedWorkSection
                resource={mockResource}
                relatedIdentifiers={[
                    makeRelatedIdentifier({ id: 1, identifier_type: 'PMID', identifier: '12345' }),
                    makeRelatedIdentifier({ id: 2, identifier_type: 'EAN13', identifier: '67890' }),
                ]}
            />,
        );

        expect(container.firstChild).toBeNull();
    });

    it('excludes the first IsSupplementTo relation from the list', () => {
        render(
            <RelatedWorkSection
                resource={mockResource}
                relatedIdentifiers={[
                    makeRelatedIdentifier({ id: 1, relation_type: 'IsSupplementTo', citation_label: 'Hidden first supplement' }),
                    makeRelatedIdentifier({
                        id: 2,
                        relation_type: 'IsSupplementTo',
                        identifier: '10.5880/second',
                        citation_label: 'Visible supplement',
                    }),
                ]}
            />,
        );

        expect(screen.queryByText('Hidden first supplement')).not.toBeInTheDocument();
        expect(screen.getByText('Visible supplement')).toBeInTheDocument();
    });

    it('renders headings as region, h2, and alphabetically sorted h3 groups', () => {
        render(
            <RelatedWorkSection
                resource={mockResource}
                relatedIdentifiers={[
                    makeRelatedIdentifier({ id: 1, relation_type: 'References' }),
                    makeRelatedIdentifier({ id: 2, relation_type: 'Cites', identifier: '10.5880/cites' }),
                    makeRelatedIdentifier({ id: 3, relation_type: 'IsDocumentedBy', identifier: 'https://example.com/doc', identifier_type: 'URL' }),
                ]}
            />,
        );

        expect(screen.getByRole('region', { name: 'Related Work' })).toBeInTheDocument();
        expect(screen.getByRole('heading', { level: 2, name: 'Related Work' })).toBeInTheDocument();

        const headings = screen.getAllByRole('heading', { level: 3 }).map((heading) => heading.textContent);
        expect(headings).toEqual(['Cites', 'Is Documented By', 'References']);
    });

    it('renders repository curation related identifiers below initial metadata', () => {
        render(
            <RelatedWorkSection
                resource={mockResource}
                relatedIdentifiers={[
                    makeRelatedIdentifier({
                        id: 1,
                        relation_type: 'References',
                        citation_label: 'Initial citation',
                    }),
                    makeRelatedIdentifier({
                        id: 2,
                        relation_type: 'Cites',
                        identifier: '10.5880/curated',
                        citation_label: 'Curated citation',
                        source: 'relation_suggestion_assistant',
                        is_repository_curation: true,
                    }),
                ]}
            />,
        );

        const listText = screen.getByTestId('related-works-list').textContent ?? '';
        const initialIndex = listText.indexOf('Initial citation');
        const curationHeadingIndex = listText.indexOf('Added by repository curation');
        const curatedIndex = listText.indexOf('Curated citation');

        expect(initialIndex).toBeGreaterThanOrEqual(0);
        expect(curationHeadingIndex).toBeGreaterThan(initialIndex);
        expect(curatedIndex).toBeGreaterThan(curationHeadingIndex);
        expect(screen.getByTestId('repository-curation-related-identifiers')).toHaveTextContent('Added by repository curation');
        expect(screen.getByRole('link', { name: /Curated citation/i })).toHaveClass('bg-cyan-50/70');
    });
    it('renders persisted citation labels for DOI links and synchronous DOI fallbacks when missing', () => {
        render(
            <RelatedWorkSection
                resource={mockResource}
                relatedIdentifiers={[
                    makeRelatedIdentifier({ id: 1, citation_label: 'Smith, J. (2024). Persisted Citation.' }),
                    makeRelatedIdentifier({ id: 2, identifier: '10.5880/no-label' }),
                ]}
            />,
        );

        expect(screen.getByText('Smith, J. (2024). Persisted Citation.')).toBeInTheDocument();
        expect(screen.getByText('DOI: 10.5880/no-label')).toBeInTheDocument();
        expect(global.fetch).not.toHaveBeenCalled();
    });

    it('renders URL and Handle identifiers as direct links without runtime fetches', () => {
        render(
            <RelatedWorkSection
                resource={mockResource}
                relatedIdentifiers={[
                    makeRelatedIdentifier({ id: 1, identifier_type: 'URL', identifier: 'https://example.com/dataset' }),
                    makeRelatedIdentifier({ id: 2, identifier_type: 'Handle', identifier: '10013/epic.12345' }),
                ]}
            />,
        );

        const links = screen.getAllByRole('link');
        expect(links[0]).toHaveAttribute('href', 'https://example.com/dataset');
        expect(links[1]).toHaveAttribute('href', 'https://hdl.handle.net/10013/epic.12345');
        expect(global.fetch).not.toHaveBeenCalled();
    });

    it('renders mixed DOI and non-DOI items within the same relation group', () => {
        render(
            <RelatedWorkSection
                resource={mockResource}
                relatedIdentifiers={[
                    makeRelatedIdentifier({ id: 1, relation_type: 'IsDocumentedBy', citation_label: 'DOI Citation' }),
                    makeRelatedIdentifier({
                        id: 2,
                        relation_type: 'IsDocumentedBy',
                        identifier_type: 'URL',
                        identifier: 'https://docs.example.com/manual',
                    }),
                ]}
            />,
        );

        expect(screen.getByText('DOI Citation')).toBeInTheDocument();
        expect(screen.getByText('https://docs.example.com/manual')).toBeInTheDocument();
    });

    it('shows the mobile collapse button when more than nine entries exist and toggles it', () => {
        render(
            <RelatedWorkSection
                resource={mockResource}
                relatedIdentifiers={Array.from({ length: 12 }, (_, index) =>
                    makeRelatedIdentifier({
                        id: index + 1,
                        identifier_type: 'URL',
                        identifier: `https://example.com/${index}`,
                    }),
                )}
            />,
        );

        const expandButton = screen.getByRole('button', { name: /Show all \(12\)/i });
        expect(expandButton).toHaveAttribute('aria-expanded', 'false');

        fireEvent.click(expandButton);

        expect(screen.getByRole('button', { name: /Show less/i })).toHaveAttribute('aria-expanded', 'true');
    });

    it('opens the relation browser modal from the action button', async () => {
        const user = userEvent.setup();

        render(
            <RelatedWorkSection
                resource={mockResource}
                relatedIdentifiers={[makeRelatedIdentifier({ citation_label: 'Smith, J. (2024). Persisted Citation.' })]}
            />,
        );

        const button = screen.getByRole('button', { name: 'Open Relation Browser' });
        expect(button).toHaveClass('min-h-11', 'min-w-11');

        await user.click(button);

        expect(await screen.findByText('Relation Browser')).toBeInTheDocument();
        expect(screen.getByTestId('relation-browser-modal')).toBeInTheDocument();
        expect(screen.getByTestId('relation-browser-graph')).toHaveTextContent('1');
    });
});
