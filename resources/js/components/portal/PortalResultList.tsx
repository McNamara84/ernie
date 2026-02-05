import { ChevronLeft, ChevronRight, Search } from 'lucide-react';

import { PortalResultCard } from '@/components/portal/PortalResultCard';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { ScrollArea } from '@/components/ui/scroll-area';
import { Skeleton } from '@/components/ui/skeleton';
import type { PortalPagination, PortalResource } from '@/types/portal';

interface PortalResultListProps {
    resources: PortalResource[];
    pagination: PortalPagination;
    onPageChange: (page: number) => void;
    isLoading?: boolean;
}

/**
 * Paginated list of portal search results.
 */
export function PortalResultList({
    resources,
    pagination,
    onPageChange,
    isLoading = false,
}: PortalResultListProps) {
    if (isLoading) {
        return (
            <div className="flex-1 space-y-2 p-4">
                {Array.from({ length: 10 }).map((_, i) => (
                    <Skeleton key={i} className="h-10 w-full rounded-md" />
                ))}
            </div>
        );
    }

    if (resources.length === 0) {
        return (
            <div className="flex flex-1 items-center justify-center p-8">
                <EmptyState
                    icon={<Search className="h-8 w-8" />}
                    title="No results found"
                    description="Try adjusting your search or filters to find what you're looking for."
                />
            </div>
        );
    }

    const { current_page, last_page, from, to, total } = pagination;

    return (
        <div className="flex flex-1 flex-col">
            {/* Results Header */}
            <div className="border-b px-4 py-2">
                <p className="text-sm text-muted-foreground">
                    Showing {from}-{to} of {total.toLocaleString()} results
                </p>
            </div>

            {/* Results List */}
            <ScrollArea className="flex-1">
                <div className="flex flex-col gap-2 p-4">
                    {resources.map((resource) => (
                        <PortalResultCard key={resource.id} resource={resource} />
                    ))}
                </div>
            </ScrollArea>

            {/* Pagination */}
            {last_page > 1 && (
                <div className="flex items-center justify-center gap-2 border-t px-4 py-3">
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => onPageChange(current_page - 1)}
                        disabled={current_page === 1}
                    >
                        <ChevronLeft className="mr-1 h-4 w-4" />
                        Previous
                    </Button>

                    <div className="flex items-center gap-1">
                        {generatePageNumbers(current_page, last_page).map((page, index) =>
                            page === '...' ? (
                                <span key={`ellipsis-${index}`} className="px-2 text-muted-foreground">
                                    ...
                                </span>
                            ) : (
                                <Button
                                    key={page}
                                    variant={page === current_page ? 'default' : 'outline'}
                                    size="sm"
                                    onClick={() => onPageChange(page as number)}
                                    className="min-w-[2.5rem]"
                                >
                                    {page}
                                </Button>
                            ),
                        )}
                    </div>

                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => onPageChange(current_page + 1)}
                        disabled={current_page === last_page}
                    >
                        Next
                        <ChevronRight className="ml-1 h-4 w-4" />
                    </Button>
                </div>
            )}
        </div>
    );
}

/**
 * Generate page numbers with ellipsis for large page counts.
 */
function generatePageNumbers(current: number, total: number): (number | '...')[] {
    if (total <= 7) {
        return Array.from({ length: total }, (_, i) => i + 1);
    }

    const pages: (number | '...')[] = [];

    // Always show first page
    pages.push(1);

    if (current > 3) {
        pages.push('...');
    }

    // Show pages around current
    const start = Math.max(2, current - 1);
    const end = Math.min(total - 1, current + 1);

    for (let i = start; i <= end; i++) {
        pages.push(i);
    }

    if (current < total - 2) {
        pages.push('...');
    }

    // Always show last page
    if (total > 1) {
        pages.push(total);
    }

    return pages;
}
