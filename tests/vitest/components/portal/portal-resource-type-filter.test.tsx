import '@testing-library/jest-dom/vitest';

import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { PortalResourceTypeFilter } from '@/components/portal/PortalResourceTypeFilter';
import type { ResourceTypeFacet } from '@/types/portal';

describe('PortalResourceTypeFilter', () => {
    const facets: ResourceTypeFacet[] = [
        { slug: 'dataset', name: 'Dataset', count: 42 },
        { slug: 'software', name: 'Software', count: 10 },
        { slug: 'physical-object', name: 'Physical Object', count: 5 },
    ];

    let onSelectionChange: ReturnType<typeof vi.fn>;

    beforeEach(() => {
        onSelectionChange = vi.fn();
    });

    describe('trigger text', () => {
        it('shows "All Resource Types" when nothing is selected', () => {
            render(<PortalResourceTypeFilter facets={facets} selectedSlugs={[]} onSelectionChange={onSelectionChange} />);

            expect(screen.getByRole('button', { name: /all resource types/i })).toBeInTheDocument();
        });

        it('shows selected count when slugs are selected', () => {
            render(
                <PortalResourceTypeFilter
                    facets={facets}
                    selectedSlugs={['dataset', 'software']}
                    onSelectionChange={onSelectionChange}
                />,
            );

            expect(screen.getByText(/2 selected/i)).toBeInTheDocument();
        });

        it('shows "All except" label for legacy DOI exclusion', () => {
            render(
                <PortalResourceTypeFilter
                    facets={facets}
                    selectedSlugs={[]}
                    excludeType="physical-object"
                    onSelectionChange={onSelectionChange}
                />,
            );

            expect(screen.getByText(/all except physical object/i)).toBeInTheDocument();
        });

        it('shows selected count instead of exclude label when slugs are explicitly selected', () => {
            render(
                <PortalResourceTypeFilter
                    facets={facets}
                    selectedSlugs={['dataset']}
                    excludeType="physical-object"
                    onSelectionChange={onSelectionChange}
                />,
            );

            expect(screen.getByText(/1 selected/i)).toBeInTheDocument();
            expect(screen.queryByText(/all except/i)).not.toBeInTheDocument();
        });
    });

    describe('popover interaction', () => {
        it('opens popover and shows all facets', async () => {
            const user = userEvent.setup();
            render(<PortalResourceTypeFilter facets={facets} selectedSlugs={[]} onSelectionChange={onSelectionChange} />);

            await user.click(screen.getByRole('button', { name: /all resource types/i }));

            expect(screen.getByText('Dataset')).toBeInTheDocument();
            expect(screen.getByText('Software')).toBeInTheDocument();
            expect(screen.getByText('Physical Object')).toBeInTheDocument();
        });

        it('calls onSelectionChange with slug when a facet is selected', async () => {
            const user = userEvent.setup();
            render(<PortalResourceTypeFilter facets={facets} selectedSlugs={[]} onSelectionChange={onSelectionChange} />);

            await user.click(screen.getByRole('button', { name: /all resource types/i }));
            await user.click(screen.getByRole('option', { name: /dataset/i }));

            expect(onSelectionChange).toHaveBeenCalledWith(['dataset']);
        });

        it('calls onSelectionChange without slug when a selected facet is toggled off', async () => {
            const user = userEvent.setup();
            render(
                <PortalResourceTypeFilter
                    facets={facets}
                    selectedSlugs={['dataset', 'software']}
                    onSelectionChange={onSelectionChange}
                />,
            );

            await user.click(screen.getByText(/2 selected/i));
            await user.click(screen.getByRole('option', { name: /dataset/i }));

            expect(onSelectionChange).toHaveBeenCalledWith(['software']);
        });

        it('shows facet counts', async () => {
            const user = userEvent.setup();
            render(<PortalResourceTypeFilter facets={facets} selectedSlugs={[]} onSelectionChange={onSelectionChange} />);

            await user.click(screen.getByRole('button', { name: /all resource types/i }));

            expect(screen.getByText('42')).toBeInTheDocument();
            expect(screen.getByText('10')).toBeInTheDocument();
            expect(screen.getByText('5')).toBeInTheDocument();
        });
    });

    describe('clear filter', () => {
        it('shows clear button when items are selected and emits empty array', async () => {
            const user = userEvent.setup();
            render(
                <PortalResourceTypeFilter
                    facets={facets}
                    selectedSlugs={['dataset']}
                    onSelectionChange={onSelectionChange}
                />,
            );

            // Open popover
            await user.click(screen.getByText(/1 selected/i));

            const clearButton = screen.getByRole('button', { name: /clear filter/i });
            await user.click(clearButton);

            expect(onSelectionChange).toHaveBeenCalledWith([]);
        });

        it('shows clear button with exclude info for legacy DOI mode', async () => {
            const user = userEvent.setup();
            render(
                <PortalResourceTypeFilter
                    facets={facets}
                    selectedSlugs={[]}
                    excludeType="physical-object"
                    onSelectionChange={onSelectionChange}
                />,
            );

            await user.click(screen.getByText(/all except physical object/i));

            expect(screen.getByText(/excluding: physical object/i)).toBeInTheDocument();

            const clearButton = screen.getByRole('button', { name: /clear filter/i });
            await user.click(clearButton);

            expect(onSelectionChange).toHaveBeenCalledWith([]);
        });

        it('does not show clear button when no selection and no exclude', async () => {
            const user = userEvent.setup();
            render(<PortalResourceTypeFilter facets={facets} selectedSlugs={[]} onSelectionChange={onSelectionChange} />);

            await user.click(screen.getByRole('button', { name: /all resource types/i }));

            expect(screen.queryByRole('button', { name: /clear filter/i })).not.toBeInTheDocument();
        });
    });
});
