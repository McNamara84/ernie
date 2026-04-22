import { render, screen } from '@tests/vitest/utils/render';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { RelationBrowserGraph } from '@/pages/LandingPages/components/relation-browser/RelationBrowserGraph';
import type { LandingPageRelatedIdentifier, LandingPageResource } from '@/types/landing-page';

// Mock matchMedia for prefers-reduced-motion
Object.defineProperty(window, 'matchMedia', {
    writable: true,
    value: vi.fn().mockImplementation((query: string) => ({
        matches: true, // Enable reduced motion to get static layout for testing
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
    {
        id: 3,
        identifier: '978-3-06-024810-5',
        identifier_type: 'ISBN',
        relation_type: 'Cites',
    },
];

describe('RelationBrowserGraph', () => {
    beforeEach(() => {
        vi.resetAllMocks();

        global.fetch = vi.fn().mockResolvedValue({
            ok: true,
            json: () => Promise.resolve({ citation: 'Doe, J. (2024). Test. GFZ.' }),
        });

        // Re-mock matchMedia after reset
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

    it('renders SVG element', () => {
        render(
            <RelationBrowserGraph
                resource={mockResource}
                relatedIdentifiers={mockRelatedIdentifiers}
            />,
        );

        expect(screen.getByTestId('relation-browser-graph')).toBeInTheDocument();
        expect(screen.getByRole('img', { name: 'Relation Browser Graph' })).toBeInTheDocument();
    });

    it('renders with the correct container structure', () => {
        render(
            <RelationBrowserGraph
                resource={mockResource}
                relatedIdentifiers={mockRelatedIdentifiers}
            />,
        );

        const container = screen.getByTestId('relation-browser-graph');
        expect(container).toBeInTheDocument();
        expect(container.querySelector('svg')).toBeInTheDocument();
    });

    it('handles empty related identifiers', () => {
        render(
            <RelationBrowserGraph
                resource={mockResource}
                relatedIdentifiers={[]}
            />,
        );

        expect(screen.getByTestId('relation-browser-graph')).toBeInTheDocument();
    });

    it('filters out identifiers with unsupported types', () => {
        const identifiers: LandingPageRelatedIdentifier[] = [
            {
                id: 1,
                identifier: '10.5880/valid',
                identifier_type: 'DOI',
                relation_type: 'References',
            },
            {
                id: 2,
                identifier: '12345',
                identifier_type: 'PMID',
                relation_type: 'Cites',
            },
        ];

        render(
            <RelationBrowserGraph
                resource={mockResource}
                relatedIdentifiers={identifiers}
            />,
        );

        // Should still render without error
        expect(screen.getByTestId('relation-browser-graph')).toBeInTheDocument();
    });

    it('creates central node label from resource creators', () => {
        render(
            <RelationBrowserGraph
                resource={mockResource}
                relatedIdentifiers={mockRelatedIdentifiers}
            />,
        );

        const svg = screen.getByRole('img', { name: 'Relation Browser Graph' });
        const texts = svg.querySelectorAll('text');
        const labels = Array.from(texts).map((t) => t.textContent);
        expect(labels).toContain('Doe, 2024');
    });

    it('creates correct number of nodes', () => {
        render(
            <RelationBrowserGraph
                resource={mockResource}
                relatedIdentifiers={mockRelatedIdentifiers}
            />,
        );

        const svg = screen.getByRole('img', { name: 'Relation Browser Graph' });
        const circles = svg.querySelectorAll('circle');
        // 1 central + 3 related + 1 creator = 5
        expect(circles.length).toBe(5);
    });

    it('creates correct number of links', () => {
        render(
            <RelationBrowserGraph
                resource={mockResource}
                relatedIdentifiers={mockRelatedIdentifiers}
            />,
        );

        const svg = screen.getByRole('img', { name: 'Relation Browser Graph' });
        const lines = svg.querySelectorAll('line');
        // 3 related + 1 creator = 4
        expect(lines.length).toBe(4);
    });

    it('uses GFZ blue for central node', () => {
        render(
            <RelationBrowserGraph
                resource={mockResource}
                relatedIdentifiers={mockRelatedIdentifiers}
            />,
        );

        const svg = screen.getByRole('img', { name: 'Relation Browser Graph' });
        const circles = svg.querySelectorAll('circle');
        // Central node is first
        expect(circles[0]?.getAttribute('fill')).toBe('#0C2A63');
    });

    it('uses correct colors for related nodes', () => {
        render(
            <RelationBrowserGraph
                resource={mockResource}
                relatedIdentifiers={mockRelatedIdentifiers}
            />,
        );

        const svg = screen.getByRole('img', { name: 'Relation Browser Graph' });
        const circles = svg.querySelectorAll('circle');
        // DOI node (index 1)
        expect(circles[1]?.getAttribute('fill')).toBe('#10B981');
        // URL node (index 2)
        expect(circles[2]?.getAttribute('fill')).toBe('#0EA5E9');
        // ISBN node (index 3)
        expect(circles[3]?.getAttribute('fill')).toBe('#F97316');
    });

    it('handles resource without creators gracefully', () => {
        const resourceWithoutCreators: LandingPageResource = {
            ...mockResource,
            creators: [],
            titles: [{ id: 1, title: 'Fallback Title', title_type: 'MainTitle' }],
        };

        render(
            <RelationBrowserGraph
                resource={resourceWithoutCreators}
                relatedIdentifiers={mockRelatedIdentifiers}
            />,
        );

        expect(screen.getByTestId('relation-browser-graph')).toBeInTheDocument();
    });
});
