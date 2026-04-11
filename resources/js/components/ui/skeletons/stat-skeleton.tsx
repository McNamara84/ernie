import { Skeleton } from '@/components/ui/skeleton';

interface StatSkeletonProps {
    /** Number of stat card placeholders */
    count?: number;
}

/**
 * Skeleton loader for statistics/metric cards (dashboard, analytics).
 */
function StatSkeleton({ count = 4 }: StatSkeletonProps) {
    return (
        <div data-slot="stat-skeleton" className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
            {Array.from({ length: count }).map((_, i) => (
                <div key={`stat-${i}`} className="rounded-xl border bg-card p-6">
                    <Skeleton className="mb-2 h-4 w-20" />
                    <Skeleton className="h-8 w-16" />
                </div>
            ))}
        </div>
    );
}

export { StatSkeleton, type StatSkeletonProps };
