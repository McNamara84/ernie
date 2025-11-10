import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';

import { ResourcesFilters } from '@/components/resources-filters';

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

describe('ResourcesFilters', () => {
    const mockOnFilterChange = vi.fn();
    const defaultProps = {
        filters: {},
        onFilterChange: mockOnFilterChange,
        filterOptions: {
            resource_types: [
                { slug: 'dataset', name: 'Dataset' },
                { slug: 'collection', name: 'Collection' },
            ],
            statuses: ['curation', 'review', 'published'],
            curators: ['Alice', 'Bob'],
            year_range: { min: 2000, max: 2025 },
        },
        resultCount: 10,
        totalCount: 100,
        isLoading: false,
    };

    it('renders search input', () => {
        render(<ResourcesFilters {...defaultProps} />);
        expect(screen.getByPlaceholderText(/search title or doi/i)).toBeInTheDocument();
    });

    it('debounces search input with 1000ms delay', async () => {
        const user = userEvent.setup();
        render(<ResourcesFilters {...defaultProps} />);

        const searchInput = screen.getByPlaceholderText(/search title or doi/i);

        // Type first 3 characters (minimum length)
        await user.type(searchInput, 'test');

        // Should not trigger immediately
        expect(mockOnFilterChange).not.toHaveBeenCalled();

        // Wait for debounce delay (1000ms)
        await waitFor(
            () => {
                expect(mockOnFilterChange).toHaveBeenCalledTimes(1);
            },
            { timeout: 1500 }
        );

        // Check that the search filter was set correctly
        expect(mockOnFilterChange).toHaveBeenCalledWith(
            expect.objectContaining({
                search: 'test',
            })
        );
    });

    it('does not trigger search for input shorter than 3 characters', async () => {
        vi.clearAllMocks();
        const user = userEvent.setup();
        render(<ResourcesFilters {...defaultProps} />);

        const searchInput = screen.getByPlaceholderText(/search title or doi/i);

        // Type only 2 characters
        await user.clear(searchInput); // Ensure it's empty first
        await user.type(searchInput, 'ab'); // Only 2 chars

        // Wait longer than debounce delay
        await new Promise(resolve => setTimeout(resolve, 1200));

        // Should not trigger search (minimum is 3 characters)
        expect(mockOnFilterChange).not.toHaveBeenCalled();
    });

    it('clears search when input is empty', async () => {
        vi.clearAllMocks();
        const user = userEvent.setup();
        render(<ResourcesFilters {...defaultProps} filters={{ search: 'existing' }} />);

        const searchInput = screen.getByPlaceholderText(/search title or doi/i);

        // Clear the input
        await user.clear(searchInput);

        // Should trigger immediately (no debounce for clear) with last call being empty filter
        await waitFor(() => {
            expect(mockOnFilterChange).toHaveBeenCalled();
        });
        
        // Last call should clear the search
        const calls = mockOnFilterChange.mock.calls;
        const lastCall = calls[calls.length - 1][0];
        expect(lastCall).toEqual({});
    });

    it('restores focus to search input after filter change', async () => {
        const user = userEvent.setup();
        const { rerender } = render(<ResourcesFilters {...defaultProps} />);

        const searchInput = screen.getByPlaceholderText(/search title or doi/i) as HTMLInputElement;

        // Type to trigger search
        await user.type(searchInput, 'test');

        // Wait for debounce
        await waitFor(
            () => {
                expect(mockOnFilterChange).toHaveBeenCalled();
            },
            { timeout: 1500 }
        );

        // Simulate filter change (as would happen from parent component)
        rerender(<ResourcesFilters {...defaultProps} filters={{ search: 'test' }} isLoading={true} />);
        
        // Wait a bit for loading to finish
        await new Promise(resolve => setTimeout(resolve, 100));
        
        // Simulate loading finished
        rerender(<ResourcesFilters {...defaultProps} filters={{ search: 'test' }} isLoading={false} />);

        // Focus should be restored after loading completes
        await waitFor(() => {
            expect(document.activeElement).toBe(searchInput);
        }, { timeout: 300 });
    });

    it('displays result count correctly when filtered', () => {
        render(<ResourcesFilters {...defaultProps} resultCount={5} totalCount={100} />);
        
        expect(screen.getByText(/showing/i)).toBeInTheDocument();
        expect(screen.getByText('5')).toBeInTheDocument();
        expect(screen.getByText('100')).toBeInTheDocument();
    });

    it('shows clear all button when filters are active', () => {
        render(<ResourcesFilters {...defaultProps} filters={{ search: 'test', status: ['published'] }} />);
        
        expect(screen.getByText(/clear all/i)).toBeInTheDocument();
    });

    it('allows removing individual filter badges', async () => {
        const user = userEvent.setup();
        render(<ResourcesFilters {...defaultProps} filters={{ search: 'test', status: ['published'] }} />);
        
        // Find the X button on the search filter badge
        const badges = screen.getAllByRole('button', { name: /remove/i });
        expect(badges.length).toBeGreaterThan(0);
        
        // Click to remove
        await user.click(badges[0]);
        
        expect(mockOnFilterChange).toHaveBeenCalled();
    });

    it('disables filters when loading', () => {
        render(<ResourcesFilters {...defaultProps} isLoading={true} />);
        
        const searchInput = screen.getByPlaceholderText(/search title or doi/i);
        expect(searchInput).toBeDisabled();
    });
});
