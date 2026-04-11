import { Skeleton } from '@/components/ui/skeleton';

interface FilterSkeletonProps {
    /** Number of filter input placeholders */
    filters?: number;
}

/**
 * Skeleton loader for filter bars (portal, resources list).
 */
function FilterSkeleton({ filters = 3 }: FilterSkeletonProps) {
    return (
        <div data-slot="filter-skeleton" className="flex flex-wrap items-center gap-4">
            {Array.from({ length: filters }).map((_, i) => (
                <Skeleton key={`filter-${i}`} className="h-9 w-40" />
            ))}
            <Skeleton className="h-9 w-24" />
        </div>
    );
}

export { FilterSkeleton, type FilterSkeletonProps };
