import { ChevronLeft, ChevronRight, Search } from 'lucide-react';

import { PortalResultCard } from '@/components/portal/PortalResultCard';
import { Badge } from '@/components/ui/badge';
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
    hasActiveFilters?: boolean;
    onClearFilters?: () => void;
}

/**
 * Paginated list of portal search results.
 */
export function PortalResultList({
    resources,
    pagination,
    onPageChange,
    isLoading = false,
    hasActiveFilters = false,
    onClearFilters,
}: PortalResultListProps) {
    if (isLoading && resources.length === 0) {
        return (
            <div className="flex-1 space-y-3 p-4" data-testid="portal-results-loading">
                <div className="flex items-center justify-between rounded-xl border bg-background/80 px-4 py-3">
                    <div className="space-y-2">
                        <Skeleton className="h-4 w-28" />
                        <Skeleton className="h-4 w-48" />
                    </div>
                    <Badge variant="secondary">Refreshing results...</Badge>
                </div>
                {Array.from({ length: 8 }).map((_, i) => (
                    <Skeleton key={i} className="h-28 w-full rounded-xl" />
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
                    description={
                        hasActiveFilters
                            ? 'Try clearing one or more filters to widen the result set.'
                            : 'Try adjusting your search or filters to find what you\'re looking for.'
                    }
                    action={
                        hasActiveFilters && onClearFilters
                            ? {
                                  label: 'Clear filters',
                                  onClick: onClearFilters,
                              }
                            : undefined
                    }
                />
            </div>
        );
    }

    const { current_page, last_page, from, to, total } = pagination;

    return (
        <div className="flex flex-1 flex-col" data-testid="portal-results-list" aria-busy={isLoading}>
            {/* Results Header */}
            <div className="flex items-center justify-between gap-3 border-b px-4 py-3">
                <p className="text-sm text-muted-foreground" aria-live="polite">
                    Showing {from}-{to} of {total.toLocaleString()} results
                </p>
                {isLoading && (
                    <Badge variant="secondary" data-testid="portal-results-refreshing">
                        Refreshing results...
                    </Badge>
                )}
            </div>

            {/* Results List */}
            <ScrollArea className="flex-1">
                <div className={`flex flex-col gap-2 p-4 transition-opacity ${isLoading ? 'opacity-70' : 'opacity-100'}`}>
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
                        disabled={current_page === 1 || isLoading}
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
                                    disabled={isLoading}
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
                        disabled={current_page === last_page || isLoading}
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
