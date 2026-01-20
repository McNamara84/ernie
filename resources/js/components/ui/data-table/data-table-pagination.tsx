'use client';

import type { Table } from '@tanstack/react-table';
import { ChevronLeft, ChevronRight, ChevronsLeft, ChevronsRight } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';

interface DataTablePaginationProps<TData> {
    table: Table<TData>;
    serverSide?: boolean;
    paginationInfo?: {
        currentPage: number;
        lastPage: number;
        perPage: number;
        total: number;
        from: number | null;
        to: number | null;
    };
    onPageChange?: (page: number) => void;
    onPerPageChange?: (perPage: number) => void;
    perPageOptions?: number[];
}

export function DataTablePagination<TData>({
    table,
    serverSide = false,
    paginationInfo,
    onPageChange,
    onPerPageChange,
    perPageOptions = [10, 20, 30, 50],
}: DataTablePaginationProps<TData>) {
    // Server-side pagination values
    const currentPage = serverSide ? (paginationInfo?.currentPage ?? 1) : table.getState().pagination.pageIndex + 1;
    const pageCount = serverSide ? (paginationInfo?.lastPage ?? 1) : table.getPageCount();
    const perPage = serverSide ? (paginationInfo?.perPage ?? 10) : table.getState().pagination.pageSize;
    const total = serverSide ? (paginationInfo?.total ?? 0) : table.getFilteredRowModel().rows.length;
    const from = serverSide ? (paginationInfo?.from ?? 0) : (currentPage - 1) * perPage + 1;
    const to = serverSide ? (paginationInfo?.to ?? 0) : Math.min(currentPage * perPage, total);

    const canPreviousPage = serverSide ? currentPage > 1 : table.getCanPreviousPage();
    const canNextPage = serverSide ? currentPage < pageCount : table.getCanNextPage();

    const handlePageChange = (page: number) => {
        if (serverSide) {
            onPageChange?.(page);
        } else {
            table.setPageIndex(page - 1);
        }
    };

    const handlePerPageChange = (value: string) => {
        const newPerPage = Number(value);
        if (serverSide) {
            onPerPageChange?.(newPerPage);
        } else {
            table.setPageSize(newPerPage);
        }
    };

    return (
        <div className="flex items-center justify-between px-2">
            <div className="flex-1 text-sm text-muted-foreground">
                {total > 0 ? (
                    <>
                        Showing {from} to {to} of {total} results
                    </>
                ) : (
                    'No results'
                )}
            </div>
            <div className="flex items-center space-x-6 lg:space-x-8">
                <div className="flex items-center space-x-2">
                    <p className="text-sm font-medium">Rows per page</p>
                    <Select value={`${perPage}`} onValueChange={handlePerPageChange}>
                        <SelectTrigger className="h-8 w-[70px]">
                            <SelectValue placeholder={perPage} />
                        </SelectTrigger>
                        <SelectContent side="top">
                            {perPageOptions.map((pageSize) => (
                                <SelectItem key={pageSize} value={`${pageSize}`}>
                                    {pageSize}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>
                <div className="flex w-[100px] items-center justify-center text-sm font-medium">
                    Page {currentPage} of {pageCount}
                </div>
                <div className="flex items-center space-x-2">
                    <Button
                        variant="outline"
                        className="hidden h-8 w-8 p-0 lg:flex"
                        onClick={() => handlePageChange(1)}
                        disabled={!canPreviousPage}
                    >
                        <span className="sr-only">Go to first page</span>
                        <ChevronsLeft className="h-4 w-4" />
                    </Button>
                    <Button variant="outline" className="h-8 w-8 p-0" onClick={() => handlePageChange(currentPage - 1)} disabled={!canPreviousPage}>
                        <span className="sr-only">Go to previous page</span>
                        <ChevronLeft className="h-4 w-4" />
                    </Button>
                    <Button variant="outline" className="h-8 w-8 p-0" onClick={() => handlePageChange(currentPage + 1)} disabled={!canNextPage}>
                        <span className="sr-only">Go to next page</span>
                        <ChevronRight className="h-4 w-4" />
                    </Button>
                    <Button
                        variant="outline"
                        className="hidden h-8 w-8 p-0 lg:flex"
                        onClick={() => handlePageChange(pageCount)}
                        disabled={!canNextPage}
                    >
                        <span className="sr-only">Go to last page</span>
                        <ChevronsRight className="h-4 w-4" />
                    </Button>
                </div>
            </div>
        </div>
    );
}
