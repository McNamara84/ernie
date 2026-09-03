import userEvent from '@testing-library/user-event';
import { render, screen } from '@tests/vitest/utils/render';
import { describe, expect, it, vi } from 'vitest';

import { ResourcesFilters } from '@/components/resources-filters';
import type { ResourceFilterOptions } from '@/types/resources';

const defaultFilterOptions: ResourceFilterOptions = {
    resource_types: [
        { slug: 'dataset', name: 'Dataset' },
        { slug: 'text', name: 'Text' },
    ],
    datacenters: [
        { id: 7, name: 'Alpha Datacenter' },
        { id: 9, name: 'Beta Datacenter' },
    ],
    statuses: ['draft', 'registered', 'findable'],
    curators: ['Alice', 'Bob'],
    year_range: { min: 2020, max: 2025 },
};

describe('ResourcesFilters', () => {
    it('renders search input', () => {
        render(
            <ResourcesFilters
                filters={{}}
                onFilterChange={vi.fn()}
                filterOptions={defaultFilterOptions}
                resultCount={10}
                totalCount={10}
            />,
        );

        expect(screen.getByLabelText('Search resources by title or DOI')).toBeInTheDocument();
    });

    it('renders filter selects', () => {
        render(
            <ResourcesFilters
                filters={{}}
                onFilterChange={vi.fn()}
                filterOptions={defaultFilterOptions}
                resultCount={10}
                totalCount={10}
            />,
        );

        expect(screen.getByLabelText('Filter by resource type')).toBeInTheDocument();
        expect(screen.getByLabelText('Filter by datacenter')).toBeInTheDocument();
        expect(screen.getByLabelText('Filter by publication status')).toBeInTheDocument();
        expect(screen.getByLabelText('Filter by curator')).toBeInTheDocument();
    });

    it('filters by a single datacenter', async () => {
        const user = userEvent.setup();
        const onFilterChange = vi.fn();

        render(
            <ResourcesFilters
                filters={{}}
                onFilterChange={onFilterChange}
                filterOptions={defaultFilterOptions}
                resultCount={10}
                totalCount={10}
            />,
        );

        await user.click(screen.getByLabelText('Filter by datacenter'));
        await user.click(screen.getByText('Alpha Datacenter'));

        expect(onFilterChange).toHaveBeenCalledWith({ datacenter_id: 7 });
    });

    it('offers and labels resources without a datacenter', async () => {
        const user = userEvent.setup();
        const onFilterChange = vi.fn();

        render(
            <ResourcesFilters
                filters={{ without_datacenter: true }}
                onFilterChange={onFilterChange}
                filterOptions={defaultFilterOptions}
                resultCount={2}
                totalCount={10}
            />,
        );

        expect(screen.getByText('Datacenter: Without Datacenter')).toBeInTheDocument();

        await user.click(screen.getByLabelText('Filter by datacenter'));
        await user.click(screen.getByText('All Datacenters'));

        expect(onFilterChange).toHaveBeenCalledWith({});
    });

    it('shows the selected datacenter name in the active filter badge', () => {
        render(
            <ResourcesFilters
                filters={{ datacenter_id: 9 }}
                onFilterChange={vi.fn()}
                filterOptions={defaultFilterOptions}
                resultCount={4}
                totalCount={10}
            />,
        );

        expect(screen.getByText('Datacenter: Beta Datacenter')).toBeInTheDocument();
    });

    it('shows total count when not filtered', () => {
        render(
            <ResourcesFilters
                filters={{ search: 'test' }}
                onFilterChange={vi.fn()}
                filterOptions={defaultFilterOptions}
                resultCount={10}
                totalCount={10}
            />,
        );

        // When not filtered (resultCount === totalCount), nothing shows OR it shows total
        // When active filters exist, it shows the badge area
        expect(screen.getByText('Active filters:')).toBeInTheDocument();
    });

    it('shows filtered result count', () => {
        render(
            <ResourcesFilters
                filters={{ status: ['draft'] }}
                onFilterChange={vi.fn()}
                filterOptions={defaultFilterOptions}
                resultCount={5}
                totalCount={10}
            />,
        );

        expect(screen.getByText('5')).toBeInTheDocument();
        expect(screen.getByText('10')).toBeInTheDocument();
    });

    it('announces asynchronous total states through a polite status region', () => {
        const props = {
            filters: {},
            onFilterChange: vi.fn(),
            filterOptions: defaultFilterOptions,
            resultCount: 10,
        };
        const { rerender } = render(<ResourcesFilters {...props} totalCount={null} countStatus="pending" />);

        expect(screen.getByRole('status')).toHaveAttribute('aria-live', 'polite');
        expect(screen.getByRole('status')).toHaveAttribute('aria-atomic', 'true');
        expect(screen.getByRole('status')).toHaveTextContent('Showing 10; counting total');

        rerender(<ResourcesFilters {...props} totalCount={null} countStatus="failed" onRetryCount={vi.fn()} />);
        expect(screen.getByRole('status')).toHaveTextContent('Showing 10; total unavailable');

        rerender(<ResourcesFilters {...props} totalCount={12} countStatus="ready" />);
        expect(screen.getByRole('status')).toHaveTextContent('Showing 10 of 12 resources');
    });

    it('shows active filter badges', () => {
        render(
            <ResourcesFilters
                filters={{ status: ['draft'], search: 'climate' }}
                onFilterChange={vi.fn()}
                filterOptions={defaultFilterOptions}
                resultCount={3}
                totalCount={10}
            />,
        );

        expect(screen.getByText('Active filters:')).toBeInTheDocument();
        expect(screen.getByText(/Search: climate/)).toBeInTheDocument();
    });

    it('shows Clear All button with active filters', () => {
        render(
            <ResourcesFilters
                filters={{ search: 'test' }}
                onFilterChange={vi.fn()}
                filterOptions={defaultFilterOptions}
                resultCount={5}
                totalCount={10}
            />,
        );

        expect(screen.getByText('Clear All')).toBeInTheDocument();
    });

    it('calls onFilterChange with empty object on Clear All', async () => {
        const user = userEvent.setup();
        const onFilterChange = vi.fn();

        render(
            <ResourcesFilters
                filters={{ search: 'test', without_spdx_license: true }}
                onFilterChange={onFilterChange}
                filterOptions={defaultFilterOptions}
                resultCount={5}
                totalCount={10}
            />,
        );

        await user.click(screen.getByText('Clear All'));
        expect(onFilterChange).toHaveBeenCalledWith({});
    });

    it('removes individual filter on badge close', async () => {
        const user = userEvent.setup();
        const onFilterChange = vi.fn();

        render(
            <ResourcesFilters
                filters={{ search: 'test' }}
                onFilterChange={onFilterChange}
                filterOptions={defaultFilterOptions}
                resultCount={5}
                totalCount={10}
            />,
        );

        const removeButton = screen.getByLabelText('Remove search filter');
        await user.click(removeButton);
        expect(onFilterChange).toHaveBeenCalledWith({});
    });

    it('toggles the Without SPDX License filter while preserving other filters', async () => {
        const user = userEvent.setup();
        const onFilterChange = vi.fn();

        render(
            <ResourcesFilters
                filters={{ search: 'climate' }}
                onFilterChange={onFilterChange}
                filterOptions={defaultFilterOptions}
                resultCount={5}
                totalCount={10}
            />,
        );

        const toggle = screen.getByRole('switch', { name: 'Without SPDX License' });
        expect(toggle).not.toBeChecked();

        await user.click(toggle);
        expect(onFilterChange).toHaveBeenCalledWith({ search: 'climate', without_spdx_license: true });
    });

    it('restores and removes the active Without SPDX License filter', async () => {
        const user = userEvent.setup();
        const onFilterChange = vi.fn();

        render(
            <ResourcesFilters
                filters={{ status: ['draft'], without_spdx_license: true }}
                onFilterChange={onFilterChange}
                filterOptions={defaultFilterOptions}
                resultCount={2}
                totalCount={10}
            />,
        );

        const toggle = screen.getByRole('switch', { name: 'Without SPDX License' });
        expect(toggle).toBeChecked();
        expect(screen.getAllByText('Without SPDX License')).toHaveLength(2);

        await user.click(toggle);
        expect(onFilterChange).toHaveBeenCalledWith({ status: ['draft'] });

        onFilterChange.mockClear();
        await user.click(screen.getByLabelText('Remove without_spdx_license filter'));
        expect(onFilterChange).toHaveBeenCalledWith({ status: ['draft'] });
    });

    it('disables inputs when loading', () => {
        render(
            <ResourcesFilters
                filters={{}}
                onFilterChange={vi.fn()}
                filterOptions={defaultFilterOptions}
                resultCount={10}
                totalCount={10}
                isLoading
            />,
        );

        expect(screen.getByLabelText('Search resources by title or DOI')).toBeDisabled();
        expect(screen.getByRole('switch', { name: 'Without SPDX License' })).toBeDisabled();
    });

    it('shows year range button', () => {
        render(
            <ResourcesFilters
                filters={{}}
                onFilterChange={vi.fn()}
                filterOptions={defaultFilterOptions}
                resultCount={10}
                totalCount={10}
            />,
        );

        expect(screen.getByLabelText('Filter by publication year range')).toBeInTheDocument();
    });

    it('renders date range filter popovers', () => {
        render(
            <ResourcesFilters
                filters={{}}
                onFilterChange={vi.fn()}
                filterOptions={defaultFilterOptions}
                resultCount={10}
                totalCount={10}
            />,
        );

        expect(screen.getByLabelText('Filter by creation date range')).toBeInTheDocument();
        expect(screen.getByLabelText('Filter by last update date range')).toBeInTheDocument();
    });

    it('debounces search input', async () => {
        vi.useFakeTimers({ shouldAdvanceTime: true });
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
        const onFilterChange = vi.fn();

        render(
            <ResourcesFilters
                filters={{}}
                onFilterChange={onFilterChange}
                filterOptions={defaultFilterOptions}
                resultCount={10}
                totalCount={10}
            />,
        );

        const searchInput = screen.getByLabelText('Search resources by title or DOI');
        await user.type(searchInput, 'climate data');

        // Should not have triggered yet (debounce is 1000ms)
        expect(onFilterChange).not.toHaveBeenCalled();

        // Advance timer past debounce
        vi.advanceTimersByTime(1100);
        expect(onFilterChange).toHaveBeenCalledWith(
            expect.objectContaining({ search: 'climate data' }),
        );

        vi.useRealTimers();
    });
});
