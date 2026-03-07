import { type ColumnDef } from '@tanstack/react-table';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';

import { DataTable } from '@/components/ui/data-table/data-table';

// Test DataTablePagination through the DataTable component (pagination component is internal)

interface TestRow {
    id: number;
    name: string;
}

const columns: ColumnDef<TestRow>[] = [
    { accessorKey: 'id', header: 'ID' },
    { accessorKey: 'name', header: 'Name' },
];

const manyRows: TestRow[] = Array.from({ length: 25 }, (_, i) => ({
    id: i + 1,
    name: `User ${i + 1}`,
}));

describe('DataTablePagination (via DataTable)', () => {
    describe('server-side pagination', () => {
        it('shows result count', () => {
            render(
                <DataTable
                    columns={columns}
                    data={manyRows.slice(0, 10)}
                    serverSide
                    paginationInfo={{
                        currentPage: 1,
                        lastPage: 3,
                        perPage: 10,
                        total: 25,
                        from: 1,
                        to: 10,
                    }}
                />,
            );

            expect(screen.getByText(/Showing 1 to 10 of 25 results/)).toBeInTheDocument();
        });

        it('shows page info', () => {
            render(
                <DataTable
                    columns={columns}
                    data={manyRows.slice(0, 10)}
                    serverSide
                    paginationInfo={{
                        currentPage: 2,
                        lastPage: 3,
                        perPage: 10,
                        total: 25,
                        from: 11,
                        to: 20,
                    }}
                />,
            );

            expect(screen.getByText('Page 2 of 3')).toBeInTheDocument();
        });

        it('calls onPageChange on next page click', async () => {
            const user = userEvent.setup();
            const onPageChange = vi.fn();

            render(
                <DataTable
                    columns={columns}
                    data={manyRows.slice(0, 10)}
                    serverSide
                    paginationInfo={{
                        currentPage: 1,
                        lastPage: 3,
                        perPage: 10,
                        total: 25,
                        from: 1,
                        to: 10,
                    }}
                    onPageChange={onPageChange}
                />,
            );

            const nextButton = screen.getByRole('button', { name: /go to next page/i });
            await user.click(nextButton);
            expect(onPageChange).toHaveBeenCalledWith(2);
        });

        it('disables previous button on first page', () => {
            render(
                <DataTable
                    columns={columns}
                    data={manyRows.slice(0, 10)}
                    serverSide
                    paginationInfo={{
                        currentPage: 1,
                        lastPage: 3,
                        perPage: 10,
                        total: 25,
                        from: 1,
                        to: 10,
                    }}
                />,
            );

            expect(screen.getByRole('button', { name: /go to previous page/i })).toBeDisabled();
        });

        it('disables next button on last page', () => {
            render(
                <DataTable
                    columns={columns}
                    data={manyRows.slice(0, 5)}
                    serverSide
                    paginationInfo={{
                        currentPage: 3,
                        lastPage: 3,
                        perPage: 10,
                        total: 25,
                        from: 21,
                        to: 25,
                    }}
                />,
            );

            expect(screen.getByRole('button', { name: /go to next page/i })).toBeDisabled();
        });

        it('shows "No results" when total is 0', () => {
            render(
                <DataTable
                    columns={columns}
                    data={[]}
                    serverSide
                    paginationInfo={{
                        currentPage: 1,
                        lastPage: 1,
                        perPage: 10,
                        total: 0,
                        from: null,
                        to: null,
                    }}
                />,
            );

            expect(screen.getByText('No results')).toBeInTheDocument();
        });
    });

    describe('client-side pagination', () => {
        it('shows correct page info for client-side data', () => {
            render(<DataTable columns={columns} data={manyRows} />);

            expect(screen.getByText('Page 1 of 3')).toBeInTheDocument();
        });

        it('shows rows per page selector', () => {
            render(<DataTable columns={columns} data={manyRows} />);

            expect(screen.getByText('Rows per page')).toBeInTheDocument();
        });
    });
});
