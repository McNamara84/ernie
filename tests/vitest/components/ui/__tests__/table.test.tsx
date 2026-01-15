import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import {
    Table,
    TableBody,
    TableCaption,
    TableCell,
    TableFooter,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';

describe('Table', () => {
    it('renders a table element', () => {
        render(
            <Table>
                <TableBody>
                    <TableRow>
                        <TableCell>Cell content</TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        );

        expect(screen.getByRole('table')).toBeInTheDocument();
    });

    it('renders table header with columnheader role', () => {
        render(
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Name</TableHead>
                        <TableHead>Age</TableHead>
                    </TableRow>
                </TableHeader>
            </Table>
        );

        expect(screen.getAllByRole('columnheader')).toHaveLength(2);
        expect(screen.getByText('Name')).toBeInTheDocument();
        expect(screen.getByText('Age')).toBeInTheDocument();
    });

    it('renders table body with cells', () => {
        render(
            <Table>
                <TableBody>
                    <TableRow>
                        <TableCell>John</TableCell>
                        <TableCell>30</TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        );

        expect(screen.getAllByRole('cell')).toHaveLength(2);
        expect(screen.getByText('John')).toBeInTheDocument();
        expect(screen.getByText('30')).toBeInTheDocument();
    });

    it('renders table caption', () => {
        render(
            <Table>
                <TableCaption>A list of users</TableCaption>
                <TableBody>
                    <TableRow>
                        <TableCell>Data</TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        );

        expect(screen.getByText('A list of users')).toBeInTheDocument();
    });

    it('renders table footer', () => {
        render(
            <Table>
                <TableBody>
                    <TableRow>
                        <TableCell>Data</TableCell>
                    </TableRow>
                </TableBody>
                <TableFooter data-testid="table-footer">
                    <TableRow>
                        <TableCell>Total</TableCell>
                    </TableRow>
                </TableFooter>
            </Table>
        );

        expect(screen.getByTestId('table-footer')).toBeInTheDocument();
        expect(screen.getByText('Total')).toBeInTheDocument();
    });

    it('applies custom className to Table', () => {
        render(
            <Table className="custom-table">
                <TableBody>
                    <TableRow>
                        <TableCell>Cell</TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        );

        expect(screen.getByRole('table')).toHaveClass('custom-table');
    });

    it('applies custom className to TableRow', () => {
        render(
            <Table>
                <TableBody>
                    <TableRow className="custom-row" data-testid="row">
                        <TableCell>Cell</TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        );

        expect(screen.getByTestId('row')).toHaveClass('custom-row');
    });

    it('renders a complete table with all components', () => {
        render(
            <Table>
                <TableCaption>User Information</TableCaption>
                <TableHeader>
                    <TableRow>
                        <TableHead>Name</TableHead>
                        <TableHead>Email</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow>
                        <TableCell>Alice</TableCell>
                        <TableCell>alice@example.com</TableCell>
                    </TableRow>
                    <TableRow>
                        <TableCell>Bob</TableCell>
                        <TableCell>bob@example.com</TableCell>
                    </TableRow>
                </TableBody>
                <TableFooter>
                    <TableRow>
                        <TableCell colSpan={2}>2 users total</TableCell>
                    </TableRow>
                </TableFooter>
            </Table>
        );

        expect(screen.getByRole('table')).toBeInTheDocument();
        expect(screen.getByText('User Information')).toBeInTheDocument();
        expect(screen.getAllByRole('columnheader')).toHaveLength(2);
        expect(screen.getByText('Alice')).toBeInTheDocument();
        expect(screen.getByText('Bob')).toBeInTheDocument();
        expect(screen.getByText('2 users total')).toBeInTheDocument();
    });
});
