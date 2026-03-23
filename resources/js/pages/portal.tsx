import { Head, router } from '@inertiajs/react';
import { Map as MapIcon, MapPin, PanelRightClose, PanelRightOpen } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';

import { PortalFilters } from '@/components/portal/PortalFilters';
import { PortalMap } from '@/components/portal/PortalMap';
import { PortalResultList } from '@/components/portal/PortalResultList';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ResizableHandle, ResizablePanel, ResizablePanelGroup } from '@/components/ui/resizable';
import { usePortalFilters } from '@/hooks/use-portal-filters';
import PortalLayout from '@/layouts/portal-layout';
import type { GeoBounds, PortalPageProps, TemporalFilterValue } from '@/types/portal';

const STORAGE_KEY_COLLAPSED = 'portal-map-collapsed';
const STORAGE_KEY_LAYOUT = 'portal-panel-layout';
const DEFAULT_RESULTS_SIZE = 55;
const DEFAULT_MAP_SIZE = 45;

export default function Portal({ resources, mapData, pagination, filters, keywordSuggestions, temporalRange }: PortalPageProps) {
    const [isFilterCollapsed, setIsFilterCollapsed] = useState(false);

    // Initialize map collapsed state from localStorage
    const [isMapCollapsed, setIsMapCollapsed] = useState(() => {
        if (typeof window === 'undefined') return false;
        const saved = localStorage.getItem(STORAGE_KEY_COLLAPSED);
        return saved === 'true';
    });

    // Initialize panel sizes from localStorage
    const [panelSizes, setPanelSizes] = useState<{ results: number; map: number }>(() => {
        if (typeof window === 'undefined') return { results: DEFAULT_RESULTS_SIZE, map: DEFAULT_MAP_SIZE };
        const saved = localStorage.getItem(STORAGE_KEY_LAYOUT);
        if (saved) {
            try {
                const parsed = JSON.parse(saved) as { results?: number; map?: number };
                return {
                    results: parsed.results ?? DEFAULT_RESULTS_SIZE,
                    map: parsed.map ?? DEFAULT_MAP_SIZE,
                };
            } catch {
                return { results: DEFAULT_RESULTS_SIZE, map: DEFAULT_MAP_SIZE };
            }
        }
        return { results: DEFAULT_RESULTS_SIZE, map: DEFAULT_MAP_SIZE };
    });

    // Persist map collapsed state to localStorage
    useEffect(() => {
        localStorage.setItem(STORAGE_KEY_COLLAPSED, String(isMapCollapsed));
    }, [isMapCollapsed]);

    // Handle layout changes and persist to localStorage
    const handleLayoutChanged = useCallback((layout: { [panelId: string]: number }) => {
        const resultsSize = layout['results'] ?? DEFAULT_RESULTS_SIZE;
        const mapSize = layout['map'] ?? DEFAULT_MAP_SIZE;
        setPanelSizes({ results: resultsSize, map: mapSize });
        localStorage.setItem(STORAGE_KEY_LAYOUT, JSON.stringify({ results: resultsSize, map: mapSize }));
    }, []);

    const { setSearch, setType, setKeywords, setBounds, clearBounds, setTemporal, clearFilters, hasActiveFilters } = usePortalFilters({
        filters,
        currentPage: pagination.current_page,
    });

    // Geo filter toggle state – initialized from URL params
    const [geoFilterEnabled, setGeoFilterEnabled] = useState(() => filters.bounds !== null);

    // Temporal filter toggle state – initialized from URL params
    const [temporalFilterEnabled, setTemporalFilterEnabled] = useState(() => filters.temporal !== null);

    // Bounds from manual coordinate input (triggers map fly-to)
    const [flyToBounds, setFlyToBounds] = useState<GeoBounds | null>(null);

    // Debounce timer ref for viewport changes
    const viewportTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);

    // Sync geo filter enabled state when URL bounds change
    useEffect(() => {
        setGeoFilterEnabled(filters.bounds !== null);
    }, [filters.bounds]);

    // Sync temporal filter enabled state when URL temporal changes
    useEffect(() => {
        setTemporalFilterEnabled(filters.temporal !== null);
    }, [filters.temporal]);

    // Handle map viewport changes with 500ms debounce
    const handleViewportChange = useCallback(
        (bounds: GeoBounds) => {
            if (!geoFilterEnabled) return;

            if (viewportTimerRef.current) {
                clearTimeout(viewportTimerRef.current);
            }

            viewportTimerRef.current = setTimeout(() => {
                setBounds(bounds);
            }, 500);
        },
        [geoFilterEnabled, setBounds],
    );

    // Cleanup debounce timer
    useEffect(() => {
        return () => {
            if (viewportTimerRef.current) {
                clearTimeout(viewportTimerRef.current);
            }
        };
    }, []);

    // Handle geo filter toggle
    const handleGeoFilterToggle = useCallback(
        (enabled: boolean) => {
            setGeoFilterEnabled(enabled);
            if (!enabled) {
                if (viewportTimerRef.current) {
                    clearTimeout(viewportTimerRef.current);
                    viewportTimerRef.current = null;
                }
                clearBounds();
                setFlyToBounds(null);
            }
        },
        [clearBounds],
    );

    // Handle manual bounds change from coordinate inputs
    const handleBoundsChange = useCallback(
        (bounds: GeoBounds | null) => {
            if (bounds) {
                setFlyToBounds(bounds);
                setBounds(bounds);
            } else {
                setFlyToBounds(null);
                clearBounds();
            }
        },
        [setBounds, clearBounds],
    );

    // Handle temporal filter toggle
    // Note: The child component (PortalTemporalFilter) already calls
    // onTemporalChange(null) when toggled off, so we only manage the
    // local toggle state here to avoid duplicate navigations.
    const handleTemporalFilterToggle = useCallback((enabled: boolean) => {
        setTemporalFilterEnabled(enabled);
    }, []);

    // Handle temporal filter value change
    const handleTemporalChange = useCallback(
        (temporal: TemporalFilterValue | null) => {
            if (temporal) {
                setTemporal(temporal);
            } else {
                setTemporal(null);
            }
        },
        [setTemporal],
    );

    // Extended clear that also resets geo and temporal filters
    const handleClearAllFilters = useCallback(() => {
        if (viewportTimerRef.current) {
            clearTimeout(viewportTimerRef.current);
            viewportTimerRef.current = null;
        }
        setGeoFilterEnabled(false);
        setTemporalFilterEnabled(false);
        setFlyToBounds(null);
        clearFilters();
    }, [clearFilters]);

    // Count geo locations for display
    const geoCount = useMemo(() => {
        return mapData.filter((r) => r.geoLocations.length > 0).reduce((acc, r) => acc + r.geoLocations.length, 0);
    }, [mapData]);

    const handlePageChange = useCallback(
        (page: number) => {
            const params = new URLSearchParams();

            if (filters.query && filters.query.trim() !== '') {
                params.set('q', filters.query.trim());
            }

            if (filters.type && filters.type !== 'all') {
                params.set('type', filters.type);
            }

            if (filters.keywords && filters.keywords.length > 0) {
                filters.keywords.forEach((kw) => {
                    params.append('keywords[]', kw);
                });
            }

            if (filters.bounds) {
                params.set('north', filters.bounds.north.toFixed(6));
                params.set('south', filters.bounds.south.toFixed(6));
                params.set('east', filters.bounds.east.toFixed(6));
                params.set('west', filters.bounds.west.toFixed(6));
            }

            if (filters.temporal) {
                params.set('date_type', filters.temporal.dateType);
                params.set('year_from', String(filters.temporal.yearFrom));
                params.set('year_to', String(filters.temporal.yearTo));
            }

            // Page is passed as Inertia data, not URL parameter
            const queryString = params.toString();
            const url = queryString ? `/portal?${queryString}` : '/portal';

            router.get(url, { page }, { preserveState: true, preserveScroll: false });
        },
        [filters],
    );

    return (
        <PortalLayout>
            <Head title="Data Portal" />

            <div className="flex h-[calc(100vh-8rem)] flex-col">
                {/* Page Header */}
                <div className="border-b px-6 py-4">
                    <h1 className="text-2xl font-bold">Data Portal</h1>
                    <p className="mt-1 text-sm text-muted-foreground">Discover and explore published research datasets</p>
                </div>

                {/* Main Content */}
                <div className="flex flex-1 overflow-hidden">
                    {/* Filter Sidebar */}
                    <PortalFilters
                        filters={filters}
                        onSearchChange={setSearch}
                        onTypeChange={setType}
                        onKeywordsChange={setKeywords}
                        onClearFilters={handleClearAllFilters}
                        hasActiveFilters={hasActiveFilters}
                        isCollapsed={isFilterCollapsed}
                        onToggleCollapse={() => setIsFilterCollapsed(!isFilterCollapsed)}
                        totalResults={pagination.total}
                        keywordSuggestions={keywordSuggestions}
                        geoFilterEnabled={geoFilterEnabled}
                        onGeoFilterToggle={handleGeoFilterToggle}
                        onBoundsChange={handleBoundsChange}
                        temporalRange={temporalRange}
                        temporalFilterEnabled={temporalFilterEnabled}
                        onTemporalFilterToggle={handleTemporalFilterToggle}
                        onTemporalChange={handleTemporalChange}
                    />

                    {/* Results + Map Container - Stacked layout for smaller screens */}
                    <div className="flex flex-1 flex-col overflow-hidden 2xl:hidden">
                        {/* Results List */}
                        <div className="flex flex-1 flex-col overflow-hidden">
                            <PortalResultList resources={resources} pagination={pagination} onPageChange={handlePageChange} />
                        </div>

                        {/* Map - collapsible on smaller screens */}
                        <div className="border-t">
                            <PortalMap
                                resources={mapData}
                                geoFilterEnabled={geoFilterEnabled}
                                onViewportChange={handleViewportChange}
                                flyToBounds={flyToBounds}
                            />
                        </div>
                    </div>

                    {/* Resizable layout for 2xl+ screens */}
                    <div className="hidden flex-1 overflow-hidden 2xl:flex">
                        <ResizablePanelGroup orientation="horizontal" className="h-full" onLayoutChanged={handleLayoutChanged}>
                            {/* Results Panel */}
                            <ResizablePanel id="results" defaultSize={isMapCollapsed ? 100 : panelSizes.results} minSize={30}>
                                <div className="flex h-full flex-col overflow-hidden">
                                    <PortalResultList resources={resources} pagination={pagination} onPageChange={handlePageChange} />
                                </div>
                            </ResizablePanel>

                            {/* Resize Handle */}
                            {!isMapCollapsed && <ResizableHandle withHandle />}

                            {/* Map Panel - collapsible */}
                            {!isMapCollapsed && (
                                <ResizablePanel id="map" defaultSize={panelSizes.map} minSize={20}>
                                    <div className="flex h-full flex-col border-l">
                                        {/* Map Header with collapse button */}
                                        <div className="flex items-center justify-between border-b px-4 py-3">
                                            <div className="flex items-center gap-2">
                                                <MapIcon className="h-4 w-4" />
                                                <span className="font-medium">Map</span>
                                                <span className="text-sm text-muted-foreground">
                                                    ({geoCount} {geoCount === 1 ? 'location' : 'locations'})
                                                </span>
                                                {geoFilterEnabled && filters.bounds && (
                                                    <Badge variant="secondary" className="text-xs">
                                                        <MapPin className="mr-1 h-3 w-3" />
                                                        Spatial filter
                                                    </Badge>
                                                )}
                                            </div>
                                            <Button variant="ghost" size="icon" onClick={() => setIsMapCollapsed(true)} title="Collapse map">
                                                <PanelRightClose className="h-4 w-4" />
                                            </Button>
                                        </div>
                                        {/* Map Content */}
                                        <div className="flex-1">
                                            <PortalMap
                                                resources={mapData}
                                                hideHeader
                                                geoFilterEnabled={geoFilterEnabled}
                                                onViewportChange={handleViewportChange}
                                                flyToBounds={flyToBounds}
                                            />
                                        </div>
                                    </div>
                                </ResizablePanel>
                            )}
                        </ResizablePanelGroup>

                        {/* Collapsed Map Toggle Button */}
                        {isMapCollapsed && (
                            <div className="flex flex-col border-l">
                                <Button variant="ghost" size="icon" className="m-2" onClick={() => setIsMapCollapsed(false)} title="Show map">
                                    <PanelRightOpen className="h-4 w-4" />
                                </Button>
                                <div className="flex flex-1 items-center justify-center">
                                    <span
                                        className="cursor-pointer text-xs text-muted-foreground [writing-mode:vertical-lr]"
                                        onClick={() => setIsMapCollapsed(false)}
                                    >
                                        Show Map ({geoCount})
                                    </span>
                                </div>
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </PortalLayout>
    );
}
