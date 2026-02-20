import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createColumnHelper, getCoreRowModel, getSortedRowModel, useReactTable } from '@tanstack/react-table';
import type { PropsWithChildren } from 'react';
import { describe, expect, it } from 'vitest';

import { DataTableColumnHeader, SimpleSortableHeader } from '@/components/ui/data-table/data-table-column-header';

interface TestRow {
    name: string;
}

const columnHelper = createColumnHelper<TestRow>();

// Wrapper that creates a table context for testing column header components
function TableWrapper({
    children,
    enableSorting = true,
    enableHiding = true,
}: PropsWithChildren<{ enableSorting?: boolean; enableHiding?: boolean }>) {
    const columns = [
        columnHelper.accessor('name', {
            header: ({ column }) => children ?? <DataTableColumnHeader column={column} title="Name" enableSorting={enableSorting} enableHiding={enableHiding} />,
            enableSorting,
            enableHiding,
        }),
    ];

    const Table = () => {
        const table = useReactTable({
            data: [{ name: 'Alice' }],
            columns,
            getCoreRowModel: getCoreRowModel(),
            getSortedRowModel: getSortedRowModel(),
        });

        return (
            <table>
                <thead>
                    {table.getHeaderGroups().map((hg) => (
                        <tr key={hg.id}>
                            {hg.headers.map((h) => (
                                <th key={h.id}>
                                    {h.isPlaceholder ? null : h.column.columnDef.header
                                        ? typeof h.column.columnDef.header === 'function'
                                            ? (h.column.columnDef.header as any)(h.getContext())
                                            : h.column.columnDef.header
                                        : null}
                                </th>
                            ))}
                        </tr>
                    ))}
                </thead>
            </table>
        );
    };

    return <Table />;
}

describe('DataTableColumnHeader', () => {
    it('renders title text', () => {
        render(<TableWrapper />);
        expect(screen.getByText('Name')).toBeInTheDocument();
    });

    it('renders as plain text when sorting is disabled', () => {
        render(<TableWrapper enableSorting={false} />);
        expect(screen.getByText('Name')).toBeInTheDocument();
        expect(screen.queryByRole('button')).not.toBeInTheDocument();
    });

    it('renders dropdown trigger button when sortable', () => {
        render(<TableWrapper />);
        expect(screen.getByRole('button', { name: /name/i })).toBeInTheDocument();
    });

    it('shows sort menu on click', async () => {
        const user = userEvent.setup();
        render(<TableWrapper />);

        await user.click(screen.getByRole('button', { name: /name/i }));

        expect(screen.getByText('Asc')).toBeInTheDocument();
        expect(screen.getByText('Desc')).toBeInTheDocument();
    });

    it('shows hide option when hiding is enabled', async () => {
        const user = userEvent.setup();
        render(<TableWrapper enableHiding />);

        await user.click(screen.getByRole('button', { name: /name/i }));

        expect(screen.getByText('Hide')).toBeInTheDocument();
    });
});

describe('SimpleSortableHeader', () => {
    it('renders title as button', () => {
        // Create a standalone wrapper that uses SimpleSortableHeader
        const columns = [
            columnHelper.accessor('name', {
                header: ({ column }) => <SimpleSortableHeader column={column} title="Status" />,
                enableSorting: true,
            }),
        ];

        function SimpleTable() {
            const table = useReactTable({
                data: [{ name: 'Alice' }],
                columns,
                getCoreRowModel: getCoreRowModel(),
                getSortedRowModel: getSortedRowModel(),
            });

            return (
                <table>
                    <thead>
                        {table.getHeaderGroups().map((hg) => (
                            <tr key={hg.id}>
                                {hg.headers.map((h) => (
                                    <th key={h.id}>
                                        {h.isPlaceholder
                                            ? null
                                            : typeof h.column.columnDef.header === 'function'
                                              ? (h.column.columnDef.header as any)(h.getContext())
                                              : h.column.columnDef.header}
                                    </th>
                                ))}
                            </tr>
                        ))}
                    </thead>
                </table>
            );
        }

        render(<SimpleTable />);

        expect(screen.getByRole('button', { name: /status/i })).toBeInTheDocument();
    });
});
