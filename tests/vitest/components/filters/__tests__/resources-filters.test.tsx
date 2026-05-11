import { fireEvent, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';

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

describe('ResourcesFilters Component', () => {
    const mockOnFilterChange = vi.fn();

    beforeEach(() => {
        vi.clearAllMocks();
    });

    // Note: We use real timers with setTimeout instead of vi.useFakeTimers() because:
    // 1. userEvent.type() internally uses timers that conflict with fake timers
    // 2. Fake timers caused timeouts and "Failed to execute 'dispatchEvent'" errors
    // 3. Real timers with increased timeout (15s) provide stable, reliable tests
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
        await new Promise(resolve => setTimeout(resolve, 1100));

        // Should have been called after debounce
        expect(mockOnFilterChange).toHaveBeenCalledTimes(1);

        // Check that the search filter was set correctly
        expect(mockOnFilterChange).toHaveBeenCalledWith(
            expect.objectContaining({
                search: 'test',
            })
        );
    }, 15000);

    it('does not trigger search for input shorter than 3 characters', async () => {
        const user = userEvent.setup();
        render(<ResourcesFilters {...defaultProps} />);

        const searchInput = screen.getByPlaceholderText(/search title or doi/i);

        // Type only 2 characters
        await user.clear(searchInput); // Ensure it's empty first
        await user.type(searchInput, 'ab'); // Only 2 chars

        // Wait beyond debounce delay
        await new Promise(resolve => setTimeout(resolve, 1200));

        // Should not trigger search (minimum is 3 characters)
        expect(mockOnFilterChange).not.toHaveBeenCalled();
    }, 15000);

    it('clears search when input is empty', async () => {
        const user = userEvent.setup();
        render(<ResourcesFilters {...defaultProps} filters={{ search: 'existing' }} />);

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
        const { rerender } = render(<ResourcesFilters {...defaultProps} />);

        const searchInput = screen.getByPlaceholderText(/search title or doi/i) as HTMLInputElement;

        // Type to trigger search
        await user.type(searchInput, 'test');

        // Wait for debounce
        await new Promise(resolve => setTimeout(resolve, 1100));

        // Verify debounce callback was called
        expect(mockOnFilterChange).toHaveBeenCalled();

        // Simulate filter change (as would happen from parent component)
        rerender(<ResourcesFilters {...defaultProps} filters={{ search: 'test' }} isLoading={true} />);
        
        // Simulate loading finished
        rerender(<ResourcesFilters {...defaultProps} filters={{ search: 'test' }} isLoading={false} />);

        // Wait for focus restoration (100ms delay)
        await new Promise(resolve => setTimeout(resolve, 150));

        // Focus should be restored after loading completes
        expect(document.activeElement).toBe(searchInput);
    }, 15000);

    it('displays result count correctly when filtered', () => {
        render(<ResourcesFilters {...defaultProps} resultCount={5} totalCount={100} />);
        
        // The result count shows up when resultCount !== totalCount
        // Look for the span containing "Showing" as direct text
        const spans = screen.getAllByText(/Showing/);
        const resultSpan = spans.find((el) => el.textContent === 'Showing 5 of 100 resources');
        expect(resultSpan).toBeInTheDocument();
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
    }, 15000);

            it('buffers year range changes until Apply is clicked', async () => {
                const user = userEvent.setup();
                render(<ResourcesFilters {...defaultProps} />);

                await user.type(screen.getByLabelText('From Year'), '2021');
                await user.type(screen.getByLabelText('To Year'), '2024');

                expect(mockOnFilterChange).not.toHaveBeenCalled();

                await user.click(screen.getByRole('button', { name: 'Apply' }));

                expect(mockOnFilterChange).toHaveBeenCalledWith({ year_from: 2021, year_to: 2024 });
            }, 15000);

            it('does not emit changes when Apply is triggered without pending year edits', async () => {
                render(<ResourcesFilters {...defaultProps} filters={{ year_from: 2021, year_to: 2024 }} />);

                const applyButton = screen.getByRole('button', { name: 'Apply' });
                expect(applyButton).toBeDisabled();
            }, 15000);

            it('does not emit changes when Apply is triggered with only invalid year edits', async () => {
                const user = userEvent.setup();
                render(<ResourcesFilters {...defaultProps} filters={{ status: ['published'] }} />);

                fireEvent.change(screen.getByLabelText('From Year'), { target: { value: '1999' } });
                await user.click(screen.getByRole('button', { name: 'Apply' }));

                expect(mockOnFilterChange).not.toHaveBeenCalled();
            }, 15000);

            it('renders resource type badges with the display name instead of the slug', () => {
                render(<ResourcesFilters {...defaultProps} filters={{ resource_type: ['dataset'] }} />);

                expect(screen.getByText('Type: Dataset')).toBeInTheDocument();
            });

            it('disables year range controls when filter options are unavailable', () => {
                render(<ResourcesFilters {...defaultProps} filterOptions={null} />);

                expect(screen.getByLabelText('Filter by publication year range')).toBeDisabled();
                expect(screen.getByLabelText('From Year')).toBeDisabled();
                expect(screen.getByLabelText('To Year')).toBeDisabled();
                expect(screen.getByRole('button', { name: 'Apply' })).toBeDisabled();
            });

            it('shows year placeholders from the backend bounds', () => {
                render(<ResourcesFilters {...defaultProps} />);

                expect(screen.getByLabelText('From Year')).toHaveAttribute('placeholder', '2000');
                expect(screen.getByLabelText('To Year')).toHaveAttribute('placeholder', '2025');
            });

            it('removes year_from on Apply when the draft value is cleared', async () => {
                const user = userEvent.setup();
                render(<ResourcesFilters {...defaultProps} filters={{ status: ['published'], year_from: 2021, year_to: 2024 }} />);

                await user.clear(screen.getByLabelText('From Year'));
                await user.click(screen.getByRole('button', { name: 'Apply' }));

                expect(mockOnFilterChange).toHaveBeenCalledWith({ status: ['published'], year_to: 2024 });
            }, 15000);

            it('removes year_to on Apply when the draft value is invalid', async () => {
                const user = userEvent.setup();
                render(<ResourcesFilters {...defaultProps} filters={{ status: ['published'], year_from: 2021, year_to: 2024 }} />);

                fireEvent.change(screen.getByLabelText('To Year'), { target: { value: '0' } });
                await user.click(screen.getByRole('button', { name: 'Apply' }));

                expect(mockOnFilterChange).toHaveBeenCalledWith({ status: ['published'], year_from: 2021 });
            }, 15000);

            it('removes decimal year values on Apply instead of truncating them', async () => {
                const user = userEvent.setup();
                render(<ResourcesFilters {...defaultProps} filters={{ status: ['published'], year_from: 2021, year_to: 2024 }} />);

                fireEvent.change(screen.getByLabelText('To Year'), { target: { value: '2021.9' } });
                await user.click(screen.getByRole('button', { name: 'Apply' }));

                expect(mockOnFilterChange).toHaveBeenCalledWith({ status: ['published'], year_from: 2021 });
            }, 15000);

            it('removes out-of-range year values on Apply', async () => {
                const user = userEvent.setup();
                render(<ResourcesFilters {...defaultProps} filters={{ status: ['published'], year_from: 2021, year_to: 2024 }} />);

                fireEvent.change(screen.getByLabelText('From Year'), { target: { value: '1999' } });
                await user.click(screen.getByRole('button', { name: 'Apply' }));

                expect(mockOnFilterChange).toHaveBeenCalledWith({ status: ['published'], year_to: 2024 });
            }, 15000);

            it('clears local year draft values without triggering a reload when nothing is committed', async () => {
                const user = userEvent.setup();
                render(<ResourcesFilters {...defaultProps} />);

                const fromYearInput = screen.getByLabelText('From Year');

                await user.type(fromYearInput, '2021');
                await user.click(screen.getByRole('button', { name: 'Clear' }));

                expect(mockOnFilterChange).not.toHaveBeenCalled();
                expect(fromYearInput).toHaveValue(null);
                expect(screen.getByLabelText('To Year')).toHaveValue(null);
            }, 15000);

            it('clears committed year range values while preserving other filters', async () => {
                const user = userEvent.setup();
                render(<ResourcesFilters {...defaultProps} filters={{ status: ['published'], year_from: 2021, year_to: 2024 }} />);

                await user.click(screen.getByRole('button', { name: 'Clear' }));

                expect(mockOnFilterChange).toHaveBeenCalledWith({ status: ['published'] });
            }, 15000);

            it('syncs local year inputs when the parent filters change', () => {
                const { rerender } = render(<ResourcesFilters {...defaultProps} filters={{ year_from: 2021 }} />);

                expect(screen.getByLabelText('From Year')).toHaveValue(2021);
                expect(screen.getByLabelText('To Year')).toHaveValue(null);

                rerender(<ResourcesFilters {...defaultProps} filters={{ year_from: 2022, year_to: 2025 }} />);

                expect(screen.getByLabelText('From Year')).toHaveValue(2022);
                expect(screen.getByLabelText('To Year')).toHaveValue(2025);
            });

    it('disables filters when loading', () => {
        render(<ResourcesFilters {...defaultProps} isLoading={true} />);
        
        const searchInput = screen.getByPlaceholderText(/search title or doi/i);
        expect(searchInput).toBeDisabled();
    });
});
