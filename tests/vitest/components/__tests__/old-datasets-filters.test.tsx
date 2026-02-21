import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';

import { OldDatasetsFilters } from '@/components/old-datasets-filters';
import type { FilterOptions } from '@/types/old-datasets';

const defaultFilterOptions: FilterOptions = {
    resource_types: ['Dataset', 'Text', 'Software'],
    statuses: ['draft', 'registered', 'findable'],
    curators: ['Alice', 'Bob'],
    year_range: { min: 2015, max: 2025 },
};

describe('OldDatasetsFilters', () => {
    it('renders search input', () => {
        render(
            <OldDatasetsFilters
                filters={{}}
                onFilterChange={vi.fn()}
                filterOptions={defaultFilterOptions}
                resultCount={20}
                totalCount={20}
            />,
        );

        expect(screen.getByLabelText('Search datasets by title or DOI')).toBeInTheDocument();
    });

    it('renders filter selects', () => {
        render(
            <OldDatasetsFilters
                filters={{}}
                onFilterChange={vi.fn()}
                filterOptions={defaultFilterOptions}
                resultCount={20}
                totalCount={20}
            />,
        );

        expect(screen.getByLabelText('Filter by resource type')).toBeInTheDocument();
        expect(screen.getByLabelText('Filter by publication status')).toBeInTheDocument();
        expect(screen.getByLabelText('Filter by curator')).toBeInTheDocument();
    });

    it('shows filtered result count', () => {
        render(
            <OldDatasetsFilters
                filters={{ status: ['draft'] }}
                onFilterChange={vi.fn()}
                filterOptions={defaultFilterOptions}
                resultCount={8}
                totalCount={20}
            />,
        );

        expect(screen.getByText('8')).toBeInTheDocument();
        expect(screen.getByText('20')).toBeInTheDocument();
    });

    it('shows Clear All button with active filters', () => {
        render(
            <OldDatasetsFilters
                filters={{ search: 'rock' }}
                onFilterChange={vi.fn()}
                filterOptions={defaultFilterOptions}
                resultCount={5}
                totalCount={20}
            />,
        );

        expect(screen.getByText('Clear All')).toBeInTheDocument();
    });

    it('calls onFilterChange with empty object on Clear All', async () => {
        const user = userEvent.setup();
        const onFilterChange = vi.fn();

        render(
            <OldDatasetsFilters
                filters={{ search: 'rock' }}
                onFilterChange={onFilterChange}
                filterOptions={defaultFilterOptions}
                resultCount={5}
                totalCount={20}
            />,
        );

        await user.click(screen.getByText('Clear All'));
        expect(onFilterChange).toHaveBeenCalledWith({});
    });

    it('disables inputs when loading', () => {
        render(
            <OldDatasetsFilters
                filters={{}}
                onFilterChange={vi.fn()}
                filterOptions={defaultFilterOptions}
                resultCount={20}
                totalCount={20}
                isLoading
            />,
        );

        expect(screen.getByLabelText('Search datasets by title or DOI')).toBeDisabled();
    });

    it('shows active filter badges', () => {
        render(
            <OldDatasetsFilters
                filters={{ search: 'seismic', status: ['draft'] }}
                onFilterChange={vi.fn()}
                filterOptions={defaultFilterOptions}
                resultCount={3}
                totalCount={20}
            />,
        );

        expect(screen.getByText(/Search: seismic/)).toBeInTheDocument();
    });
});
