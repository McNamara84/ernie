import '@testing-library/jest-dom/vitest';

import userEvent from '@testing-library/user-event';
import { act, render, screen } from '@tests/vitest/utils/render';
import type React from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import Portal from '@/pages/portal';
import type { PortalPageProps } from '@/types/portal';

const routerMock = vi.hoisted(() => ({ get: vi.fn() }));

vi.mock('@inertiajs/react', () => ({
    Head: ({ children }: { children?: React.ReactNode }) => <>{children}</>,
    router: routerMock,
}));

vi.mock('@/layouts/portal-layout', () => ({
    default: ({ children }: { children?: React.ReactNode }) => <div data-testid="portal-layout">{children}</div>,
}));

const setSearchMock = vi.fn();
const setTypeMock = vi.fn();
const clearFiltersMock = vi.fn();
const setBoundsMock = vi.fn();
const clearBoundsMock = vi.fn();

vi.mock('@/hooks/use-portal-filters', () => ({
    usePortalFilters: () => ({
        setSearch: setSearchMock,
        setType: setTypeMock,
        setKeywords: vi.fn(),
        addKeyword: vi.fn(),
        removeKeyword: vi.fn(),
        setBounds: setBoundsMock,
        clearBounds: clearBoundsMock,
        clearFilters: clearFiltersMock,
        hasActiveFilters: false,
    }),
}));

vi.mock('@/components/portal/PortalFilters', () => ({
    PortalFilters: ({
        // eslint-disable-next-line @typescript-eslint/no-unused-vars
        filters,
        totalResults,
        onSearchChange,
        onClearFilters,
        geoFilterEnabled,
        onGeoFilterToggle,
        onBoundsChange,
    }: {
        filters: PortalPageProps['filters'];
        totalResults: number;
        onSearchChange: (s: string) => void;
        onClearFilters: () => void;
        geoFilterEnabled?: boolean;
        onGeoFilterToggle?: (enabled: boolean) => void;
        onBoundsChange?: (bounds: { north: number; south: number; east: number; west: number } | null) => void;
    }) => (
        <div data-testid="portal-filters">
            <span data-testid="total-results">{totalResults}</span>
            <input data-testid="search-input" onChange={(e) => onSearchChange(e.target.value)} />
            <button data-testid="clear-filters" onClick={onClearFilters}>
                Clear
            </button>
            <span data-testid="geo-filter-enabled">{String(geoFilterEnabled ?? false)}</span>
            {onGeoFilterToggle && (
                <button data-testid="geo-toggle" onClick={() => onGeoFilterToggle(!geoFilterEnabled)}>
                    Toggle Geo
                </button>
            )}
            {onBoundsChange && (
                <>
                    <button
                        data-testid="apply-bounds"
                        onClick={() => onBoundsChange({ north: 53, south: 51, east: 14, west: 12 })}
                    >
                        Apply Bounds
                    </button>
                    <button
                        data-testid="clear-bounds"
                        onClick={() => onBoundsChange(null)}
                    >
                        Clear Bounds
                    </button>
                </>
            )}
        </div>
    ),
}));

vi.mock('@/components/portal/PortalMap', () => ({
    PortalMap: ({ resources, onViewportChange, geoFilterEnabled }: { resources: unknown[]; onViewportChange?: (bounds: { north: number; south: number; east: number; west: number }) => void; geoFilterEnabled?: boolean }) => (
        <div data-testid="portal-map">
            <span data-testid="map-resource-count">{(resources as unknown[]).length}</span>
            <span data-testid="map-geo-enabled">{String(geoFilterEnabled ?? false)}</span>
            {onViewportChange && (
                <button
                    data-testid="trigger-viewport-change"
                    onClick={() => onViewportChange({ north: 54, south: 50, east: 15, west: 11 })}
                >
                    Viewport Change
                </button>
            )}
        </div>
    ),
}));

