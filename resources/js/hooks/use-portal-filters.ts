import { router } from '@inertiajs/react';
import { useCallback, useMemo } from 'react';

import type { GeoBounds, PortalFilters, TemporalFilterValue } from '@/types/portal';

interface UsePortalFiltersOptions {
    filters: PortalFilters;
    currentPage: number;
}

interface UsePortalFiltersReturn {
    filters: PortalFilters;
    setSearch: (query: string) => void;
    setType: (type: string[]) => void;
    setKeywords: (keywords: string[]) => void;
    addKeyword: (keyword: string) => void;
    removeKeyword: (keyword: string) => void;
    setBounds: (bounds: GeoBounds | null) => void;
    clearBounds: () => void;
    setTemporal: (temporal: TemporalFilterValue | null) => void;
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
            const bounds = newFilters.bounds !== undefined ? newFilters.bounds : filters.bounds;
            const temporal = newFilters.temporal !== undefined ? newFilters.temporal : filters.temporal;

            if (query && query.trim() !== '') {
                params.set('q', query.trim());
            }

            if (type && type.length > 0) {
                type.forEach((slug) => {
                    params.append('type[]', slug);
                });
            }

            if (keywords && keywords.length > 0) {
                keywords.forEach((kw) => {
                    params.append('keywords[]', kw);
                });
            }

            if (bounds) {
                params.set('north', bounds.north.toFixed(6));
                params.set('south', bounds.south.toFixed(6));
                params.set('east', bounds.east.toFixed(6));
                params.set('west', bounds.west.toFixed(6));
            }

            if (temporal) {
                params.set('date_type', temporal.dateType);
                params.set('year_from', String(temporal.yearFrom));
                params.set('year_to', String(temporal.yearTo));
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
        (type: string[]) => {
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

    const setBounds = useCallback(
        (bounds: GeoBounds | null) => {
            updateFilters({ bounds }, true);
        },
        [updateFilters],
    );

    const clearBounds = useCallback(() => {
        updateFilters({ bounds: null }, true);
    }, [updateFilters]);

    const setTemporal = useCallback(
        (temporal: TemporalFilterValue | null) => {
            updateFilters({ temporal }, true);
        },
        [updateFilters],
    );

    const clearFilters = useCallback(() => {
        router.get('/portal', {}, { preserveState: true, preserveScroll: true });
    }, []);

    const hasActiveFilters = useMemo(() => {
        return (
            (filters.query !== null && filters.query.trim() !== '') ||
            (filters.type !== undefined && filters.type.length > 0) ||
            (filters.keywords !== undefined && filters.keywords.length > 0) ||
            filters.bounds !== null ||
            filters.temporal !== null
        );
    }, [filters]);

    return {
        filters,
        setSearch,
        setType,
        setKeywords,
        addKeyword,
        removeKeyword,
        setBounds,
        clearBounds,
        setTemporal,
        clearFilters,
        hasActiveFilters,
    };
}
