import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { OldDatasetsFilters } from '@/components/old-datasets-filters';

// Mock Radix UI Popover to avoid portal issues
vi.mock('@/components/ui/popover', () => ({
    Popover: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
    PopoverTrigger: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
    PopoverContent: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
}));

// Mock Radix UI Select to avoid portal issues
vi.mock('@/components/ui/select', () => ({
    Select: ({ children, onValueChange, value }: { children: React.ReactNode; onValueChange: (value: string) => void; value: string }) => (
        <select onChange={(e) => onValueChange(e.target.value)} value={value}>
            {children}
        </select>
    ),
    SelectContent: ({ children }: { children: React.ReactNode }) => <>{children}</>,
    SelectItem: ({ children, value }: { children: React.ReactNode; value: string }) => <option value={value}>{children}</option>,
    SelectTrigger: ({ children }: { children: React.ReactNode }) => <>{children}</>,
    SelectValue: () => null,
}));

describe('OldDatasetsFilters', () => {
    const mockOnFilterChange = vi.fn();

    beforeEach(() => {
        vi.clearAllMocks();
    });
    const defaultProps = {
        filters: {},
        onFilterChange: mockOnFilterChange,
        filterOptions: {
            resource_types: ['Dataset', 'Collection', 'Software'],
            statuses: ['published', 'draft', 'review', 'archived'],
            curators: ['Alice', 'Bob', 'Charlie'],
            year_range: { min: 2000, max: 2025 },
        },
        resultCount: 15,
        totalCount: 150,
        isLoading: false,
    };

    it('renders search input', () => {
        render(<OldDatasetsFilters {...defaultProps} />);
        expect(screen.getByPlaceholderText(/search title or doi/i)).toBeInTheDocument();
    });

    it('debounces search input with 1000ms delay', async () => {
        const user = userEvent.setup();
        render(<OldDatasetsFilters {...defaultProps} />);

        const searchInput = screen.getByPlaceholderText(/search title or doi/i);

        // Type first 3 characters (minimum length)
        await user.type(searchInput, 'abc');

        // Should not trigger immediately
        expect(mockOnFilterChange).not.toHaveBeenCalled();

        // Wait for debounce delay (1000ms)
        await new Promise(resolve => setTimeout(resolve, 1100));

        // Should have been called after debounce
        expect(mockOnFilterChange).toHaveBeenCalledTimes(1);

        // Check that the search filter was set correctly
        expect(mockOnFilterChange).toHaveBeenCalledWith(
            expect.objectContaining({
                search: 'abc',
            })
        );
    }, 15000);

    it('does not trigger search for input shorter than 3 characters', async () => {
        const user = userEvent.setup();
        render(<OldDatasetsFilters {...defaultProps} />);

        const searchInput = screen.getByPlaceholderText(/search title or doi/i);

        // Type only 2 characters
        await user.clear(searchInput); // Ensure it's empty first
        await user.type(searchInput, 'xy'); // Only 2 chars

        // Wait beyond debounce delay
        await new Promise(resolve => setTimeout(resolve, 1200));

        // Should not trigger search (minimum is 3 characters)
        expect(mockOnFilterChange).not.toHaveBeenCalled();
    }, 15000);

    it('clears search when input is empty', async () => {
        const user = userEvent.setup();
        render(<OldDatasetsFilters {...defaultProps} filters={{ search: 'previous' }} />);

        const searchInput = screen.getByPlaceholderText(/search title or doi/i);

        // Clear the input
        await user.clear(searchInput);

        // Wait for debounce
        await new Promise(resolve => setTimeout(resolve, 1100));
        
        // Last call should clear the search
        const calls = mockOnFilterChange.mock.calls;
        const lastCall = calls[calls.length - 1][0];
        expect(lastCall).toEqual({});
    }, 15000);

    it('restores focus to search input after filter change', async () => {
        const user = userEvent.setup();
        const { rerender } = render(<OldDatasetsFilters {...defaultProps} />);

        const searchInput = screen.getByPlaceholderText(/search title or doi/i) as HTMLInputElement;

        // Type to trigger search
        await user.type(searchInput, 'xyz');

        // Wait for debounce
        await new Promise(resolve => setTimeout(resolve, 1100));

        // Verify debounce callback was called
        expect(mockOnFilterChange).toHaveBeenCalled();

        // Simulate filter change (as would happen from parent component)
        rerender(<OldDatasetsFilters {...defaultProps} filters={{ search: 'xyz' }} isLoading={true} />);
        
        // Simulate loading finished
        rerender(<OldDatasetsFilters {...defaultProps} filters={{ search: 'xyz' }} isLoading={false} />);

        // Wait for focus restoration (100ms delay)
        await new Promise(resolve => setTimeout(resolve, 150));

        // Focus should be restored after loading completes
        expect(document.activeElement).toBe(searchInput);
    }, 15000);

    it('displays result count correctly when filtered', () => {
        render(<OldDatasetsFilters {...defaultProps} resultCount={10} totalCount={150} />);
        
        expect(screen.getByText(/showing/i)).toBeInTheDocument();
        expect(screen.getByText('10')).toBeInTheDocument();
        expect(screen.getByText('150')).toBeInTheDocument();
    });

    it('shows clear all button when filters are active', () => {
        render(<OldDatasetsFilters {...defaultProps} filters={{ search: 'xyz', curator: ['Alice'] }} />);
        
        expect(screen.getByText(/clear all/i)).toBeInTheDocument();
    });

    it('allows removing individual filter badges', async () => {
        const user = userEvent.setup();
        render(<OldDatasetsFilters {...defaultProps} filters={{ search: 'test', status: ['published'] }} />);
        
        // Find the X button on the filter badge
        const badges = screen.getAllByRole('button', { name: /remove/i });
        expect(badges.length).toBeGreaterThan(0);
        
        // Click to remove
        await user.click(badges[0]);
        
        expect(mockOnFilterChange).toHaveBeenCalled();
    }, 15000);

    it('disables filters when loading', () => {
        render(<OldDatasetsFilters {...defaultProps} isLoading={true} />);
        
        const searchInput = screen.getByPlaceholderText(/search title or doi/i);
        expect(searchInput).toBeDisabled();
    });
});