vi.mock('@/components/portal/PortalResultList', () => ({
    PortalResultList: ({
        resources,
        pagination,
        onPageChange,
    }: {
        resources: unknown[];
        pagination: { current_page: number; last_page: number };
        onPageChange: (page: number) => void;
    }) => (
        <div data-testid="portal-result-list">
            <span data-testid="result-count">{(resources as unknown[]).length}</span>
            <span data-testid="current-page">{pagination.current_page}</span>
            <button data-testid="next-page" onClick={() => onPageChange(pagination.current_page + 1)}>
                Next
            </button>
        </div>
    ),
}));

vi.mock('@/components/ui/resizable', () => ({
    ResizablePanelGroup: ({ children, onLayoutChanged }: { children?: React.ReactNode; onLayoutChanged?: (layout: Record<string, number>) => void }) => (
        <div data-testid="resizable-group">
            {children}
            {onLayoutChanged && (
                <button
                    data-testid="trigger-layout-change"
                    onClick={() => onLayoutChanged({ results: 60, map: 40 })}
                >
                    Layout Change
                </button>
            )}
        </div>
    ),
    ResizablePanel: ({ children }: { children?: React.ReactNode }) => <div>{children}</div>,
    ResizableHandle: () => <div data-testid="resizable-handle" />,
}));

const defaultProps: PortalPageProps = {
    resources: [
        { id: 1, doi: '10.1234/test1', title: 'Dataset 1', creators: [], year: 2024, resourceType: 'Dataset', resourceTypeSlug: 'dataset', isIgsn: false, geoLocations: [], landingPageUrl: null },
        { id: 2, doi: '10.1234/test2', title: 'Dataset 2', creators: [], year: 2023, resourceType: 'Dataset', resourceTypeSlug: 'dataset', isIgsn: false, geoLocations: [], landingPageUrl: null },
    ],
    mapData: [
        { id: 1, doi: '10.1234/test1', title: 'Dataset 1', creators: [], year: 2024, resourceType: 'Dataset', resourceTypeSlug: 'dataset', isIgsn: false, geoLocations: [{ id: 1, type: 'point', point: { lat: 52, lng: 13 }, bounds: null, polygon: null }], landingPageUrl: null },
    ],
    pagination: {
        current_page: 1,
        last_page: 3,
        per_page: 25,
        total: 50,
        from: 1,
        to: 25,
    },
    filters: {
        query: '',
        type: 'all',
        keywords: [],
        bounds: null,
        temporal: null,
    },
    keywordSuggestions: [],
    temporalRange: { Created: { min: 2000, max: 2024 } },
};

