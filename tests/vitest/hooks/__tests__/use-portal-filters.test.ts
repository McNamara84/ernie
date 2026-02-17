import { renderHook, act } from '@testing-library/react';
import { describe, expect, it, vi, beforeEach } from 'vitest';

const routerMock = vi.hoisted(() => ({ get: vi.fn() }));

vi.mock('@inertiajs/react', () => ({
    router: routerMock,
}));

import { usePortalFilters } from '@/hooks/use-portal-filters';

describe('usePortalFilters', () => {
    beforeEach(() => {
        routerMock.get.mockClear();
    });

    it('returns the current filters', () => {
        const { result } = renderHook(() =>
            usePortalFilters({
                filters: { query: 'test', type: 'all' },
                currentPage: 1,
            }),
        );
        expect(result.current.filters).toEqual({ query: 'test', type: 'all' });
    });

    it('hasActiveFilters is false when no filters active', () => {
        const { result } = renderHook(() =>
            usePortalFilters({
                filters: { query: null, type: 'all' },
                currentPage: 1,
            }),
        );
        expect(result.current.hasActiveFilters).toBe(false);
    });

    it('hasActiveFilters is true when query is set', () => {
        const { result } = renderHook(() =>
            usePortalFilters({
                filters: { query: 'geology', type: 'all' },
                currentPage: 1,
            }),
        );
        expect(result.current.hasActiveFilters).toBe(true);
    });

    it('hasActiveFilters is true when type is not all', () => {
        const { result } = renderHook(() =>
            usePortalFilters({
                filters: { query: null, type: 'Dataset' },
                currentPage: 1,
            }),
        );
        expect(result.current.hasActiveFilters).toBe(true);
    });

    it('setSearch calls router.get with query parameter', () => {
        const { result } = renderHook(() =>
            usePortalFilters({
                filters: { query: null, type: 'all' },
                currentPage: 1,
            }),
        );

        act(() => {
            result.current.setSearch('climate');
        });

        expect(routerMock.get).toHaveBeenCalledWith(
            expect.stringContaining('q=climate'),
            {},
            expect.objectContaining({ preserveState: true }),
        );
    });

    it('setType calls router.get with type parameter', () => {
        const { result } = renderHook(() =>
            usePortalFilters({
                filters: { query: null, type: 'all' },
                currentPage: 1,
            }),
        );

        act(() => {
            result.current.setType('Dataset');
        });

        expect(routerMock.get).toHaveBeenCalledWith(
            expect.stringContaining('type=Dataset'),
            {},
            expect.objectContaining({ preserveState: true }),
        );
    });

    it('clearFilters navigates to /portal without params', () => {
        const { result } = renderHook(() =>
            usePortalFilters({
                filters: { query: 'test', type: 'Dataset' },
                currentPage: 2,
            }),
        );

        act(() => {
            result.current.clearFilters();
        });

        expect(routerMock.get).toHaveBeenCalledWith('/portal', {}, expect.any(Object));
    });

    it('setSearch preserves existing type filter', () => {
        const { result } = renderHook(() =>
            usePortalFilters({
                filters: { query: null, type: 'Software' },
                currentPage: 1,
            }),
        );

        act(() => {
            result.current.setSearch('ocean');
        });

        expect(routerMock.get).toHaveBeenCalledWith(
            expect.stringMatching(/q=ocean.*type=Software|type=Software.*q=ocean/),
            {},
            expect.any(Object),
        );
    });

    it('hasActiveFilters handles empty string query', () => {
        const { result } = renderHook(() =>
            usePortalFilters({
                filters: { query: '   ', type: 'all' },
                currentPage: 1,
            }),
        );
        expect(result.current.hasActiveFilters).toBe(false);
    });
});
