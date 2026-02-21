import '@testing-library/jest-dom/vitest';

import { act, render, renderHook, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import React from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { SortableTableHeader, type SortDirection,useSortState } from '@/components/ui/sortable-table-header';
import { Table, TableBody, TableHeader, TableRow } from '@/components/ui/table';

// Wrapper to render TableHead within a proper table structure
function renderInTable(ui: React.ReactElement) {
    return render(
        <Table>
            <TableHeader>
                <TableRow>{ui}</TableRow>
            </TableHeader>
            <TableBody />
        </Table>,
    );
}

describe('SortableTableHeader', () => {
    const defaultProps = {
        label: 'Name',
        sortKey: 'name' as const,
        sortState: { key: 'name', direction: 'asc' as SortDirection },
        onSort: vi.fn(),
    };

    beforeEach(() => {
        vi.clearAllMocks();
    });

    describe('rendering', () => {
        it('renders the label text', () => {
            renderInTable(<SortableTableHeader {...defaultProps} />);
            expect(screen.getByText('Name')).toBeInTheDocument();
        });

        it('renders a clickable button', () => {
            renderInTable(<SortableTableHeader {...defaultProps} />);
            expect(screen.getByRole('button', { name: /sort by name/i })).toBeInTheDocument();
        });

        it('accepts ReactNode as label', () => {
            renderInTable(
                <SortableTableHeader
                    {...defaultProps}
                    label={<span data-testid="custom-label">Custom</span>}
                />,
            );
            expect(screen.getByTestId('custom-label')).toBeInTheDocument();
        });
    });

    describe('aria-sort', () => {
        it('sets aria-sort="ascending" when active and asc', () => {
            renderInTable(
                <SortableTableHeader
                    {...defaultProps}
                    sortState={{ key: 'name', direction: 'asc' }}
                />,
            );
            const th = screen.getByRole('columnheader');
            expect(th).toHaveAttribute('aria-sort', 'ascending');
        });

        it('sets aria-sort="descending" when active and desc', () => {
            renderInTable(
                <SortableTableHeader
                    {...defaultProps}
                    sortState={{ key: 'name', direction: 'desc' }}
                />,
            );
            const th = screen.getByRole('columnheader');
            expect(th).toHaveAttribute('aria-sort', 'descending');
        });

        it('sets aria-sort="none" when not active', () => {
            renderInTable(
                <SortableTableHeader
                    {...defaultProps}
                    sortState={{ key: 'other', direction: 'asc' }}
                />,
            );
            const th = screen.getByRole('columnheader');
            expect(th).toHaveAttribute('aria-sort', 'none');
        });
    });

    describe('interactions', () => {
        it('calls onSort with the sort key when clicked', async () => {
            const onSort = vi.fn();
            renderInTable(<SortableTableHeader {...defaultProps} onSort={onSort} />);

            await userEvent.click(screen.getByRole('button'));
            expect(onSort).toHaveBeenCalledWith('name');
        });
    });
});

describe('SortIndicator (via SortableTableHeader)', () => {
    it('renders ArrowUpDown icon when column is not active', () => {
        render(
            <Table><TableHeader><TableRow>
                <SortableTableHeader
                    label="Name"
                    sortKey="name"
                    sortState={{ key: 'other', direction: 'asc' }}
                    onSort={vi.fn()}
                />
            </TableRow></TableHeader><TableBody /></Table>,
        );
        // ArrowUpDown icon should be present with opacity-50
        const button = screen.getByRole('button', { name: /sort by name/i });
        const svg = button.querySelectorAll('svg');
        // Last SVG is the sort icon
        const sortIcon = svg[svg.length - 1];
        expect(sortIcon).toBeInTheDocument();
        expect(sortIcon?.classList.contains('opacity-50') || sortIcon?.getAttribute('class')?.includes('opacity-50')).toBe(true);
    });

    it('renders ArrowUp icon when active and ascending', () => {
        render(
            <Table><TableHeader><TableRow>
                <SortableTableHeader
                    label="Name"
                    sortKey="name"
                    sortState={{ key: 'name', direction: 'asc' }}
                    onSort={vi.fn()}
                />
            </TableRow></TableHeader><TableBody /></Table>,
        );
        const button = screen.getByRole('button', { name: /sort by name/i });
        const svg = button.querySelectorAll('svg');
        const sortIcon = svg[svg.length - 1];
        expect(sortIcon).toBeInTheDocument();
        expect(sortIcon?.className).not.toContain('opacity-50');
    });

    it('renders ArrowDown icon when active and descending', () => {
        render(
            <Table><TableHeader><TableRow>
                <SortableTableHeader
                    label="Name"
                    sortKey="name"
                    sortState={{ key: 'name', direction: 'desc' }}
                    onSort={vi.fn()}
                />
            </TableRow></TableHeader><TableBody /></Table>,
        );
        const button = screen.getByRole('button', { name: /sort by name/i });
        const svg = button.querySelectorAll('svg');
        const sortIcon = svg[svg.length - 1];
        expect(sortIcon).toBeInTheDocument();
        expect(sortIcon?.className).not.toContain('opacity-50');
    });
});

describe('useSortState', () => {
    it('initializes with provided key and direction', () => {
        const { result } = renderHook(() =>
            useSortState({ initialKey: 'name', initialDirection: 'asc' }),
        );
        expect(result.current.sortState).toEqual({ key: 'name', direction: 'asc' });
    });

    it('toggles direction when same key is clicked', () => {
        const { result } = renderHook(() =>
            useSortState({ initialKey: 'name', initialDirection: 'asc' }),
        );

        act(() => {
            result.current.handleSort('name');
        });

        expect(result.current.sortState).toEqual({ key: 'name', direction: 'desc' });
    });

    it('toggles back to asc from desc', () => {
        const { result } = renderHook(() =>
            useSortState({ initialKey: 'name', initialDirection: 'desc' }),
        );

        act(() => {
            result.current.handleSort('name');
        });

        expect(result.current.sortState).toEqual({ key: 'name', direction: 'asc' });
    });

    it('uses default direction "asc" when clicking a new key', () => {
        const { result } = renderHook(() =>
            useSortState<string>({ initialKey: 'name', initialDirection: 'desc' }),
        );

        act(() => {
            result.current.handleSort('email');
        });

        expect(result.current.sortState).toEqual({ key: 'email', direction: 'asc' });
    });

    it('uses custom defaultDirections for new keys', () => {
        const { result } = renderHook(() =>
            useSortState<string>({
                initialKey: 'name',
                initialDirection: 'asc',
                defaultDirections: { created_at: 'desc' },
            }),
        );

        act(() => {
            result.current.handleSort('created_at');
        });

        expect(result.current.sortState).toEqual({ key: 'created_at', direction: 'desc' });
    });

    it('calls onSortChange callback when sort changes', () => {
        const onSortChange = vi.fn();
        const { result } = renderHook(() =>
            useSortState({
                initialKey: 'name',
                initialDirection: 'asc',
                onSortChange,
            }),
        );

        act(() => {
            result.current.handleSort('name');
        });

        expect(onSortChange).toHaveBeenCalledWith({ key: 'name', direction: 'desc' });
    });

    it('allows manual setSortState', () => {
        const { result } = renderHook(() =>
            useSortState<string>({ initialKey: 'name', initialDirection: 'asc' }),
        );

        act(() => {
            result.current.setSortState({ key: 'email', direction: 'desc' });
        });

        expect(result.current.sortState).toEqual({ key: 'email', direction: 'desc' });
    });
});
