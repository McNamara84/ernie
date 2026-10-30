import { fireEvent, render, screen, within } from '@tests/vitest/utils/render';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { ModelDescriptionSection } from '@/pages/LandingPages/components/ModelDescriptionSection';
import { RelatedWorkSection } from '@/pages/LandingPages/components/RelatedWorkSection';
import {
    EDGE_FALLBACK_COLOR,
    getEdgeCategoryColorMap,
    getNodeColor,
    getNodeColorMap,
    GFZ_BLUE,
    NODE_FALLBACK_COLOR,
} from '@/pages/LandingPages/components/relation-browser/graph-colors';
import { RelationBrowserModal } from '@/pages/LandingPages/components/RelationBrowserModal';
import { VersionNotice } from '@/pages/LandingPages/components/VersionNotice';
import type { LandingPageRelatedIdentifier, LandingPageResource } from '@/types/landing-page';

const core = 'swh:1:cnt:4d99d2d18326621ccdd70f5ea66c2e2ac236ad8b';
const qualified = `${core};origin=https://example.org/Repo/;path=/A%20B.c;lines=9-15`;
const url = `https://archive.softwareheritage.org/${qualified}`;
const resource: LandingPageResource = {
    id: 1,
    identifier: '10.5880/test',
    publication_year: 2026,
    version: '1.0',
    language: 'en',
    creators: [],
    titles: [{ id: 1, title: 'Test resource', title_type: null }],
};
const related: LandingPageRelatedIdentifier = { id: 2, identifier: url, identifier_type: 'SWHID', relation_type: 'IsIdenticalTo' };

beforeEach(() => {
    vi.stubGlobal(
        'matchMedia',
        vi.fn(() => ({
            matches: true,
            media: '',
            onchange: null,
            addListener: vi.fn(),
            removeListener: vi.fn(),
            addEventListener: vi.fn(),
            removeEventListener: vi.fn(),
            dispatchEvent: vi.fn(),
        })),
    );
});

describe('SWHID landing-page modules', () => {
    it('shows a two-line decoded label and preserves the complete link', () => {
        render(<RelatedWorkSection resource={resource} relatedIdentifiers={[related]} />);
        expect(screen.getByText('Is Identical To')).toBeInTheDocument();
        const link = screen.getByRole('link');
        expect(link).toHaveTextContent(`File · ${core}`);
        expect(link).toHaveTextContent('Origin: https://example.org/Repo/ · Path: /A B.c · Lines 9–15');
        expect(link).toHaveAttribute('href', url);
        expect(link).toHaveAttribute('target', '_blank');
        expect(link).toHaveAttribute('rel', 'noopener noreferrer');
    });

    it.each([{ citation_label: 'Curated citation' }, { related_title: 'Related software title' }])('preserves preferred labels %j', (label) => {
        render(<RelatedWorkSection resource={resource} relatedIdentifiers={[{ ...related, ...label }]} />);
        expect(screen.getByRole('link')).toHaveTextContent(Object.values(label)[0]!);
        expect(screen.getByRole('link')).toHaveAttribute('href', url);
        expect(screen.queryByText(/Origin:/)).not.toBeInTheDocument();
    });

    it('hides invalid SWHIDs and leaves other resolvable identifiers visible', () => {
        render(
            <RelatedWorkSection
                resource={resource}
                relatedIdentifiers={[
                    { ...related, identifier: core.slice(0, -1) },
                    { id: 3, identifier: '10.5880/other', identifier_type: 'DOI', relation_type: 'References' },
                ]}
            />,
        );
        expect(screen.queryByTestId('related-work-entry-2')).not.toBeInTheDocument();
        expect(screen.getByRole('link')).toHaveAttribute('href', 'https://doi.org/10.5880/other');
    });

    it('uses the SWHID resolver in model descriptions and newer-version notices', () => {
        const { unmount } = render(
            <ModelDescriptionSection relatedIdentifiers={[{ ...related, relation_type: 'IsSupplementTo' }]} resourceType="Model" />,
        );
        expect(screen.getByRole('link')).toHaveAttribute('href', url);
        unmount();
        render(<VersionNotice relatedIdentifiers={[{ ...related, relation_type: 'IsPreviousVersionOf' }]} relatedItems={[]} />);
        expect(screen.getByRole('link')).toHaveAttribute('href', url);
    });

    it('renders a valid SWHID node, legend, complete tooltip and working node click while filtering invalid values', () => {
        const open = vi.spyOn(window, 'open').mockImplementation(() => null);
        render(
            <RelationBrowserModal
                open
                onOpenChange={vi.fn()}
                resource={resource}
                relatedIdentifiers={[
                    { ...related, citation_label: 'Lovelace, A. (2026). Archive.' },
                    { ...related, id: 3, identifier: core.slice(0, -1) },
                ]}
            />,
        );
        const legend = screen.getByTestId('relation-browser-legend');
        expect(within(legend).getByText('SWHID')).toBeInTheDocument();
        const graph = screen.getByTestId('relation-browser-graph');
        const nodes = graph.querySelectorAll('[data-testid="graph-nodes"] g');
        expect(nodes).toHaveLength(2);
        const swhidNode = nodes[1];
        expect(swhidNode.querySelector('circle')).toHaveAttribute('fill', '#CA8A04');
        expect(swhidNode).toHaveTextContent('Lovelace');
        fireEvent.mouseEnter(swhidNode);
        fireEvent.mouseOver(swhidNode);
        expect(screen.getByTestId('relation-browser-tooltip')).toHaveTextContent(qualified);
        fireEvent.click(swhidNode);
        expect(open).toHaveBeenCalledWith(url, '_blank', 'noopener,noreferrer');
        open.mockRestore();
    });

    it('uses a color distinct from every other node, edge and fallback', () => {
        const swhid = getNodeColor('SWHID', false);
        const others = Object.entries(getNodeColorMap())
            .filter(([key]) => key !== 'SWHID')
            .map(([, color]) => color);
        expect([...others, ...Object.values(getEdgeCategoryColorMap()), GFZ_BLUE, NODE_FALLBACK_COLOR, EDGE_FALLBACK_COLOR]).not.toContain(swhid);
    });
});
