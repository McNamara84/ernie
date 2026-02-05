import { Head, router } from '@inertiajs/react';
import { Map as MapIcon, PanelRightClose, PanelRightOpen } from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';

import { PortalFilters } from '@/components/portal/PortalFilters';
import { PortalMap } from '@/components/portal/PortalMap';
import { PortalResultList } from '@/components/portal/PortalResultList';
import { Button } from '@/components/ui/button';
import { ResizableHandle, ResizablePanel, ResizablePanelGroup } from '@/components/ui/resizable';
import { usePortalFilters } from '@/hooks/use-portal-filters';
import PortalLayout from '@/layouts/portal-layout';
import type { PortalPageProps } from '@/types/portal';

const STORAGE_KEY_COLLAPSED = 'portal-map-collapsed';
const STORAGE_KEY_LAYOUT = 'portal-panel-layout';
const DEFAULT_RESULTS_SIZE = 55;
const DEFAULT_MAP_SIZE = 45;

export default function Portal({ resources, mapData, pagination, filters }: PortalPageProps) {
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

    const { setSearch, setType, clearFilters, hasActiveFilters } = usePortalFilters({
        filters,
        currentPage: pagination.current_page,
    });

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
                        onClearFilters={clearFilters}
                        hasActiveFilters={hasActiveFilters}
                        isCollapsed={isFilterCollapsed}
                        onToggleCollapse={() => setIsFilterCollapsed(!isFilterCollapsed)}
                        totalResults={pagination.total}
                    />

                    {/* Results + Map Container - Stacked layout for smaller screens */}
                    <div className="flex flex-1 flex-col overflow-hidden 2xl:hidden">
                        {/* Results List */}
                        <div className="flex flex-1 flex-col overflow-hidden">
                            <PortalResultList resources={resources} pagination={pagination} onPageChange={handlePageChange} />
                        </div>

                        {/* Map - collapsible on smaller screens */}
                        <div className="border-t">
                            <PortalMap resources={mapData} />
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
                                            </div>
                                            <Button variant="ghost" size="icon" onClick={() => setIsMapCollapsed(true)} title="Collapse map">
                                                <PanelRightClose className="h-4 w-4" />
                                            </Button>
                                        </div>
                                        {/* Map Content */}
                                        <div className="flex-1">
                                            <PortalMap resources={mapData} hideHeader />
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
