import '@testing-library/jest-dom/vitest';

import { act, renderHook } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import type { PortalFilters } from '@/types/portal';

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
        type: [],
        keywords: [],
        bounds: null,
        temporal: null,
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
                    filters: { query: 'test', type: [], keywords: [],
                        bounds: null, temporal: null, },
                    currentPage: 1,
                }),
            );
            expect(result.current.hasActiveFilters).toBe(true);
        });

        it('reports active filters when type is not "all"', () => {
            const { result } = renderHook(() =>
                usePortalFilters({
                    filters: { query: null, type: ['doi'], keywords: [],
                        bounds: null, temporal: null, },
                    currentPage: 1,
                }),
            );
            expect(result.current.hasActiveFilters).toBe(true);
        });

        it('does not report active filters for empty/whitespace query', () => {
            const { result } = renderHook(() =>
                usePortalFilters({
                    filters: { query: '   ', type: [], keywords: [],
                        bounds: null, temporal: null, },
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
        it('navigates with type[] params', () => {
            const { result } = renderHook(() =>
                usePortalFilters({ filters: defaultFilters, currentPage: 1 }),
            );

            act(() => {
                result.current.setType(['doi']);
            });

            const calledUrl = routerMock.get.mock.calls[0][0] as string;
            expect(calledUrl).toContain('type%5B%5D=doi');
        });

        it('navigates without type param when set to empty array', () => {
            const { result } = renderHook(() =>
                usePortalFilters({
                    filters: { query: null, type: ['doi'], keywords: [],
                        bounds: null, temporal: null, },
                    currentPage: 1,
                }),
            );

            act(() => {
                result.current.setType([]);
            });

            expect(routerMock.get).toHaveBeenCalledWith(
                '/portal',
                {},
                expect.objectContaining({ preserveState: true }),
            );
        });

        it('navigates with multiple type[] params', () => {
            const { result } = renderHook(() =>
                usePortalFilters({
                    filters: { query: null, type: [], keywords: [],
                        bounds: null, temporal: null, },
                    currentPage: 1,
                }),
            );

            act(() => {
                result.current.setType(['dataset', 'software']);
            });

            const calledUrl = routerMock.get.mock.calls[0][0] as string;
            expect(calledUrl).toContain('type%5B%5D=dataset');
            expect(calledUrl).toContain('type%5B%5D=software');
        });

        it('preserves existing query when changing type', () => {
            const { result } = renderHook(() =>
                usePortalFilters({
                    filters: { query: 'existing', type: [], keywords: [],
                        bounds: null, temporal: null, },
                    currentPage: 1,
                }),
            );

            act(() => {
                result.current.setType(['physical-object']);
            });

            const calledUrl = routerMock.get.mock.calls[0][0] as string;
            expect(calledUrl).toContain('q=existing');
            expect(calledUrl).toContain('type%5B%5D=physical-object');
        });
    });

    describe('clearFilters', () => {
        it('navigates to /portal without any params', () => {
            const { result } = renderHook(() =>
                usePortalFilters({
                    filters: { query: 'test', type: ['doi'], keywords: [],
                        bounds: null, temporal: null, },
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
                    filters: { query: null, type: [], keywords: ['Seismology'],
                        bounds: null, temporal: null, },
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
                    filters: { query: 'test', type: ['doi'], keywords: [],
                        bounds: null, temporal: null, },
                    currentPage: 1,
                }),
            );

            act(() => {
                result.current.setKeywords(['Seismology']);
            });

            const calledUrl = routerMock.get.mock.calls[0][0] as string;
            expect(calledUrl).toContain('q=test');
            expect(calledUrl).toContain('type%5B%5D=doi');
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
                    filters: { query: null, type: [], keywords: ['Seismology'],
                        bounds: null, temporal: null, },
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
                    filters: { query: null, type: [], keywords: ['Seismology'],
                        bounds: null, temporal: null, },
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
                    filters: { query: null, type: [], keywords: ['Seismology', 'Geology'],
                        bounds: null, temporal: null, },
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
                    filters: { query: null, type: [], keywords: ['Seismology'],
                        bounds: null, temporal: null, },
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
                    filters: { query: null, type: [], keywords: ['Seismology'],
                        bounds: null, temporal: null, },
                    currentPage: 1,
                }),
            );

            expect(result.current.hasActiveFilters).toBe(true);
        });

        it('does not report active filters when keywords are empty', () => {
            const { result } = renderHook(() =>
                usePortalFilters({
                    filters: { query: null, type: [], keywords: [],
                        bounds: null, temporal: null, },
                    currentPage: 1,
                }),
            );

            expect(result.current.hasActiveFilters).toBe(false);
        });
    });

    describe('hasActiveFilters with exclude_type', () => {
        it('reports active filters when exclude_type is set', () => {
            const { result } = renderHook(() =>
                usePortalFilters({
                    filters: { query: null, type: [], exclude_type: 'physical-object',
                        keywords: [], bounds: null, temporal: null, },
                    currentPage: 1,
                }),
            );

            expect(result.current.hasActiveFilters).toBe(true);
        });

        it('does not report active filters when exclude_type is null', () => {
            const { result } = renderHook(() =>
                usePortalFilters({
                    filters: { query: null, type: [], exclude_type: null,
                        keywords: [], bounds: null, temporal: null, },
                    currentPage: 1,
                }),
            );

            expect(result.current.hasActiveFilters).toBe(false);
        });

        it('does not report active filters when exclude_type is undefined', () => {
            const { result } = renderHook(() =>
                usePortalFilters({
                    filters: { query: null, type: [], keywords: [],
                        bounds: null, temporal: null, },
                    currentPage: 1,
                }),
            );

            expect(result.current.hasActiveFilters).toBe(false);
        });
    });

    describe('legacy doi URL persistence', () => {
        it('preserves type=doi when exclude_type is set and type is empty', () => {
            const { result } = renderHook(() =>
                usePortalFilters({
                    filters: { query: null, type: [], exclude_type: 'physical-object',
                        keywords: [], bounds: null, temporal: null, },
                    currentPage: 1,
                }),
            );

            act(() => {
                result.current.setKeywords(['Seismology']);
            });

            const calledUrl = routerMock.get.mock.calls[0][0] as string;
            expect(calledUrl).toContain('type=doi');
            expect(calledUrl).toContain('keywords%5B%5D=Seismology');
        });

        it('does not emit type=doi when exclude_type is absent', () => {
            const { result } = renderHook(() =>
                usePortalFilters({
                    filters: { query: null, type: [], keywords: [],
                        bounds: null, temporal: null, },
                    currentPage: 1,
                }),
            );

            act(() => {
                result.current.setKeywords(['Seismology']);
            });

            const calledUrl = routerMock.get.mock.calls[0][0] as string;
            expect(calledUrl).not.toContain('type=doi');
        });

        it('does not emit type=doi when type slugs are explicitly set', () => {
            const { result } = renderHook(() =>
                usePortalFilters({
                    filters: { query: null, type: ['dataset'], exclude_type: 'physical-object',
                        keywords: [], bounds: null, temporal: null, },
                    currentPage: 1,
                }),
            );

            act(() => {
                result.current.setKeywords(['Seismology']);
            });

            const calledUrl = routerMock.get.mock.calls[0][0] as string;
            expect(calledUrl).not.toContain('type=doi');
            expect(calledUrl).toContain('type%5B%5D=dataset');
        });
    });

    describe('setBounds', () => {
        it('navigates with bounds URL params', () => {
            const { result } = renderHook(() =>
                usePortalFilters({ filters: defaultFilters, currentPage: 1 }),
            );

            act(() => {
                result.current.setBounds({ north: 53, south: 51, east: 14, west: 12 });
            });

            const calledUrl = routerMock.get.mock.calls[0][0] as string;
            expect(calledUrl).toContain('north=53.000000');
            expect(calledUrl).toContain('south=51.000000');
            expect(calledUrl).toContain('east=14.000000');
            expect(calledUrl).toContain('west=12.000000');
        });

        it('navigates without bounds params when null is passed', () => {
            const { result } = renderHook(() =>
                usePortalFilters({
                    filters: {
                        query: null,
                        type: [],
                        keywords: [],
                        bounds: { north: 53, south: 51, east: 14, west: 12 },
                        temporal: null,
                    },
                    currentPage: 1,
                }),
            );

            act(() => {
                result.current.setBounds(null);
            });

            expect(routerMock.get).toHaveBeenCalledWith(
                '/portal',
                {},
                expect.objectContaining({ preserveState: true }),
            );
        });

        it('preserves existing query and type when setting bounds', () => {
            const { result } = renderHook(() =>
                usePortalFilters({
                    filters: { query: 'test', type: ['doi'], keywords: [],
                        bounds: null, temporal: null, },
                    currentPage: 1,
                }),
            );

            act(() => {
                result.current.setBounds({ north: 53, south: 51, east: 14, west: 12 });
            });

            const calledUrl = routerMock.get.mock.calls[0][0] as string;
            expect(calledUrl).toContain('q=test');
            expect(calledUrl).toContain('type%5B%5D=doi');
            expect(calledUrl).toContain('north=53.000000');
        });

        it('resets page to 1 when setting bounds', () => {
            const { result } = renderHook(() =>
                usePortalFilters({ filters: defaultFilters, currentPage: 3 }),
            );

            act(() => {
                result.current.setBounds({ north: 53, south: 51, east: 14, west: 12 });
            });

            const calledUrl = routerMock.get.mock.calls[0][0] as string;
            expect(calledUrl).not.toContain('page=');
        });
    });

    describe('clearBounds', () => {
        it('navigates without bounds params', () => {
            const { result } = renderHook(() =>
                usePortalFilters({
                    filters: {
                        query: null,
                        type: [],
                        keywords: [],
                        bounds: { north: 53, south: 51, east: 14, west: 12 },
                        temporal: null,
                    },
                    currentPage: 1,
                }),
            );

            act(() => {
                result.current.clearBounds();
            });

            const calledUrl = routerMock.get.mock.calls[0][0] as string;
            expect(calledUrl).not.toContain('north');
            expect(calledUrl).not.toContain('south');
        });
    });

    describe('hasActiveFilters with bounds', () => {
        it('reports active filters when bounds are set', () => {
            const { result } = renderHook(() =>
                usePortalFilters({
                    filters: {
                        query: null,
                        type: [],
                        keywords: [],
                        bounds: { north: 53, south: 51, east: 14, west: 12 },
                        temporal: null,
                    },
                    currentPage: 1,
                }),
            );

            expect(result.current.hasActiveFilters).toBe(true);
        });

        it('does not report active filters when bounds are null', () => {
            const { result } = renderHook(() =>
                usePortalFilters({
                    filters: { query: null, type: [], keywords: [],
                        bounds: null, temporal: null, },
                    currentPage: 1,
                }),
            );

            expect(result.current.hasActiveFilters).toBe(false);
        });
    });

    describe('setTemporal', () => {
        it('navigates with temporal URL params', () => {
            const { result } = renderHook(() =>
                usePortalFilters({ filters: defaultFilters, currentPage: 1 }),
            );

            act(() => {
                result.current.setTemporal({ dateType: 'Created', yearFrom: 2010, yearTo: 2020 });
            });

            const calledUrl = routerMock.get.mock.calls[0][0] as string;
            expect(calledUrl).toContain('date_type=Created');
            expect(calledUrl).toContain('year_from=2010');
            expect(calledUrl).toContain('year_to=2020');
        });

        it('navigates without temporal params when null is passed', () => {
            const { result } = renderHook(() =>
                usePortalFilters({
                    filters: {
                        query: null,
                        type: [],
                        keywords: [],
                        bounds: null,
                        temporal: { dateType: 'Created', yearFrom: 2010, yearTo: 2020 },
                    },
                    currentPage: 1,
                }),
            );

            act(() => {
                result.current.setTemporal(null);
            });

            const calledUrl = routerMock.get.mock.calls[0][0] as string;
            expect(calledUrl).not.toContain('date_type');
            expect(calledUrl).not.toContain('year_from');
            expect(calledUrl).not.toContain('year_to');
        });

        it('preserves existing query and bounds when setting temporal', () => {
            const { result } = renderHook(() =>
                usePortalFilters({
                    filters: {
                        query: 'test',
                        type: ['doi'],
                        keywords: [],
                        bounds: { north: 53, south: 51, east: 14, west: 12 },
                        temporal: null,
                    },
                    currentPage: 1,
                }),
            );

            act(() => {
                result.current.setTemporal({ dateType: 'Collected', yearFrom: 2000, yearTo: 2024 });
            });

            const calledUrl = routerMock.get.mock.calls[0][0] as string;
            expect(calledUrl).toContain('q=test');
            expect(calledUrl).toContain('type%5B%5D=doi');
            expect(calledUrl).toContain('north=53.000000');
            expect(calledUrl).toContain('date_type=Collected');
            expect(calledUrl).toContain('year_from=2000');
            expect(calledUrl).toContain('year_to=2024');
        });

        it('resets page to 1 when setting temporal', () => {
            const { result } = renderHook(() =>
                usePortalFilters({ filters: defaultFilters, currentPage: 3 }),
            );

            act(() => {
                result.current.setTemporal({ dateType: 'Created', yearFrom: 2010, yearTo: 2020 });
            });

            const calledUrl = routerMock.get.mock.calls[0][0] as string;
            expect(calledUrl).not.toContain('page=');
        });
    });

    describe('hasActiveFilters with temporal', () => {
        it('reports active filters when temporal is set', () => {
            const { result } = renderHook(() =>
                usePortalFilters({
                    filters: {
                        query: null,
                        type: [],
                        keywords: [],
                        bounds: null,
                        temporal: { dateType: 'Created', yearFrom: 2010, yearTo: 2020 },
                    },
                    currentPage: 1,
                }),
            );

            expect(result.current.hasActiveFilters).toBe(true);
        });

        it('does not report active filters when temporal is null', () => {
            const { result } = renderHook(() =>
                usePortalFilters({
                    filters: { query: null, type: [], keywords: [],
                        bounds: null, temporal: null, },
                    currentPage: 1,
                }),
            );

            expect(result.current.hasActiveFilters).toBe(false);
        });
    });
});
