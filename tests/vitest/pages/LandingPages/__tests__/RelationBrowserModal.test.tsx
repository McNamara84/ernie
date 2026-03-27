import { render, screen } from '@tests/vitest/utils/render';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { RelationBrowserModal } from '@/pages/LandingPages/components/RelationBrowserModal';
import type { LandingPageRelatedIdentifier, LandingPageResource } from '@/types/landing-page';

// Mock matchMedia
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
                given_name: 'Jane',
                family_name: 'Smith',
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

const mockRelatedIdentifiers: LandingPageRelatedIdentifier[] = [
    {
        id: 1,
        identifier: '10.5880/related1',
        identifier_type: 'DOI',
        relation_type: 'References',
    },
    {
        id: 2,
        identifier: 'https://example.com/data',
        identifier_type: 'URL',
        relation_type: 'IsDocumentedBy',
    },
];

describe('RelationBrowserModal', () => {
    beforeEach(() => {
        vi.resetAllMocks();
        global.fetch = vi.fn().mockResolvedValue({
            ok: true,
            json: () => Promise.resolve({ citation: 'Smith, J. (2024). Related. GFZ.' }),
        });

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

    it('renders dialog with "Relation Browser" title when open', () => {
        render(
            <RelationBrowserModal
                open={true}
                onOpenChange={vi.fn()}
                resource={mockResource}
                relatedIdentifiers={mockRelatedIdentifiers}
            />,
        );

        expect(screen.getByText('Relation Browser')).toBeInTheDocument();
    });

    it('renders description text when open', () => {
        render(
            <RelationBrowserModal
                open={true}
                onOpenChange={vi.fn()}
                resource={mockResource}
                relatedIdentifiers={mockRelatedIdentifiers}
            />,
        );

        expect(screen.getByText(/Interactive graph of relationships/)).toBeInTheDocument();
    });

    it('does not render graph content when closed', () => {
        render(
            <RelationBrowserModal
                open={false}
                onOpenChange={vi.fn()}
                resource={mockResource}
                relatedIdentifiers={mockRelatedIdentifiers}
            />,
        );

        expect(screen.queryByTestId('relation-browser-graph')).not.toBeInTheDocument();
    });

    it('renders graph when open', () => {
        render(
            <RelationBrowserModal
                open={true}
                onOpenChange={vi.fn()}
                resource={mockResource}
                relatedIdentifiers={mockRelatedIdentifiers}
            />,
        );

        expect(screen.getByTestId('relation-browser-graph')).toBeInTheDocument();
    });

    it('renders legend with active identifier types', () => {
        render(
            <RelationBrowserModal
                open={true}
                onOpenChange={vi.fn()}
                resource={mockResource}
                relatedIdentifiers={mockRelatedIdentifiers}
            />,
        );

        expect(screen.getByTestId('relation-browser-legend')).toBeInTheDocument();
        expect(screen.getByText('DOI')).toBeInTheDocument();
        expect(screen.getByText('URL')).toBeInTheDocument();
    });

    it('filters out unsupported identifier types', () => {
        const identifiersWithUnsupported: LandingPageRelatedIdentifier[] = [
            ...mockRelatedIdentifiers,
            {
                id: 3,
                identifier: '12345',
                identifier_type: 'PMID',
                relation_type: 'Cites',
            },
        ];

        render(
            <RelationBrowserModal
                open={true}
                onOpenChange={vi.fn()}
                resource={mockResource}
                relatedIdentifiers={identifiersWithUnsupported}
            />,
        );

        // PMID should not appear in legend (unsupported type)
        expect(screen.queryByText('PMID')).not.toBeInTheDocument();
    });

    it('calls onOpenChange when closed', () => {
        const onOpenChange = vi.fn();

        render(
            <RelationBrowserModal
                open={true}
                onOpenChange={onOpenChange}
                resource={mockResource}
                relatedIdentifiers={mockRelatedIdentifiers}
            />,
        );

        // Click close button
        const closeButton = screen.getByRole('button', { name: 'Close' });
        closeButton.click();

        expect(onOpenChange).toHaveBeenCalledWith(false);
    });
});
