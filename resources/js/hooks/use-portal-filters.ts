import { router } from '@inertiajs/react';
import { useCallback, useMemo } from 'react';

import { buildPortalFilterUrl, mergePortalFilters } from '@/lib/portal-filter-url';
import type { GeoBounds, PortalFilters, TemporalFilterValue } from '@/types/portal';

interface UsePortalFiltersOptions {
    filters: PortalFilters;
    currentPage: number;
}

interface UsePortalFiltersReturn {
    filters: PortalFilters;
    setSearch: (query: string) => void;
    setType: (type: string[]) => void;
    setDatacenter: (datacenter: string[]) => void;
    setKeywords: (keywords: string[]) => void;
    addKeyword: (keyword: string) => void;
    removeKeyword: (keyword: string) => void;
    setFreeKeywords: (keywords: string[]) => void;
    setThesaurusKeywords: (nodeIds: string[]) => void;
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
            const resolvedFilters = mergePortalFilters(filters, newFilters);
            const url = buildPortalFilterUrl(resolvedFilters, !resetPage && currentPage > 1 ? currentPage : null);

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

    const setDatacenter = useCallback(
        (datacenter: string[]) => {
            updateFilters({ datacenter }, true);
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

    const setFreeKeywords = useCallback(
        (freeKeywords: string[]) => {
            updateFilters({ freeKeywords }, true);
        },
        [updateFilters],
    );

    const setThesaurusKeywords = useCallback(
        (thesaurusKeywords: string[]) => {
            updateFilters({ thesaurusKeywords }, true);
        },
        [updateFilters],
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
            filters.exclude_type != null ||
            (filters.datacenter !== undefined && filters.datacenter.length > 0) ||
            (filters.keywords !== undefined && filters.keywords.length > 0) ||
            (filters.freeKeywords !== undefined && filters.freeKeywords.length > 0) ||
            (filters.thesaurusKeywords !== undefined && filters.thesaurusKeywords.length > 0) ||
            filters.bounds !== null ||
            filters.temporal !== null
        );
    }, [filters]);

    return {
        filters,
        setSearch,
        setType,
        setDatacenter,
        setKeywords,
        addKeyword,
        removeKeyword,
        setFreeKeywords,
        setThesaurusKeywords,
        setBounds,
        clearBounds,
        setTemporal,
        clearFilters,
        hasActiveFilters,
    };
}
