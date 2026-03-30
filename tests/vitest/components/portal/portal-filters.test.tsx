import '@testing-library/jest-dom/vitest';

import { fireEvent, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { PortalFilters } from '@/components/portal/PortalFilters';
import type { PortalFilters as PortalFiltersType } from '@/types/portal';

describe('PortalFilters', () => {
    const defaultFilters: PortalFiltersType = {
        query: '',
        type: [],
        keywords: [],
        bounds: null,
        temporal: null,
    };

    const defaultFacets = [
        { slug: 'dataset', name: 'Dataset', count: 42 },
        { slug: 'software', name: 'Software', count: 10 },
        { slug: 'physical-object', name: 'IGSN Samples', count: 5 },
    ];

    const defaultProps = {
        filters: defaultFilters,
        onSearchChange: vi.fn(),
        onTypeChange: vi.fn(),
        onKeywordsChange: vi.fn(),
        onClearFilters: vi.fn(),
        hasActiveFilters: false,
        isCollapsed: false,
        onToggleCollapse: vi.fn(),
        totalResults: 42,
        keywordSuggestions: [],
        geoFilterEnabled: false,
        onGeoFilterToggle: vi.fn(),
        onBoundsChange: vi.fn(),
        temporalRange: { Created: { min: 2000, max: 2024 } },
        temporalFilterEnabled: false,
        onTemporalFilterToggle: vi.fn(),
        onTemporalChange: vi.fn(),
        resourceTypeFacets: defaultFacets,
    };

    beforeEach(() => {
        vi.clearAllMocks();
    });

    describe('Expanded State', () => {
        it('renders search input and resource type filter button', () => {
            render(<PortalFilters {...defaultProps} />);

            expect(screen.getByPlaceholderText(/search datasets/i)).toBeInTheDocument();
            expect(screen.getByText(/42 results/i)).toBeInTheDocument();
            expect(screen.getByText(/all resource types/i)).toBeInTheDocument();
        });

        it('shows selected count when types are selected', () => {
            const filters: PortalFiltersType = { ...defaultFilters, type: ['dataset', 'software'] };
            render(<PortalFilters {...defaultProps} filters={filters} />);

            expect(screen.getByText(/2 selected/i)).toBeInTheDocument();
        });

        it('shows search input with current query value', () => {
            const filters: PortalFiltersType = { ...defaultFilters, query: 'climate data' };
            render(<PortalFilters {...defaultProps} filters={filters} />);

            const input = screen.getByPlaceholderText(/search datasets/i);
            expect(input).toHaveValue('climate data');
        });

        it('selects correct type filter based on filters prop', () => {
            const filters: PortalFiltersType = { ...defaultFilters, type: ['physical-object'] };
            render(<PortalFilters {...defaultProps} filters={filters} />);

            expect(screen.getByText(/1 selected/i)).toBeInTheDocument();
        });
    });

    describe('Search Interaction', () => {
        it('updates local state when typing in search input', async () => {
            const user = userEvent.setup();
            render(<PortalFilters {...defaultProps} />);

            const input = screen.getByPlaceholderText(/search datasets/i);
            await user.type(input, 'test query');

            expect(input).toHaveValue('test query');
        });

        it('calls onSearchChange when form is submitted', async () => {
            const onSearchChange = vi.fn();
            render(<PortalFilters {...defaultProps} onSearchChange={onSearchChange} />);

            const input = screen.getByPlaceholderText(/search datasets/i);
            fireEvent.change(input, { target: { value: 'submitted query' } });
            fireEvent.submit(input.closest('form')!);

            expect(onSearchChange).toHaveBeenCalledWith('submitted query');
        });

        it('shows clear button when search input has value and clears on click', async () => {
            const user = userEvent.setup();
            const onSearchChange = vi.fn();
            const filters: PortalFiltersType = { ...defaultFilters, query: 'existing query' };
            render(
                <PortalFilters
                    {...defaultProps}
                    filters={filters}
                    onSearchChange={onSearchChange}
                    hasActiveFilters={true}
                />,
            );

            // Find the clear X button next to search input (the one in the input container)
            const clearButton = screen.getByRole('button', { name: /clear search/i });
            await user.click(clearButton);

            expect(onSearchChange).toHaveBeenCalledWith('');
        });
    });

    describe('Type Filter Interaction', () => {
        it('renders resource type filter label', () => {
            render(<PortalFilters {...defaultProps} />);

            expect(screen.getByText('Resource Type')).toBeInTheDocument();
        });

        it('shows "All Resource Types" when no types selected', () => {
            render(<PortalFilters {...defaultProps} />);

            expect(screen.getByText(/all resource types/i)).toBeInTheDocument();
        });

        it('shows selected count badge when types are active', () => {
            const filters: PortalFiltersType = { ...defaultFilters, type: ['dataset'] };
            render(<PortalFilters {...defaultProps} filters={filters} />);

            expect(screen.getByText(/1 selected/i)).toBeInTheDocument();
        });
    });

    describe('Clear Filters', () => {
        it('shows clear filters button when hasActiveFilters is true', () => {
            render(<PortalFilters {...defaultProps} hasActiveFilters={true} />);

            expect(screen.getByRole('button', { name: /clear all/i })).toBeInTheDocument();
        });

        it('does not show clear filters button when hasActiveFilters is false', () => {
            render(<PortalFilters {...defaultProps} hasActiveFilters={false} />);

            expect(screen.queryByRole('button', { name: /clear all/i })).not.toBeInTheDocument();
        });

        it('calls onClearFilters when clear button is clicked', async () => {
            const user = userEvent.setup();
            const onClearFilters = vi.fn();
            render(<PortalFilters {...defaultProps} hasActiveFilters={true} onClearFilters={onClearFilters} />);

            const clearButton = screen.getByRole('button', { name: /clear all/i });
            await user.click(clearButton);

            expect(onClearFilters).toHaveBeenCalled();
        });
    });

    describe('Collapsed State', () => {
        it('renders collapsed sidebar with toggle button', () => {
            render(<PortalFilters {...defaultProps} isCollapsed={true} />);

            // In collapsed state, search input should not be visible
            expect(screen.queryByPlaceholderText(/search datasets/i)).not.toBeInTheDocument();

            // Toggle button should be visible
            expect(screen.getByRole('button', { name: /expand filters/i })).toBeInTheDocument();
        });

        it('calls onToggleCollapse when collapsed sidebar button is clicked', async () => {
            const user = userEvent.setup();
            const onToggleCollapse = vi.fn();
            render(<PortalFilters {...defaultProps} isCollapsed={true} onToggleCollapse={onToggleCollapse} />);

            const toggleButton = screen.getByRole('button', { name: /expand filters/i });
            await user.click(toggleButton);

            expect(onToggleCollapse).toHaveBeenCalled();
        });
    });

    describe('Collapse Toggle from Expanded', () => {
        it('renders collapse button in expanded state', () => {
            render(<PortalFilters {...defaultProps} isCollapsed={false} />);

            expect(screen.getByRole('button', { name: /collapse filters/i })).toBeInTheDocument();
        });

        it('calls onToggleCollapse when collapse button is clicked', async () => {
            const user = userEvent.setup();
            const onToggleCollapse = vi.fn();
            render(<PortalFilters {...defaultProps} isCollapsed={false} onToggleCollapse={onToggleCollapse} />);

            const collapseButton = screen.getByRole('button', { name: /collapse filters/i });
            await user.click(collapseButton);

            expect(onToggleCollapse).toHaveBeenCalled();
        });
    });

    describe('Result Count Display', () => {
        it('displays total results count', () => {
            render(<PortalFilters {...defaultProps} totalResults={1234} />);

            // Locale-aware number formatting (1,234 or 1.234 depending on locale)
            expect(screen.getByText(/1[,.]234 results/i)).toBeInTheDocument();
        });

        it('handles singular result count', () => {
            render(<PortalFilters {...defaultProps} totalResults={1} />);

            expect(screen.getByText(/1 result$/i)).toBeInTheDocument();
        });

        it('handles zero results', () => {
            render(<PortalFilters {...defaultProps} totalResults={0} />);

            expect(screen.getByText(/0 results/i)).toBeInTheDocument();
        });
    });

    describe('Filter State Sync', () => {
        it('syncs search input when filters.query changes externally', () => {
            const { rerender } = render(<PortalFilters {...defaultProps} />);

            // Initially empty
            expect(screen.getByPlaceholderText(/search datasets/i)).toHaveValue('');

            // Update filters externally
            const newFilters: PortalFiltersType = { ...defaultFilters, query: 'external update' };
            rerender(<PortalFilters {...defaultProps} filters={newFilters} />);

            expect(screen.getByPlaceholderText(/search datasets/i)).toHaveValue('external update');
        });
    });
});
