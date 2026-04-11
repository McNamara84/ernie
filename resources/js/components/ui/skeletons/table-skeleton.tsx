import { Skeleton } from '@/components/ui/skeleton';

interface TableSkeletonProps {
    /** Number of skeleton rows to render */
    rows?: number;
    /** Number of columns per row */
    columns?: number;
}

/**
 * Generic table skeleton loader with configurable rows and columns.
 * Uses an HTML table structure that matches the data table layout.
 */
function TableSkeleton({ rows = 5, columns = 4 }: TableSkeletonProps) {
    return (
        <div data-slot="table-skeleton" className="w-full">
            {/* Header skeleton */}
            <div className="flex gap-4 border-b px-4 py-3">
                {Array.from({ length: columns }).map((_, i) => (
                    <Skeleton key={`header-${i}`} className="h-4 flex-1" />
                ))}
            </div>
            {/* Row skeletons */}
            {Array.from({ length: rows }).map((_, rowIndex) => (
                <div key={`row-${rowIndex}`} className="flex gap-4 border-b px-4 py-4">
                    {Array.from({ length: columns }).map((_, colIndex) => (
                        <Skeleton key={`cell-${rowIndex}-${colIndex}`} className="h-4 flex-1" />
                    ))}
                </div>
            ))}
        </div>
    );
}

export { TableSkeleton, type TableSkeletonProps };
