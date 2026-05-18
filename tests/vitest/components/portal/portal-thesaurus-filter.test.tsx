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
});