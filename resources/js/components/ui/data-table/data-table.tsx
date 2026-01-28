'use client';

import {
    type ColumnDef,
    type ColumnFiltersState,
    flexRender,
    getCoreRowModel,
    getFilteredRowModel,
    getPaginationRowModel,
    getSortedRowModel,
    type SortingState,
    useReactTable,
    type VisibilityState,
} from '@tanstack/react-table';
import * as React from 'react';

import { Skeleton } from '@/components/ui/skeleton';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { cn } from '@/lib/utils';

import { DataTablePagination } from './data-table-pagination';

interface DataTableProps<TData, TValue> {
    /** Column definitions for the table */
    columns: ColumnDef<TData, TValue>[];
    /** Data to display in the table */
    data: TData[];
    /** Enable pagination (default: true) */
    pagination?: boolean;
    /** Custom pagination info for server-side pagination */
    paginationInfo?: {
        currentPage: number;
        lastPage: number;
        perPage: number;
        total: number;
        from: number | null;
        to: number | null;
    };
    /** Callback for page changes (server-side pagination) */
    onPageChange?: (page: number) => void;
    /** Callback for per-page changes (server-side pagination) */
    onPerPageChange?: (perPage: number) => void;
    /** Enable server-side pagination */
    serverSide?: boolean;
    /** Initial sorting state */
    initialSorting?: SortingState;
    /** Callback for sorting changes (server-side sorting) */
    onSortingChange?: (sorting: SortingState) => void;
    /** Loading state */
    loading?: boolean;
    /** Empty state message */
    emptyMessage?: string;
    /** Additional class names */
    className?: string;
    /** Show column visibility toggle */
    showColumnVisibility?: boolean;
    /** Row click handler */
    onRowClick?: (row: TData) => void;
    /** Per-page options */
    perPageOptions?: number[];
}

export function DataTable<TData, TValue>({
    columns,
    data,
    pagination = true,
    paginationInfo,
    onPageChange,
    onPerPageChange,
    serverSide = false,
    initialSorting = [],
    onSortingChange,
    loading = false,
    emptyMessage = 'No results.',
    className,
    onRowClick,
    perPageOptions = [10, 20, 30, 50],
}: DataTableProps<TData, TValue>) {
    const [sorting, setSorting] = React.useState<SortingState>(initialSorting);
    const [columnFilters, setColumnFilters] = React.useState<ColumnFiltersState>([]);
    const [columnVisibility, setColumnVisibility] = React.useState<VisibilityState>({});
    const [rowSelection, setRowSelection] = React.useState({});

    // Handle sorting changes
    const handleSortingChange = React.useCallback(
        (updaterOrValue: SortingState | ((old: SortingState) => SortingState)) => {
            const newSorting = typeof updaterOrValue === 'function' ? updaterOrValue(sorting) : updaterOrValue;
            setSorting(newSorting);
            onSortingChange?.(newSorting);
        },
        [sorting, onSortingChange],
    );

    const table = useReactTable({
        data,
        columns,
        getCoreRowModel: getCoreRowModel(),
        // Only use client-side pagination/sorting if not server-side
        ...(serverSide
            ? {
                  manualPagination: true,
                  manualSorting: true,
                  pageCount: paginationInfo?.lastPage ?? -1,
              }
            : {
                  getPaginationRowModel: getPaginationRowModel(),
                  getSortedRowModel: getSortedRowModel(),
              }),
        getFilteredRowModel: getFilteredRowModel(),
        onSortingChange: handleSortingChange,
        onColumnFiltersChange: setColumnFilters,
        onColumnVisibilityChange: setColumnVisibility,
        onRowSelectionChange: setRowSelection,
        state: {
            sorting,
            columnFilters,
            columnVisibility,
            rowSelection,
            ...(serverSide && paginationInfo
                ? {
                      pagination: {
                          pageIndex: paginationInfo.currentPage - 1,
                          pageSize: paginationInfo.perPage,
                      },
                  }
                : {}),
        },
    });

    return (
        <div className={cn('space-y-4', className)}>
            <div className="rounded-md border">
                <Table>
                    <TableHeader>
                        {table.getHeaderGroups().map((headerGroup) => (
                            <TableRow key={headerGroup.id}>
                                {headerGroup.headers.map((header) => (
                                    <TableHead key={header.id} colSpan={header.colSpan}>
                                        {header.isPlaceholder ? null : flexRender(header.column.columnDef.header, header.getContext())}
                                    </TableHead>
                                ))}
                            </TableRow>
                        ))}
                    </TableHeader>
                    <TableBody>
                        {loading ? (
                            // Loading skeleton
                            Array.from({ length: 5 }).map((_, index) => (
                                <TableRow key={`skeleton-${index}`}>
                                    {columns.map((_, colIndex) => (
                                        <TableCell key={`skeleton-${index}-${colIndex}`}>
                                            <Skeleton className="h-4 w-full" />
                                        </TableCell>
                                    ))}
                                </TableRow>
                            ))
                        ) : table.getRowModel().rows?.length ? (
                            table.getRowModel().rows.map((row) => (
                                <TableRow
                                    key={row.id}
                                    data-state={row.getIsSelected() && 'selected'}
                                    className={onRowClick ? 'cursor-pointer' : undefined}
                                    onClick={() => onRowClick?.(row.original)}
                                >
                                    {row.getVisibleCells().map((cell) => (
                                        <TableCell key={cell.id}>{flexRender(cell.column.columnDef.cell, cell.getContext())}</TableCell>
                                    ))}
                                </TableRow>
                            ))
                        ) : (
                            <TableRow>
                                <TableCell colSpan={columns.length} className="h-24 text-center">
                                    {emptyMessage}
                                </TableCell>
                            </TableRow>
                        )}
                    </TableBody>
                </Table>
            </div>
            {pagination && (
                <DataTablePagination
                    table={table}
                    serverSide={serverSide}
                    paginationInfo={paginationInfo}
                    onPageChange={onPageChange}
                    onPerPageChange={onPerPageChange}
                    perPageOptions={perPageOptions}
                />
            )}
        </div>
    );
}
