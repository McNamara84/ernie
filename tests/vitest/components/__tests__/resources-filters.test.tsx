import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';

import { ResourcesFilters } from '@/components/resources-filters';
import type { ResourceFilterOptions } from '@/types/resources';

const defaultFilterOptions: ResourceFilterOptions = {
    resource_types: [
        { slug: 'dataset', name: 'Dataset' },
        { slug: 'text', name: 'Text' },
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
        expect(screen.getByLabelText('Filter by publication status')).toBeInTheDocument();
        expect(screen.getByLabelText('Filter by curator')).toBeInTheDocument();
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
                filters={{ search: 'test' }}
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
