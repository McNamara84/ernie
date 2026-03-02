import { act, fireEvent, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { IgsnFilters, type IgsnFilterOptions, type IgsnFilterState } from '@/components/igsns/igsn-filters';

// Mock Radix UI Select to avoid portal issues in tests
vi.mock('@/components/ui/select', () => ({
    Select: ({
        children,
        onValueChange,
        value,
        disabled,
    }: {
        children: React.ReactNode;
        onValueChange: (value: string) => void;
        value: string;
        disabled?: boolean;
    }) => {
        // Extract aria-label from SelectTrigger children
        const extractAriaLabel = (nodes: React.ReactNode): string | undefined => {
            const childArray = Array.isArray(nodes) ? nodes : [nodes];
            for (const child of childArray) {
                if (child && typeof child === 'object' && 'props' in child) {
                    const props = child.props as Record<string, unknown>;
                    if (props['aria-label']) return props['aria-label'] as string;
                    if (props.children) {
                        const found = extractAriaLabel(props.children as React.ReactNode);
                        if (found) return found;
                    }
                }
            }
            return undefined;
        };
        const ariaLabel = extractAriaLabel(children);
        return (
            <select onChange={(e) => onValueChange(e.target.value)} value={value} disabled={disabled} aria-label={ariaLabel}>
                {children}
            </select>
        );
    },
    SelectContent: ({ children }: { children: React.ReactNode }) => <>{children}</>,
    SelectItem: ({ children, value }: { children: React.ReactNode; value: string }) => <option value={value}>{children}</option>,
    SelectTrigger: ({ children }: { children: React.ReactNode }) => <>{children}</>,
    SelectValue: () => null,
}));

describe('IgsnFilters Component', () => {
    const mockOnFilterChange = vi.fn();

    const defaultFilterOptions: IgsnFilterOptions = {
        prefixes: ['10.58052', '10.58095', '10.60516'],
        statuses: ['pending', 'registered', 'error'],
    };

    const defaultProps = {
        filters: {} as IgsnFilterState,
        onFilterChange: mockOnFilterChange,
        filterOptions: defaultFilterOptions,
        resultCount: 50,
        totalCount: 50,
        isLoading: false,
    };

    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('renders search input and filter dropdowns', () => {
        render(<IgsnFilters {...defaultProps} />);

        expect(screen.getByPlaceholderText(/search igsn or title/i)).toBeInTheDocument();
        expect(screen.getByLabelText('Filter by IGSN prefix')).toBeInTheDocument();
        expect(screen.getByLabelText('Filter by upload status')).toBeInTheDocument();
    });

    it('shows filter options in prefix dropdown', () => {
        render(<IgsnFilters {...defaultProps} />);

        const prefixSelect = screen.getByLabelText('Filter by IGSN prefix');
        const options = prefixSelect.querySelectorAll('option');

        // "All Prefixes" + 3 prefix options
        expect(options).toHaveLength(4);
        expect(options[0]).toHaveTextContent('All Prefixes');
        expect(options[1]).toHaveTextContent('10.58052');
        expect(options[2]).toHaveTextContent('10.58095');
        expect(options[3]).toHaveTextContent('10.60516');
    });

    it('shows filter options in status dropdown', () => {
        render(<IgsnFilters {...defaultProps} />);

        const statusSelect = screen.getByLabelText('Filter by upload status');
        const options = statusSelect.querySelectorAll('option');

        // "All Statuses" + 3 status options
        expect(options).toHaveLength(4);
        expect(options[0]).toHaveTextContent('All Statuses');
        expect(options[1]).toHaveTextContent('Pending');
        expect(options[2]).toHaveTextContent('Registered');
        expect(options[3]).toHaveTextContent('Error');
    });

    it('calls onFilterChange when prefix is selected', async () => {
        const user = userEvent.setup();
        render(<IgsnFilters {...defaultProps} />);

        const prefixSelect = screen.getByLabelText('Filter by IGSN prefix');
        await user.selectOptions(prefixSelect, '10.60516');

        expect(mockOnFilterChange).toHaveBeenCalledTimes(1);
        expect(mockOnFilterChange).toHaveBeenCalledWith(
            expect.objectContaining({ prefix: '10.60516' }),
        );
    });

    it('calls onFilterChange when status is selected', async () => {
        const user = userEvent.setup();
        render(<IgsnFilters {...defaultProps} />);

        const statusSelect = screen.getByLabelText('Filter by upload status');
        await user.selectOptions(statusSelect, 'registered');

        expect(mockOnFilterChange).toHaveBeenCalledTimes(1);
        expect(mockOnFilterChange).toHaveBeenCalledWith(
            expect.objectContaining({ status: 'registered' }),
        );
    });

    it('clears prefix when "All Prefixes" is selected', async () => {
        const user = userEvent.setup();
        const props = {
            ...defaultProps,
            filters: { prefix: '10.60516' } as IgsnFilterState,
        };
        render(<IgsnFilters {...props} />);

        const prefixSelect = screen.getByLabelText('Filter by IGSN prefix');
        await user.selectOptions(prefixSelect, 'all');

        expect(mockOnFilterChange).toHaveBeenCalledTimes(1);
        const calledWith = mockOnFilterChange.mock.calls[0][0] as IgsnFilterState;
        expect(calledWith.prefix).toBeUndefined();
    });

    it('shows active filter badges when filters are active', () => {
        const props = {
            ...defaultProps,
            filters: { prefix: '10.60516', status: 'pending' } as IgsnFilterState,
            resultCount: 12,
            totalCount: 50,
        };
        render(<IgsnFilters {...props} />);

        expect(screen.getByText('Prefix: 10.60516')).toBeInTheDocument();
        expect(screen.getByText('Status: Pending')).toBeInTheDocument();
        expect(screen.getByText('Active filters:')).toBeInTheDocument();
    });

    it('shows result count when filtering changes the total', () => {
        const props = {
            ...defaultProps,
            resultCount: 12,
            totalCount: 50,
        };
        render(<IgsnFilters {...props} />);

        expect(screen.getByText('12')).toBeInTheDocument();
        expect(screen.getByText('50')).toBeInTheDocument();
    });

    it('hides result count row when no filters are active and counts match', () => {
        render(<IgsnFilters {...defaultProps} />);

        // When resultCount === totalCount and no filters are active,
        // the result count row is not rendered
        expect(screen.queryByText(/samples total/i)).not.toBeInTheDocument();
    });

    it('removes individual filter on badge close button click', async () => {
        const user = userEvent.setup();
        const props = {
            ...defaultProps,
            filters: { prefix: '10.60516', status: 'pending' } as IgsnFilterState,
            resultCount: 12,
            totalCount: 50,
        };
        render(<IgsnFilters {...props} />);

        const removePrefixButton = screen.getByLabelText('Remove prefix filter');
        await user.click(removePrefixButton);

        expect(mockOnFilterChange).toHaveBeenCalledTimes(1);
        const calledWith = mockOnFilterChange.mock.calls[0][0] as IgsnFilterState;
        expect(calledWith.prefix).toBeUndefined();
        expect(calledWith.status).toBe('pending');
    });

    it('clears all filters when Clear All is clicked', async () => {
        const user = userEvent.setup();
        const props = {
            ...defaultProps,
            filters: { prefix: '10.60516', status: 'pending' } as IgsnFilterState,
            resultCount: 12,
            totalCount: 50,
        };
        render(<IgsnFilters {...props} />);

        const clearAllButton = screen.getByText('Clear All');
        await user.click(clearAllButton);

        expect(mockOnFilterChange).toHaveBeenCalledTimes(1);
        expect(mockOnFilterChange).toHaveBeenCalledWith({});
    });

    it('hides Clear All button when no filters are active', () => {
        render(<IgsnFilters {...defaultProps} />);

        expect(screen.queryByText('Clear All')).not.toBeInTheDocument();
    });

    it('disables controls when loading', () => {
        const props = { ...defaultProps, isLoading: true };
        render(<IgsnFilters {...props} />);

        expect(screen.getByPlaceholderText(/search igsn or title/i)).toBeDisabled();
        expect(screen.getByLabelText('Filter by IGSN prefix')).toBeDisabled();
        expect(screen.getByLabelText('Filter by upload status')).toBeDisabled();
    });

    it('debounces search input with 1000ms delay', async () => {
        vi.useFakeTimers();
        render(<IgsnFilters {...defaultProps} />);

        const searchInput = screen.getByPlaceholderText(/search igsn or title/i);
        fireEvent.change(searchInput, { target: { value: 'test' } });

        // Should not trigger immediately
        expect(mockOnFilterChange).not.toHaveBeenCalled();

        // Advance past debounce delay (1000ms)
        await act(async () => {
            vi.advanceTimersByTime(1100);
        });

        expect(mockOnFilterChange).toHaveBeenCalledTimes(1);
        expect(mockOnFilterChange).toHaveBeenCalledWith(
            expect.objectContaining({ search: 'test' }),
        );

        vi.useRealTimers();
    });

    it('does not trigger search for input shorter than 3 characters', async () => {
        vi.useFakeTimers();
        render(<IgsnFilters {...defaultProps} />);

        const searchInput = screen.getByPlaceholderText(/search igsn or title/i);
        fireEvent.change(searchInput, { target: { value: 'ab' } });

        // Advance past debounce delay
        await act(async () => {
            vi.advanceTimersByTime(1200);
        });

        // Should not trigger search (minimum is 3 characters)
        expect(mockOnFilterChange).not.toHaveBeenCalled();

        vi.useRealTimers();
    });

    it('clears active search filter when input drops below minimum length', async () => {
        const user = userEvent.setup({ delay: null });
        const propsWithSearch = { ...defaultProps, filters: { search: 'test' } };
        render(<IgsnFilters {...propsWithSearch} />);

        const searchInput = screen.getByPlaceholderText(/search igsn or title/i);
        await user.clear(searchInput);
        await user.type(searchInput, 'ab');

        // Should immediately clear the search filter since an active filter existed
        expect(mockOnFilterChange).toHaveBeenCalledWith(
            expect.not.objectContaining({ search: expect.anything() }),
        );

        vi.useRealTimers();
    });
});
