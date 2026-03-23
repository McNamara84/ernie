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
        keywords: [],
        bounds: null,
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
                    filters: { query: 'test', type: 'all', keywords: [],
                        bounds: null, },
                    currentPage: 1,
                }),
            );
            expect(result.current.hasActiveFilters).toBe(true);
        });

        it('reports active filters when type is not "all"', () => {
            const { result } = renderHook(() =>
                usePortalFilters({
                    filters: { query: null, type: 'doi', keywords: [],
                        bounds: null, },
                    currentPage: 1,
                }),
            );
            expect(result.current.hasActiveFilters).toBe(true);
        });

        it('does not report active filters for empty/whitespace query', () => {
            const { result } = renderHook(() =>
                usePortalFilters({
                    filters: { query: '   ', type: 'all', keywords: [],
                        bounds: null, },
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
                    filters: { query: null, type: 'doi', keywords: [],
                        bounds: null, },
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
                    filters: { query: 'existing', type: 'all', keywords: [],
                        bounds: null, },
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
                    filters: { query: 'test', type: 'doi', keywords: [],
                        bounds: null, },
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

    describe('setKeywords', () => {
        it('navigates with keywords[] URL params', () => {
            const { result } = renderHook(() =>
                usePortalFilters({ filters: defaultFilters, currentPage: 1 }),
            );

            act(() => {
                result.current.setKeywords(['Seismology', 'Geology']);
            });

            const calledUrl = routerMock.get.mock.calls[0][0] as string;
            expect(calledUrl).toContain('keywords%5B%5D=Seismology');
            expect(calledUrl).toContain('keywords%5B%5D=Geology');
        });

        it('navigates without keywords param when empty array is passed', () => {
            const { result } = renderHook(() =>
                usePortalFilters({
                    filters: { query: null, type: 'all', keywords: ['Seismology'],
                        bounds: null, },
                    currentPage: 1,
                }),
            );

            act(() => {
                result.current.setKeywords([]);
            });

            expect(routerMock.get).toHaveBeenCalledWith(
                '/portal',
                {},
                expect.objectContaining({ preserveState: true }),
            );
        });

        it('preserves existing query and type when setting keywords', () => {
            const { result } = renderHook(() =>
                usePortalFilters({
                    filters: { query: 'test', type: 'doi', keywords: [],
                        bounds: null, },
                    currentPage: 1,
                }),
            );

            act(() => {
                result.current.setKeywords(['Seismology']);
            });

            const calledUrl = routerMock.get.mock.calls[0][0] as string;
            expect(calledUrl).toContain('q=test');
            expect(calledUrl).toContain('type=doi');
            expect(calledUrl).toContain('keywords%5B%5D=Seismology');
        });

        it('resets page to 1 when setting keywords', () => {
            const { result } = renderHook(() =>
                usePortalFilters({ filters: defaultFilters, currentPage: 3 }),
            );

            act(() => {
                result.current.setKeywords(['Seismology']);
            });

            const calledUrl = routerMock.get.mock.calls[0][0] as string;
            expect(calledUrl).not.toContain('page=');
        });
    });

    describe('addKeyword', () => {
        it('adds a keyword to the existing list', () => {
            const { result } = renderHook(() =>
                usePortalFilters({
                    filters: { query: null, type: 'all', keywords: ['Seismology'],
                        bounds: null, },
                    currentPage: 1,
                }),
            );

            act(() => {
                result.current.addKeyword('Geology');
            });

            const calledUrl = routerMock.get.mock.calls[0][0] as string;
            expect(calledUrl).toContain('keywords%5B%5D=Seismology');
            expect(calledUrl).toContain('keywords%5B%5D=Geology');
        });

        it('does not add duplicate keywords', () => {
            const { result } = renderHook(() =>
                usePortalFilters({
                    filters: { query: null, type: 'all', keywords: ['Seismology'],
                        bounds: null, },
                    currentPage: 1,
                }),
            );

            act(() => {
                result.current.addKeyword('Seismology');
            });

            expect(routerMock.get).not.toHaveBeenCalled();
        });

        it('adds a keyword when list is initially empty', () => {
            const { result } = renderHook(() =>
                usePortalFilters({ filters: defaultFilters, currentPage: 1 }),
            );

            act(() => {
                result.current.addKeyword('Geology');
            });

            const calledUrl = routerMock.get.mock.calls[0][0] as string;
            expect(calledUrl).toContain('keywords%5B%5D=Geology');
        });
    });

    describe('removeKeyword', () => {
        it('removes a specific keyword from the list', () => {
            const { result } = renderHook(() =>
                usePortalFilters({
                    filters: { query: null, type: 'all', keywords: ['Seismology', 'Geology'],
                        bounds: null, },
                    currentPage: 1,
                }),
            );

            act(() => {
                result.current.removeKeyword('Seismology');
            });

            const calledUrl = routerMock.get.mock.calls[0][0] as string;
            expect(calledUrl).toContain('keywords%5B%5D=Geology');
            expect(calledUrl).not.toContain('Seismology');
        });

        it('navigates without keywords param when removing the last keyword', () => {
            const { result } = renderHook(() =>
                usePortalFilters({
                    filters: { query: null, type: 'all', keywords: ['Seismology'],
                        bounds: null, },
                    currentPage: 1,
                }),
            );

            act(() => {
                result.current.removeKeyword('Seismology');
            });

            expect(routerMock.get).toHaveBeenCalledWith(
                '/portal',
                {},
                expect.objectContaining({ preserveState: true }),
            );
        });
    });

    describe('hasActiveFilters with keywords', () => {
        it('reports active filters when keywords are non-empty', () => {
            const { result } = renderHook(() =>
                usePortalFilters({
                    filters: { query: null, type: 'all', keywords: ['Seismology'],
                        bounds: null, },
                    currentPage: 1,
                }),
            );

            expect(result.current.hasActiveFilters).toBe(true);
        });

        it('does not report active filters when keywords are empty', () => {
            const { result } = renderHook(() =>
                usePortalFilters({
                    filters: { query: null, type: 'all', keywords: [],
                        bounds: null, },
                    currentPage: 1,
                }),
            );

            expect(result.current.hasActiveFilters).toBe(false);
        });
    });
});
