'use client';

import type { Table } from '@tanstack/react-table';
import { X } from 'lucide-react';
import * as React from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

import { DataTableViewOptions } from './data-table-view-options';

interface DataTableToolbarProps<TData> {
    table: Table<TData>;
    /** Column ID to use for global filter */
    filterColumn?: string;
    /** Placeholder for the filter input */
    filterPlaceholder?: string;
    /** Show view options toggle */
    showViewOptions?: boolean;
    /** Additional toolbar content */
    children?: React.ReactNode;
}

export function DataTableToolbar<TData>({
    table,
    filterColumn,
    filterPlaceholder = 'Filter...',
    showViewOptions = true,
    children,
}: DataTableToolbarProps<TData>) {
    const isFiltered = table.getState().columnFilters.length > 0;
    const column = filterColumn ? table.getColumn(filterColumn) : undefined;

    return (
        <div className="flex items-center justify-between">
            <div className="flex flex-1 items-center space-x-2">
                {filterColumn && column && (
                    <Input
                        placeholder={filterPlaceholder}
                        value={(column.getFilterValue() as string) ?? ''}
                        onChange={(event) => column.setFilterValue(event.target.value)}
                        className="h-8 w-[150px] lg:w-[250px]"
                    />
                )}
                {isFiltered && (
                    <Button variant="ghost" onClick={() => table.resetColumnFilters()} className="h-8 px-2 lg:px-3">
                        Reset
                        <X className="ml-2 h-4 w-4" />
                    </Button>
                )}
                {children}
            </div>
            {showViewOptions && <DataTableViewOptions table={table} />}
        </div>
    );
}
