/**
 * @vitest-environment jsdom
 */
import '@testing-library/jest-dom/vitest';

import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { PortalThesaurusFilter } from '@/components/portal/PortalThesaurusFilter';
import type { PortalThesaurusFacet } from '@/types/portal';

const defaultFacets: PortalThesaurusFacet[] = [
    {
        scheme: 'Science Keywords',
        roots: [
            {
                id: 'earth-science',
                text: 'EARTH SCIENCE',
                language: 'en',
                scheme: 'Science Keywords',
                schemeURI: 'https://example.test/science',
                description: '',
                children: [
                    {
                        id: 'solid-earth',
                        text: 'SOLID EARTH',
                        language: 'en',
                        scheme: 'Science Keywords',
                        schemeURI: 'https://example.test/science',
                        description: '',
                        children: [],
                    },
                ],
            },
        ],
    },
];

const nestedFacets: PortalThesaurusFacet[] = [
    {
        scheme: 'Science Keywords',
        roots: [
            {
                id: 'earth-science',
                text: 'EARTH SCIENCE',
                language: 'en',
                scheme: 'Science Keywords',
                schemeURI: 'https://example.test/science',
                description: '',
                children: [
                    {
                        id: 'solid-earth',
                        text: 'SOLID EARTH',
                        language: 'en',
                        scheme: 'Science Keywords',
                        schemeURI: 'https://example.test/science',
                        description: '',
                        children: [
                            {
                                id: 'bedrock',
                                text: 'BEDROCK',
                                language: 'en',
                                scheme: 'Science Keywords',
                                schemeURI: 'https://example.test/science',
                                description: '',
                                children: [],
                            },
                        ],
                    },
                ],
            },
        ],
    },
];

describe('PortalThesaurusFilter', () => {
    const defaultProps = {
        facets: defaultFacets,
        selectedNodeIds: [] as string[],
        onSelectionChange: vi.fn(),
    };

    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('renders the thesaurus label and scheme section', () => {
        render(<PortalThesaurusFilter {...defaultProps} />);

        expect(screen.getByText('Thesaurus Keywords')).toBeInTheDocument();
        expect(screen.getByText('GCMD Science Keywords')).toBeInTheDocument();
        expect(screen.getByText('EARTH SCIENCE')).toBeInTheDocument();
    });

    it('renders an empty state when no facets are available', () => {
        render(<PortalThesaurusFilter facets={[]} selectedNodeIds={[]} onSelectionChange={vi.fn()} />);

        expect(screen.getByText('No thesaurus keywords available.')).toBeInTheDocument();
    });

    it('calls onSelectionChange when a node checkbox is selected', async () => {
        const user = userEvent.setup();
        const onSelectionChange = vi.fn();

        render(
            <PortalThesaurusFilter
                {...defaultProps}
                onSelectionChange={onSelectionChange}
            />,
        );

        await user.click(screen.getByLabelText('Select thesaurus keyword EARTH SCIENCE'));

        expect(onSelectionChange).toHaveBeenCalledWith(['earth-science']);
    });

    it('removes a selected node through the chip action', async () => {
        const user = userEvent.setup();
        const onSelectionChange = vi.fn();

        render(
            <PortalThesaurusFilter
                {...defaultProps}
                selectedNodeIds={['earth-science']}
                onSelectionChange={onSelectionChange}
            />,
        );

        await user.click(screen.getByLabelText('Remove thesaurus keyword EARTH SCIENCE'));

        expect(onSelectionChange).toHaveBeenCalledWith([]);
    });

    it('toggles nested branches open and closed', async () => {
        const user = userEvent.setup();

        render(
            <PortalThesaurusFilter
                facets={nestedFacets}
                selectedNodeIds={[]}
                onSelectionChange={vi.fn()}
            />,
        );

        expect(screen.queryByText('BEDROCK')).not.toBeInTheDocument();

        await user.click(screen.getByRole('button', { name: 'Expand SOLID EARTH' }));
        expect(screen.getByText('BEDROCK')).toBeInTheDocument();

        await user.click(screen.getByRole('button', { name: 'Collapse SOLID EARTH' }));
        expect(screen.queryByText('BEDROCK')).not.toBeInTheDocument();
    });

    it('exposes tree semantics with hierarchy and expanded state metadata', async () => {
        const user = userEvent.setup();

        render(
            <PortalThesaurusFilter
                facets={nestedFacets}
                selectedNodeIds={['bedrock']}
                onSelectionChange={vi.fn()}
            />,
        );

        const tree = screen.getByRole('tree', { name: 'GCMD Science Keywords thesaurus hierarchy' });
        expect(tree).toBeInTheDocument();

        const earthScienceItem = screen.getByRole('button', { name: 'EARTH SCIENCE' }).closest('[role="treeitem"]');
        const solidEarthItem = screen.getByRole('button', { name: 'SOLID EARTH' }).closest('[role="treeitem"]');
        const bedrockItem = screen.getByRole('button', { name: 'BEDROCK' }).closest('[role="treeitem"]');

        expect(earthScienceItem).toHaveAttribute('aria-level', '1');
        expect(earthScienceItem).toHaveAttribute('aria-expanded', 'true');
        expect(solidEarthItem).toHaveAttribute('aria-level', '2');
        expect(solidEarthItem).toHaveAttribute('aria-expanded', 'true');
        expect(bedrockItem).toHaveAttribute('aria-level', '3');
        expect(bedrockItem).toHaveAttribute('aria-selected', 'true');

        await user.click(screen.getByRole('button', { name: 'Collapse SOLID EARTH' }));

        expect(solidEarthItem).toHaveAttribute('aria-expanded', 'false');
    });

    it('auto-expands ancestors of selected descendants and shows the selected count', () => {
        render(
            <PortalThesaurusFilter
                facets={nestedFacets}
                selectedNodeIds={['bedrock']}
                onSelectionChange={vi.fn()}
            />,
        );

        expect(screen.getByRole('button', { name: 'BEDROCK' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Collapse SOLID EARTH' })).toBeInTheDocument();
        expect(screen.getByText('1 selected')).toBeInTheDocument();
    });

    it('toggles a node when its label button is clicked', async () => {
        const user = userEvent.setup();
        const onSelectionChange = vi.fn();

        render(
            <PortalThesaurusFilter
                {...defaultProps}
                onSelectionChange={onSelectionChange}
            />,
        );

        await user.click(screen.getByRole('button', { name: 'EARTH SCIENCE' }));

        expect(onSelectionChange).toHaveBeenCalledWith(['earth-science']);
    });

    it('falls back to the raw node id when a selected label is missing from the tree', () => {
        render(
            <PortalThesaurusFilter
                {...defaultProps}
                selectedNodeIds={['missing-node']}
            />,
        );

        expect(screen.getByText('missing-node')).toBeInTheDocument();
        expect(screen.getByLabelText('Remove thesaurus keyword missing-node')).toBeInTheDocument();
    });

    it('supports omitted optional props without crashing', async () => {
        const user = userEvent.setup();

        render(<PortalThesaurusFilter facets={defaultFacets} />);

        await user.click(screen.getByRole('button', { name: 'EARTH SCIENCE' }));

        expect(screen.getByText('Thesaurus Keywords')).toBeInTheDocument();
    });
});