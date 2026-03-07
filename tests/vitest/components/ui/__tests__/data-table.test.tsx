import { type ColumnDef } from '@tanstack/react-table';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';

import { DataTable } from '@/components/ui/data-table/data-table';

interface TestRow {
    id: number;
    name: string;
    email: string;
}

const columns: ColumnDef<TestRow>[] = [
    { accessorKey: 'id', header: 'ID' },
    { accessorKey: 'name', header: 'Name' },
    { accessorKey: 'email', header: 'Email' },
];

const testData: TestRow[] = [
    { id: 1, name: 'Alice', email: 'alice@example.com' },
    { id: 2, name: 'Bob', email: 'bob@example.com' },
    { id: 3, name: 'Charlie', email: 'charlie@example.com' },
];

describe('DataTable', () => {
    it('renders table with data', () => {
        render(<DataTable columns={columns} data={testData} pagination={false} />);

        expect(screen.getByText('Alice')).toBeInTheDocument();
        expect(screen.getByText('Bob')).toBeInTheDocument();
        expect(screen.getByText('Charlie')).toBeInTheDocument();
    });

    it('renders column headers', () => {
        render(<DataTable columns={columns} data={testData} pagination={false} />);

        expect(screen.getByText('ID')).toBeInTheDocument();
        expect(screen.getByText('Name')).toBeInTheDocument();
        expect(screen.getByText('Email')).toBeInTheDocument();
    });

    it('shows empty message when no data', () => {
        render(<DataTable columns={columns} data={[]} pagination={false} />);

        expect(screen.getByText('No results.')).toBeInTheDocument();
    });

    it('shows custom empty message', () => {
        render(<DataTable columns={columns} data={[]} pagination={false} emptyMessage="Nothing found" />);

        expect(screen.getByText('Nothing found')).toBeInTheDocument();
    });

    it('shows loading skeleton', () => {
        render(<DataTable columns={columns} data={[]} pagination={false} loading />);

        // Loading renders 5 skeleton rows, each with 3 columns = should not show empty message
        expect(screen.queryByText('No results.')).not.toBeInTheDocument();
    });

    it('renders with pagination by default', () => {
        render(
            <DataTable
                columns={columns}
                data={testData}
                paginationInfo={{
                    currentPage: 1,
                    lastPage: 3,
                    perPage: 10,
                    total: 25,
                    from: 1,
                    to: 10,
                }}
                serverSide
            />,
        );

        expect(screen.getByText(/Showing 1 to 10 of 25 results/)).toBeInTheDocument();
    });

    it('calls onRowClick when row is clicked', async () => {
        const user = userEvent.setup();
        const onRowClick = vi.fn();

        render(<DataTable columns={columns} data={testData} pagination={false} onRowClick={onRowClick} />);

        await user.click(screen.getByText('Alice'));
        expect(onRowClick).toHaveBeenCalledWith(testData[0]);
    });

    it('applies custom className', () => {
        const { container } = render(<DataTable columns={columns} data={testData} pagination={false} className="custom-class" />);

        expect(container.firstChild).toHaveClass('custom-class');
    });
});