describe('Portal', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        localStorage.clear();
    });

    it('renders the portal layout with header', () => {
        render(<Portal {...defaultProps} />);
        expect(screen.getByTestId('portal-layout')).toBeInTheDocument();
        expect(screen.getByText('Data Portal')).toBeInTheDocument();
        expect(screen.getByText(/discover and explore/i)).toBeInTheDocument();
    });

    it('passes resources to result list', () => {
        render(<Portal {...defaultProps} />);
        const resultCounts = screen.getAllByTestId('result-count');
        expect(resultCounts.some((el) => el.textContent === '2')).toBe(true);
    });

    it('passes map data to map component', () => {
        render(<Portal {...defaultProps} />);
        const mapCounts = screen.getAllByTestId('map-resource-count');
        expect(mapCounts.some((el) => el.textContent === '1')).toBe(true);
    });

    it('passes total results to filters', () => {
        render(<Portal {...defaultProps} />);
        expect(screen.getByTestId('total-results')).toHaveTextContent('50');
    });

    it('navigates to next page with correct params', async () => {
        const user = userEvent.setup();
        render(<Portal {...defaultProps} />);

        const nextButtons = screen.getAllByTestId('next-page');
        await user.click(nextButtons[0]);

        expect(routerMock.get).toHaveBeenCalledWith('/portal', { page: 2 }, expect.any(Object));
    });

    it('navigates with query param preserved on page change', async () => {
        const user = userEvent.setup();
        const propsWithQuery = {
            ...defaultProps,
            filters: { query: 'climate', type: 'all' as const, keywords: [], bounds: null, temporal: null },
        };
        render(<Portal {...propsWithQuery} />);

        const nextButtons = screen.getAllByTestId('next-page');
        await user.click(nextButtons[0]);

        expect(routerMock.get).toHaveBeenCalledWith('/portal?q=climate', { page: 2 }, expect.any(Object));
    });

    it('navigates with type param preserved on page change', async () => {
        const user = userEvent.setup();
        const propsWithType = {
            ...defaultProps,
            filters: { query: '', type: 'Dataset' as PortalPageProps['filters']['type'], keywords: [], bounds: null, temporal: null },
        };
        render(<Portal {...propsWithType} />);

        const nextButtons = screen.getAllByTestId('next-page');
        await user.click(nextButtons[0]);

        expect(routerMock.get).toHaveBeenCalledWith('/portal?type=Dataset', { page: 2 }, expect.any(Object));
    });

    it('persists map collapsed state to localStorage', async () => {
        render(<Portal {...defaultProps} />);
        expect(localStorage.getItem('portal-map-collapsed')).toBe('false');
    });

    it('restores map collapsed state from localStorage', () => {
        localStorage.setItem('portal-map-collapsed', 'true');
        render(<Portal {...defaultProps} />);
        // When map is collapsed, a "Show Map" text appears
        expect(screen.queryByTestId('resizable-handle')).toBeNull();
    });

    it('restores panel layout from localStorage', () => {
        localStorage.setItem('portal-panel-layout', JSON.stringify({ results: 70, map: 30 }));
        render(<Portal {...defaultProps} />);
        expect(screen.getByTestId('portal-layout')).toBeInTheDocument();
    });

    it('handles invalid JSON in localStorage gracefully', () => {
        localStorage.setItem('portal-panel-layout', '{{invalid');
        expect(() => render(<Portal {...defaultProps} />)).not.toThrow();
    });

    it('counts geo locations correctly', () => {
        const propsWithMultipleGeo = {
            ...defaultProps,
            mapData: [
                {
                    id: 1, doi: '10.1234/a', title: 'A', creators: [], year: 2024, resourceType: 'Dataset', resourceTypeSlug: 'dataset', isIgsn: false, landingPageUrl: null,
                    geoLocations: [
                        { id: 1, type: 'point' as const, point: { lat: 1, lng: 1 }, bounds: null, polygon: null },
                        { id: 2, type: 'point' as const, point: { lat: 2, lng: 2 }, bounds: null, polygon: null },
                    ],
                },
                { id: 2, doi: '10.1234/b', title: 'B', creators: [], year: 2024, resourceType: 'Dataset', resourceTypeSlug: 'dataset', isIgsn: false, geoLocations: [], landingPageUrl: null },
            ],
        };
        render(<Portal {...propsWithMultipleGeo} />);
        // 2 geo locations from resource 1, 0 from resource 2 = 2 total
        expect(screen.getByText(/2 locations/)).toBeInTheDocument();
    });

    it('shows singular "location" for 1 geo location', () => {
        render(<Portal {...defaultProps} />);
        // defaultProps has 1 geoLocation
        expect(screen.getByText(/1 location\b/)).toBeInTheDocument();
    });

    describe('Geo Filter Integration', () => {
        it('passes geoFilterEnabled to PortalFilters', () => {
            render(<Portal {...defaultProps} />);
            expect(screen.getByTestId('geo-filter-enabled')).toHaveTextContent('false');
        });

        it('initializes geoFilterEnabled from URL bounds', () => {
            const propsWithBounds = {
                ...defaultProps,
                filters: {
                    ...defaultProps.filters,
                    bounds: { north: 53, south: 51, east: 14, west: 12 },
                },
            };
            render(<Portal {...propsWithBounds} />);
            expect(screen.getByTestId('geo-filter-enabled')).toHaveTextContent('true');
        });

        it('shows spatial filter badge when geo filter is enabled with bounds', () => {
            const propsWithBounds = {
                ...defaultProps,
                filters: {
                    ...defaultProps.filters,
                    bounds: { north: 53, south: 51, east: 14, west: 12 },
                },
            };
            render(<Portal {...propsWithBounds} />);
            expect(screen.getByText('Spatial filter')).toBeInTheDocument();
        });

        it('does not show spatial filter badge when enabled but no bounds applied', async () => {
            const user = userEvent.setup();
            render(<Portal {...defaultProps} />);

            // Enable geo filter (no bounds yet)
            await user.click(screen.getByTestId('geo-toggle'));
            expect(screen.getByTestId('geo-filter-enabled')).toHaveTextContent('true');

            // Badge should NOT be shown since bounds are null
            expect(screen.queryByText('Spatial filter')).not.toBeInTheDocument();
        });

        it('renders geo toggle button in filter panel', () => {
            render(<Portal {...defaultProps} />);
            expect(screen.getByTestId('geo-toggle')).toBeInTheDocument();
        });

        it('renders apply bounds button in filter panel', () => {
            render(<Portal {...defaultProps} />);
            expect(screen.getByTestId('apply-bounds')).toBeInTheDocument();
        });

        it('preserves bounds params on page change', async () => {
            const user = userEvent.setup();
            const propsWithBounds = {
                ...defaultProps,
                filters: {
                    query: '',
                    type: 'all' as const,
                    keywords: [],
                    bounds: { north: 53, south: 51, east: 14, west: 12 },
                    temporal: null,
                },
            };
            render(<Portal {...propsWithBounds} />);

            const nextButtons = screen.getAllByTestId('next-page');
            await user.click(nextButtons[0]);

            const calledUrl = routerMock.get.mock.calls[0][0] as string;
            expect(calledUrl).toContain('north=53.000000');
            expect(calledUrl).toContain('south=51.000000');
            expect(calledUrl).toContain('east=14.000000');
            expect(calledUrl).toContain('west=12.000000');
        });

        it('calls handleGeoFilterToggle when geo toggle is clicked (enable)', async () => {
            const user = userEvent.setup();
            render(<Portal {...defaultProps} />);

            // Initially geo filter is disabled
            expect(screen.getByTestId('geo-filter-enabled')).toHaveTextContent('false');

            // Click the geo toggle to enable
            await user.click(screen.getByTestId('geo-toggle'));

            // After toggling, geo filter should be enabled
            expect(screen.getByTestId('geo-filter-enabled')).toHaveTextContent('true');
        });

        it('calls handleGeoFilterToggle when geo toggle is clicked (disable)', async () => {
            const user = userEvent.setup();
            const propsWithBounds = {
                ...defaultProps,
                filters: {
                    ...defaultProps.filters,
                    bounds: { north: 53, south: 51, east: 14, west: 12 },
                },
            };
            render(<Portal {...propsWithBounds} />);

            // Initially geo filter is enabled (has bounds)
            expect(screen.getByTestId('geo-filter-enabled')).toHaveTextContent('true');

            // Click the geo toggle to disable
            await user.click(screen.getByTestId('geo-toggle'));

            // After toggling, geo filter should be disabled and clearBounds called
            expect(screen.getByTestId('geo-filter-enabled')).toHaveTextContent('false');
            expect(clearBoundsMock).toHaveBeenCalled();
        });

        it('calls handleBoundsChange when apply bounds is clicked', async () => {
            const user = userEvent.setup();
            render(<Portal {...defaultProps} />);

            // Click the apply bounds button in mock PortalFilters
            await user.click(screen.getByTestId('apply-bounds'));

            // setBounds should have been called with the bounds from the mock
            expect(setBoundsMock).toHaveBeenCalledWith({ north: 53, south: 51, east: 14, west: 12 });
        });

        it('calls handleClearAllFilters when clear filters is clicked', async () => {
            const user = userEvent.setup();
            const propsWithBounds = {
                ...defaultProps,
                filters: {
                    ...defaultProps.filters,
                    bounds: { north: 53, south: 51, east: 14, west: 12 },
                },
            };
            render(<Portal {...propsWithBounds} />);

            // Initially geo filter is enabled
            expect(screen.getByTestId('geo-filter-enabled')).toHaveTextContent('true');

            // Click clear all filters
            await user.click(screen.getByTestId('clear-filters'));

            // clearFilters should have been called and geo filter disabled
            expect(clearFiltersMock).toHaveBeenCalled();
            expect(screen.getByTestId('geo-filter-enabled')).toHaveTextContent('false');
        });

        it('calls handleViewportChange via PortalMap callback with debounce', () => {
            vi.useFakeTimers();

            // Enable geo filter via bounds in initial state
            const propsWithBounds = {
                ...defaultProps,
                filters: {
                    ...defaultProps.filters,
                    bounds: { north: 53, south: 51, east: 14, west: 12 },
                },
            };
            render(<Portal {...propsWithBounds} />);

            // Trigger viewport change via the map mock
            const triggerButtons = screen.getAllByTestId('trigger-viewport-change');
            act(() => {
                triggerButtons[0].click();
            });

            // setBounds should NOT have been called yet (debounce)
            expect(setBoundsMock).not.toHaveBeenCalled();

            // Advance timer past the 500ms debounce
            act(() => {
                vi.advanceTimersByTime(600);
            });

            // Now setBounds should have been called
            expect(setBoundsMock).toHaveBeenCalledWith({ north: 54, south: 50, east: 15, west: 11 });

            vi.useRealTimers();
        });

        it('calls handleLayoutChanged when layout changes', async () => {
            const user = userEvent.setup();
            render(<Portal {...defaultProps} />);

            const triggerButton = screen.getByTestId('trigger-layout-change');
            await user.click(triggerButton);

            // Layout should be persisted to localStorage
            const saved = localStorage.getItem('portal-panel-layout');
            expect(saved).toBe(JSON.stringify({ results: 60, map: 40 }));
        });

        it('resets debounce timer when viewport changes rapidly', () => {
            vi.useFakeTimers();

            const propsWithBounds = {
                ...defaultProps,
                filters: {
                    ...defaultProps.filters,
                    bounds: { north: 53, south: 51, east: 14, west: 12 },
                },
            };
            render(<Portal {...propsWithBounds} />);

            const triggerButtons = screen.getAllByTestId('trigger-viewport-change');

            // Fire first viewport change
            act(() => {
                triggerButtons[0].click();
            });

            // Advance only 200ms (less than 500ms debounce)
            act(() => {
                vi.advanceTimersByTime(200);
            });

            // Fire second viewport change (should reset the debounce timer)
            act(() => {
                triggerButtons[0].click();
            });

            // Advance 400ms (total 600ms from first, but only 400ms from second)
            act(() => {
                vi.advanceTimersByTime(400);
            });

            // setBounds should still not have been called (second fire reset the timer)
            expect(setBoundsMock).not.toHaveBeenCalled();

            // Advance another 200ms (now 600ms after second fire)
            act(() => {
                vi.advanceTimersByTime(200);
            });

            // Now it should have been called once
            expect(setBoundsMock).toHaveBeenCalledTimes(1);

            vi.useRealTimers();
        });

        it('calls handleBoundsChange with null to clear bounds', async () => {
            const user = userEvent.setup();
            render(<Portal {...defaultProps} />);

            // Click the clear bounds button
            await user.click(screen.getByTestId('clear-bounds'));

            // clearBounds should have been called
            expect(clearBoundsMock).toHaveBeenCalled();
        });

        it('preserves keywords params on page change', async () => {
            const user = userEvent.setup();
            const propsWithKeywords = {
                ...defaultProps,
                filters: {
                    query: '',
                    type: 'all' as const,
                    keywords: ['Seismology', 'Geology'],
                    bounds: null,
                    temporal: null,
                },
            };
            render(<Portal {...propsWithKeywords} />);

            const nextButtons = screen.getAllByTestId('next-page');
            await user.click(nextButtons[0]);

            const calledUrl = routerMock.get.mock.calls[0][0] as string;
            expect(calledUrl).toContain('keywords');
        });
    });
});
