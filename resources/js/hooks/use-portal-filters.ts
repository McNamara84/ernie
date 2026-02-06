import { router } from '@inertiajs/react';
import { useCallback, useMemo } from 'react';

import type { PortalFilters, PortalTypeFilter } from '@/types/portal';

interface UsePortalFiltersOptions {
    filters: PortalFilters;
    currentPage: number;
}

interface UsePortalFiltersReturn {
    filters: PortalFilters;
    setSearch: (query: string) => void;
    setType: (type: PortalTypeFilter) => void;
    clearFilters: () => void;
    hasActiveFilters: boolean;
}

/**
 * Hook for managing portal filter state via URL parameters.
 *
 * All filter changes are persisted to the URL for bookmarking/sharing
 * and trigger an Inertia page reload to fetch filtered results.
 */
export function usePortalFilters({ filters, currentPage }: UsePortalFiltersOptions): UsePortalFiltersReturn {
    const updateFilters = useCallback(
        (newFilters: Partial<PortalFilters>, resetPage = true) => {
            const params = new URLSearchParams();

            const query = newFilters.query !== undefined ? newFilters.query : filters.query;
            const type = newFilters.type !== undefined ? newFilters.type : filters.type;

            if (query && query.trim() !== '') {
                params.set('q', query.trim());
            }

            if (type && type !== 'all') {
                params.set('type', type);
            }

            if (!resetPage && currentPage > 1) {
                params.set('page', String(currentPage));
            }

            const queryString = params.toString();
            const url = queryString ? `/portal?${queryString}` : '/portal';

            router.get(url, {}, { preserveState: true, preserveScroll: true });
        },
        [filters, currentPage],
    );

    const setSearch = useCallback(
        (query: string) => {
            updateFilters({ query }, true);
        },
        [updateFilters],
    );

    const setType = useCallback(
        (type: PortalTypeFilter) => {
            updateFilters({ type }, true);
        },
        [updateFilters],
    );

    const clearFilters = useCallback(() => {
        router.get('/portal', {}, { preserveState: true, preserveScroll: true });
    }, []);

    const hasActiveFilters = useMemo(() => {
        return (filters.query !== null && filters.query.trim() !== '') || filters.type !== 'all';
    }, [filters]);

    return {
        filters,
        setSearch,
        setType,
        clearFilters,
        hasActiveFilters,
    };
}
