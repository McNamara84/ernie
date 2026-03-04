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
    setKeywords: (keywords: string[]) => void;
    addKeyword: (keyword: string) => void;
    removeKeyword: (keyword: string) => void;
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
            const keywords = newFilters.keywords !== undefined ? newFilters.keywords : filters.keywords;

            if (query && query.trim() !== '') {
                params.set('q', query.trim());
            }

            if (type && type !== 'all') {
                params.set('type', type);
            }

            if (keywords && keywords.length > 0) {
                keywords.forEach((kw) => {
                    params.append('keywords[]', kw);
                });
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

    const setKeywords = useCallback(
        (keywords: string[]) => {
            updateFilters({ keywords }, true);
        },
        [updateFilters],
    );

    const addKeyword = useCallback(
        (keyword: string) => {
            const current = filters.keywords ?? [];
            if (!current.includes(keyword)) {
                updateFilters({ keywords: [...current, keyword] }, true);
            }
        },
        [updateFilters, filters.keywords],
    );

    const removeKeyword = useCallback(
        (keyword: string) => {
            const current = filters.keywords ?? [];
            updateFilters({ keywords: current.filter((k) => k !== keyword) }, true);
        },
        [updateFilters, filters.keywords],
    );

    const clearFilters = useCallback(() => {
        router.get('/portal', {}, { preserveState: true, preserveScroll: true });
    }, []);

    const hasActiveFilters = useMemo(() => {
        return (
            (filters.query !== null && filters.query.trim() !== '') ||
            filters.type !== 'all' ||
            (filters.keywords !== undefined && filters.keywords.length > 0)
        );
    }, [filters]);

    return {
        filters,
        setSearch,
        setType,
        setKeywords,
        addKeyword,
        removeKeyword,
        clearFilters,
        hasActiveFilters,
    };
}
