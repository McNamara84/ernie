import { Skeleton } from '@/components/ui/skeleton';

interface CardSkeletonProps {
    /** Number of card placeholders to render */
    count?: number;
    /** Show a header area in each card */
    showHeader?: boolean;
}

/**
 * Card skeleton loader matching the shadcn/ui Card layout.
 */
function CardSkeleton({ count = 1, showHeader = true }: CardSkeletonProps) {
    return (
        <div data-slot="card-skeleton" className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
            {Array.from({ length: count }).map((_, i) => (
                <div key={`card-${i}`} className="rounded-xl border bg-card p-6">
                    {showHeader && (
                        <div className="mb-4 space-y-2">
                            <Skeleton className="h-5 w-3/4" />
                            <Skeleton className="h-4 w-1/2" />
                        </div>
                    )}
                    <div className="space-y-3">
                        <Skeleton className="h-4 w-full" />
                        <Skeleton className="h-4 w-5/6" />
                        <Skeleton className="h-4 w-4/6" />
                    </div>
                </div>
            ))}
        </div>
    );
}

export { CardSkeleton, type CardSkeletonProps };
