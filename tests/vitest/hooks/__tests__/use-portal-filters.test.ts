import '@testing-library/jest-dom/vitest';

import { act, renderHook } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import type { PortalFilters, PortalTypeFilter } from '@/types/portal';

const routerMock = vi.hoisted(() => ({
    get: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
    router: routerMock,
}));

import { usePortalFilters } from '@/hooks/use-portal-filters';

describe('usePortalFilters', () => {
    const defaultFilters: PortalFilters = {
        query: null,
        type: 'all',
    };

    beforeEach(() => {
        vi.clearAllMocks();
    });

    describe('initial state', () => {
        it('returns the provided filters', () => {
            const { result } = renderHook(() =>
                usePortalFilters({ filters: defaultFilters, currentPage: 1 }),
            );
            expect(result.current.filters).toEqual(defaultFilters);
        });

        it('reports no active filters when defaults are used', () => {
            const { result } = renderHook(() =>
                usePortalFilters({ filters: defaultFilters, currentPage: 1 }),
            );
            expect(result.current.hasActiveFilters).toBe(false);
        });

        it('reports active filters when query is set', () => {
            const { result } = renderHook(() =>
                usePortalFilters({
                    filters: { query: 'test', type: 'all' },
                    currentPage: 1,
                }),
            );
            expect(result.current.hasActiveFilters).toBe(true);
        });

        it('reports active filters when type is not "all"', () => {
            const { result } = renderHook(() =>
                usePortalFilters({
                    filters: { query: null, type: 'doi' },
                    currentPage: 1,
                }),
            );
            expect(result.current.hasActiveFilters).toBe(true);
        });

        it('does not report active filters for empty/whitespace query', () => {
            const { result } = renderHook(() =>
                usePortalFilters({
                    filters: { query: '   ', type: 'all' },
                    currentPage: 1,
                }),
            );
            expect(result.current.hasActiveFilters).toBe(false);
        });
    });

    describe('setSearch', () => {
        it('navigates to portal with query param', () => {
            const { result } = renderHook(() =>
                usePortalFilters({ filters: defaultFilters, currentPage: 1 }),
            );

            act(() => {
                result.current.setSearch('earthquake');
            });

            expect(routerMock.get).toHaveBeenCalledWith(
                '/portal?q=earthquake',
                {},
                { preserveState: true, preserveScroll: true },
            );
        });

        it('trims whitespace from query', () => {
            const { result } = renderHook(() =>
                usePortalFilters({ filters: defaultFilters, currentPage: 1 }),
            );

            act(() => {
                result.current.setSearch('  test  ');
            });

            expect(routerMock.get).toHaveBeenCalledWith(
                '/portal?q=test',
                {},
                expect.objectContaining({ preserveState: true }),
            );
        });

        it('navigates without query param when search is empty', () => {
            const { result } = renderHook(() =>
                usePortalFilters({ filters: defaultFilters, currentPage: 1 }),
            );

            act(() => {
                result.current.setSearch('');
            });

            expect(routerMock.get).toHaveBeenCalledWith(
                '/portal',
                {},
                expect.objectContaining({ preserveState: true }),
            );
        });

        it('resets page to 1 when searching', () => {
            const { result } = renderHook(() =>
                usePortalFilters({ filters: defaultFilters, currentPage: 3 }),
            );

            act(() => {
                result.current.setSearch('search term');
            });

            // Should NOT include page param (reset to 1)
            const calledUrl = routerMock.get.mock.calls[0][0] as string;
            expect(calledUrl).not.toContain('page=');
        });
    });

    describe('setType', () => {
        it('navigates with type param', () => {
            const { result } = renderHook(() =>
                usePortalFilters({ filters: defaultFilters, currentPage: 1 }),
            );

            act(() => {
                result.current.setType('doi' as PortalTypeFilter);
            });

            expect(routerMock.get).toHaveBeenCalledWith(
                '/portal?type=doi',
                {},
                expect.objectContaining({ preserveState: true }),
            );
        });

        it('navigates without type param when set to "all"', () => {
            const { result } = renderHook(() =>
                usePortalFilters({
                    filters: { query: null, type: 'doi' },
                    currentPage: 1,
                }),
            );

            act(() => {
                result.current.setType('all' as PortalTypeFilter);
            });

            expect(routerMock.get).toHaveBeenCalledWith(
                '/portal',
                {},
                expect.objectContaining({ preserveState: true }),
            );
        });

        it('preserves existing query when changing type', () => {
            const { result } = renderHook(() =>
                usePortalFilters({
                    filters: { query: 'existing', type: 'all' },
                    currentPage: 1,
                }),
            );

            act(() => {
                result.current.setType('igsn' as PortalTypeFilter);
            });

            const calledUrl = routerMock.get.mock.calls[0][0] as string;
            expect(calledUrl).toContain('q=existing');
            expect(calledUrl).toContain('type=igsn');
        });
    });

    describe('clearFilters', () => {
        it('navigates to /portal without any params', () => {
            const { result } = renderHook(() =>
                usePortalFilters({
                    filters: { query: 'test', type: 'doi' },
                    currentPage: 2,
                }),
            );

            act(() => {
                result.current.clearFilters();
            });

            expect(routerMock.get).toHaveBeenCalledWith(
                '/portal',
                {},
                { preserveState: true, preserveScroll: true },
            );
        });
    });
});
