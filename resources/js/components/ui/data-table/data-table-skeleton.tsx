'use client';

import { Skeleton } from '@/components/ui/skeleton';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';

interface DataTableSkeletonProps {
    /** Number of columns */
    columns: number;
    /** Number of rows to show */
    rows?: number;
    /** Column widths (optional) */
    columnWidths?: string[];
    /** Show header */
    showHeader?: boolean;
}

export function DataTableSkeleton({ columns, rows = 5, columnWidths, showHeader = true }: DataTableSkeletonProps) {
    return (
        <div className="rounded-md border">
            <Table>
                {showHeader && (
                    <TableHeader>
                        <TableRow>
                            {Array.from({ length: columns }).map((_, index) => (
                                <TableHead key={index} style={columnWidths?.[index] ? { width: columnWidths[index] } : undefined}>
                                    <Skeleton className="h-4 w-24" />
                                </TableHead>
                            ))}
                        </TableRow>
                    </TableHeader>
                )}
                <TableBody>
                    {Array.from({ length: rows }).map((_, rowIndex) => (
                        <TableRow key={rowIndex}>
                            {Array.from({ length: columns }).map((_, colIndex) => (
                                <TableCell key={colIndex}>
                                    <Skeleton className="h-4 w-full" style={columnWidths?.[colIndex] ? { width: columnWidths[colIndex] } : undefined} />
                                </TableCell>
                            ))}
                        </TableRow>
                    ))}
                </TableBody>
            </Table>
        </div>
    );
}

/**
 * Full page skeleton with pagination
 */
interface DataTablePageSkeletonProps extends DataTableSkeletonProps {
    /** Show pagination skeleton */
    showPagination?: boolean;
    /** Show toolbar skeleton */
    showToolbar?: boolean;
}

export function DataTablePageSkeleton({ showPagination = true, showToolbar = false, ...props }: DataTablePageSkeletonProps) {
    return (
        <div className="space-y-4">
            {showToolbar && (
                <div className="flex items-center justify-between">
                    <Skeleton className="h-8 w-[250px]" />
                    <Skeleton className="h-8 w-[100px]" />
                </div>
            )}
            <DataTableSkeleton {...props} />
            {showPagination && (
                <div className="flex items-center justify-between px-2">
                    <Skeleton className="h-4 w-[150px]" />
                    <div className="flex items-center space-x-6 lg:space-x-8">
                        <div className="flex items-center space-x-2">
                            <Skeleton className="h-4 w-[100px]" />
                            <Skeleton className="h-8 w-[70px]" />
                        </div>
                        <Skeleton className="h-4 w-[100px]" />
                        <div className="flex items-center space-x-2">
                            <Skeleton className="h-8 w-8" />
                            <Skeleton className="h-8 w-8" />
                            <Skeleton className="h-8 w-8" />
                            <Skeleton className="h-8 w-8" />
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}
