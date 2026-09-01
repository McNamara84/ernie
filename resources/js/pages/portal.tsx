import { Head, router } from '@inertiajs/react';
import axios, { isAxiosError } from 'axios';
import { Filter, List, Map as MapIcon, MapPin, PanelRightClose, PanelRightOpen } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';

import { PortalFilters } from '@/components/portal/PortalFilters';
import { PortalMap } from '@/components/portal/PortalMap';
import { PortalResultList } from '@/components/portal/PortalResultList';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ResizableHandle, ResizablePanel, ResizablePanelGroup } from '@/components/ui/resizable';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { useMediaQuery } from '@/hooks/use-media-query';
import { useNavigationStatus } from '@/hooks/use-navigation-status';
import { usePortalFilters } from '@/hooks/use-portal-filters';
import PortalLayout from '@/layouts/portal-layout';
import { buildPortalCountUrl, buildPortalFilterUrl } from '@/lib/portal-filter-url';
import { cn } from '@/lib/utils';
import type { GeoBounds, PortalPageProps, TemporalFilterValue } from '@/types/portal';

interface PortalCountResponse {
    filter_fingerprint: string;
    total: number;
    last_page: number;
    count_status: 'ready';
}

const STORAGE_KEY_COLLAPSED = 'portal-map-collapsed';
const STORAGE_KEY_LAYOUT = 'portal-panel-layout';
const DEFAULT_RESULTS_SIZE = 55;
const DEFAULT_MAP_SIZE = 45;
const DESKTOP_QUERY = '(min-width: 1280px)';

export default function Portal({
    portal,
    resources,
    pagination,
    filters,
    thesaurusFacets = [],
    temporalRange,
    resourceTypeFacets,
    datacenterFacets,
}: PortalPageProps) {
    const isDesktop = useMediaQuery(DESKTOP_QUERY);
    const [isFilterCollapsed, setIsFilterCollapsed] = useState(false);
    const [isFilterDrawerOpen, setIsFilterDrawerOpen] = useState(false);
    const [mobileView, setMobileView] = useState<'results' | 'map'>('results');
    const [searchDraft, setSearchDraft] = useState(filters.query ?? '');
    const [resolvedPagination, setResolvedPagination] = useState(pagination);
    const [countAttempt, setCountAttempt] = useState(0);
    const { isNavigating: isRefreshing } = useNavigationStatus('results');

    useEffect(() => setResolvedPagination(pagination), [pagination]);
    useEffect(() => setSearchDraft(filters.query ?? ''), [filters.query]);
    useEffect(() => {
        if (isDesktop) setIsFilterDrawerOpen(false);
    }, [isDesktop]);

    useEffect(() => {
        if (!pagination.filter_fingerprint || pagination.count_status === 'ready') return;

        const controller = new AbortController();
        const expectedFingerprint = pagination.filter_fingerprint;
        const countUrl = buildPortalCountUrl(window.location.search, portal.basePath);

        void axios
            .get<PortalCountResponse>(countUrl, { signal: controller.signal })
            .then(({ data }) => {
                if (data.filter_fingerprint !== expectedFingerprint) return;
                setResolvedPagination((current) =>
                    current.filter_fingerprint === data.filter_fingerprint
                        ? { ...current, total: data.total, last_page: data.last_page, count_status: 'ready' }
                        : current,
                );
            })
            .catch((error: unknown) => {
                if (controller.signal.aborted || (isAxiosError(error) && error.code === 'ERR_CANCELED')) return;
                setResolvedPagination((current) =>
                    current.filter_fingerprint === expectedFingerprint ? { ...current, count_status: 'failed' } : current,
                );
            });

        return () => controller.abort();
    }, [countAttempt, filters, pagination.count_status, pagination.filter_fingerprint, portal.basePath]);

    const [isMapCollapsed, setIsMapCollapsed] = useState(() => {
        if (typeof window === 'undefined') return false;
        return localStorage.getItem(STORAGE_KEY_COLLAPSED) === 'true';
    });
    const [panelSizes, setPanelSizes] = useState<{ results: number; map: number }>(() => {
        if (typeof window === 'undefined') return { results: DEFAULT_RESULTS_SIZE, map: DEFAULT_MAP_SIZE };
        const saved = localStorage.getItem(STORAGE_KEY_LAYOUT);
        if (!saved) return { results: DEFAULT_RESULTS_SIZE, map: DEFAULT_MAP_SIZE };

        try {
            const parsed = JSON.parse(saved) as { results?: number; map?: number };
            return { results: parsed.results ?? DEFAULT_RESULTS_SIZE, map: parsed.map ?? DEFAULT_MAP_SIZE };
        } catch {
            return { results: DEFAULT_RESULTS_SIZE, map: DEFAULT_MAP_SIZE };
        }
    });

    useEffect(() => localStorage.setItem(STORAGE_KEY_COLLAPSED, String(isMapCollapsed)), [isMapCollapsed]);

    const handleLayoutChanged = useCallback((layout: { [panelId: string]: number }) => {
        const next = { results: layout.results ?? DEFAULT_RESULTS_SIZE, map: layout.map ?? DEFAULT_MAP_SIZE };
        setPanelSizes(next);
        localStorage.setItem(STORAGE_KEY_LAYOUT, JSON.stringify(next));
    }, []);

    const {
        setSearch,
        setType,
        setDatacenter,
        setKeywords,
        setFreeKeywords,
        setSearchAndKeywords,
        setThesaurusKeywords,
        setBounds,
        clearBounds,
        setTemporal,
        clearFilters,
        hasActiveFilters,
    } = usePortalFilters({ filters, currentPage: pagination.current_page, basePath: portal.basePath });
    const hasLegacyKeywordFilters =
        filters.keywords.length > 0 && (filters.freeKeywords?.length ?? 0) === 0 && (filters.thesaurusKeywords?.length ?? 0) === 0;
    const selectedKeywordValues = useMemo(
        () => (hasLegacyKeywordFilters ? filters.keywords : (filters.freeKeywords ?? [])),
        [filters.freeKeywords, filters.keywords, hasLegacyKeywordFilters],
    );

    const [geoFilterEnabled, setGeoFilterEnabled] = useState(() => filters.bounds !== null);
    const [temporalFilterEnabled, setTemporalFilterEnabled] = useState(() => filters.temporal !== null);
    const [flyToBounds, setFlyToBounds] = useState<GeoBounds | null>(null);
    const [geoCount, setGeoCount] = useState(0);
    const viewportTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);

    useEffect(() => setGeoFilterEnabled(filters.bounds !== null), [filters.bounds]);
    useEffect(() => setTemporalFilterEnabled(filters.temporal !== null), [filters.temporal]);

    const handleViewportChange = useCallback(
        (bounds: GeoBounds) => {
            if (!geoFilterEnabled) return;
            if (viewportTimerRef.current) clearTimeout(viewportTimerRef.current);
            viewportTimerRef.current = setTimeout(() => setBounds(bounds), 500);
        },
        [geoFilterEnabled, setBounds],
    );

    useEffect(
        () => () => {
            if (viewportTimerRef.current) clearTimeout(viewportTimerRef.current);
        },
        [],
    );

    const handleGeoFilterToggle = useCallback(
        (enabled: boolean) => {
            setGeoFilterEnabled(enabled);
            if (!enabled) {
                if (viewportTimerRef.current) clearTimeout(viewportTimerRef.current);
                viewportTimerRef.current = null;
                clearBounds();
                setFlyToBounds(null);
            }
        },
        [clearBounds],
    );

    const handleBoundsChange = useCallback(
        (bounds: GeoBounds | null) => {
            setFlyToBounds(bounds);
            if (bounds) setBounds(bounds);
            else clearBounds();
        },
        [clearBounds, setBounds],
    );

    const handleTemporalChange = useCallback((temporal: TemporalFilterValue | null) => setTemporal(temporal), [setTemporal]);

    const handleKeywordChange = useCallback(
        (keywords: string[]) => (hasLegacyKeywordFilters ? setKeywords(keywords) : setFreeKeywords(keywords)),
        [hasLegacyKeywordFilters, setFreeKeywords, setKeywords],
    );

    const handleKeywordSelect = useCallback(
        (keyword: string) => {
            const nextKeywords = selectedKeywordValues.includes(keyword) ? selectedKeywordValues : [...selectedKeywordValues, keyword];
            setSearchDraft('');
            setSearchAndKeywords('', nextKeywords, hasLegacyKeywordFilters);
        },
        [hasLegacyKeywordFilters, selectedKeywordValues, setSearchAndKeywords],
    );

    const handleClearAllFilters = useCallback(() => {
        if (viewportTimerRef.current) clearTimeout(viewportTimerRef.current);
        viewportTimerRef.current = null;
        setGeoFilterEnabled(false);
        setTemporalFilterEnabled(false);
        setFlyToBounds(null);
        setSearchDraft('');
        clearFilters();
    }, [clearFilters]);

    const handlePageChange = useCallback(
        (page: number) => router.get(buildPortalFilterUrl(filters, portal.basePath), { page }, { preserveState: true, preserveScroll: false }),
        [filters, portal.basePath],
    );

    const sharedFilterProps = {
        basePath: portal.basePath,
        filters,
        searchValue: searchDraft,
        onSearchValueChange: setSearchDraft,
        onSearchChange: setSearch,
        onKeywordSelect: handleKeywordSelect,
        onTypeChange: setType,
        onDatacenterChange: setDatacenter,
        onKeywordsChange: handleKeywordChange,
        onThesaurusKeywordsChange: setThesaurusKeywords,
        onClearFilters: handleClearAllFilters,
        hasActiveFilters,
        thesaurusFacets,
        geoFilterEnabled,
        onGeoFilterToggle: handleGeoFilterToggle,
        onBoundsChange: handleBoundsChange,
        temporalRange,
        temporalFilterEnabled,
        onTemporalFilterToggle: setTemporalFilterEnabled,
        onTemporalChange: handleTemporalChange,
        resourceTypeFacets,
        datacenterFacets,
        showResourceTypeFilter: portal.showResourceTypeFilter,
    };

    const results = (
        <PortalResultList
            resources={resources}
            pagination={resolvedPagination}
            onPageChange={handlePageChange}
            isLoading={isRefreshing}
            hasActiveFilters={hasActiveFilters}
            onClearFilters={handleClearAllFilters}
            onRetryCount={() => setCountAttempt((attempt) => attempt + 1)}
        />
    );

    const map = (
        <PortalMap
            basePath={portal.basePath}
            filters={filters}
            hideHeader
            geoFilterEnabled={geoFilterEnabled}
            onViewportChange={handleViewportChange}
            onLocationCountChange={setGeoCount}
            flyToBounds={flyToBounds}
        />
    );

    return (
        <PortalLayout portalKind={portal.kind}>
            <Head title={portal.title} />

            <div className="flex min-h-0 flex-1 overflow-hidden" data-testid="portal-workspace">
                {isDesktop ? (
                    <>
                        <PortalFilters
                            {...sharedFilterProps}
                            isCollapsed={isFilterCollapsed}
                            onToggleCollapse={() => setIsFilterCollapsed((collapsed) => !collapsed)}
                        />

                        <div className="flex min-w-0 flex-1 overflow-hidden">
                            <ResizablePanelGroup orientation="horizontal" className="h-full" onLayoutChanged={handleLayoutChanged}>
                                <ResizablePanel id="results" defaultSize={isMapCollapsed ? 100 : panelSizes.results} minSize={30}>
                                    <div className="flex h-full min-w-0 flex-col overflow-hidden">{results}</div>
                                </ResizablePanel>

                                {!isMapCollapsed && <ResizableHandle withHandle />}

                                {!isMapCollapsed && (
                                    <ResizablePanel id="map" defaultSize={panelSizes.map} minSize={20}>
                                        <div className="flex h-full min-w-0 flex-col border-l">
                                            <div className="flex h-12 shrink-0 items-center justify-between border-b px-4">
                                                <div className="flex min-w-0 items-center gap-2">
                                                    <MapIcon className="h-4 w-4 shrink-0" />
                                                    <span className="font-medium">Map</span>
                                                    <span className="truncate text-sm text-muted-foreground">
                                                        ({geoCount} {geoCount === 1 ? 'location' : 'locations'})
                                                    </span>
                                                    {geoFilterEnabled && filters.bounds && (
                                                        <Badge variant="secondary" className="text-xs">
                                                            <MapPin className="mr-1 h-3 w-3" />
                                                            Spatial filter
                                                        </Badge>
                                                    )}
                                                </div>
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    onClick={() => setIsMapCollapsed(true)}
                                                    title="Collapse map"
                                                    aria-label="Collapse map"
                                                >
                                                    <PanelRightClose className="h-4 w-4" />
                                                </Button>
                                            </div>
                                            <div className="min-h-0 flex-1">{map}</div>
                                        </div>
                                    </ResizablePanel>
                                )}
                            </ResizablePanelGroup>

                            {isMapCollapsed && (
                                <div className="flex w-12 shrink-0 flex-col items-center border-l">
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        className="mt-2"
                                        onClick={() => setIsMapCollapsed(false)}
                                        title="Show map"
                                        aria-label="Show map"
                                    >
                                        <PanelRightOpen className="h-4 w-4" />
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        className="h-auto w-full flex-1 rounded-none px-0 py-2 text-xs font-normal text-muted-foreground [writing-mode:vertical-lr]"
                                        onClick={() => setIsMapCollapsed(false)}
                                    >
                                        Show Map ({geoCount})
                                    </Button>
                                </div>
                            )}
                        </div>
                    </>
                ) : (
                    <div className="flex min-h-0 min-w-0 flex-1 flex-col overflow-hidden">
                        <div className="flex h-12 shrink-0 items-center gap-2 border-b px-3">
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => setIsFilterDrawerOpen(true)}
                                className="relative"
                                data-testid="portal-filter-drawer-trigger"
                            >
                                <Filter className="mr-1.5 h-4 w-4" /> Filters
                                {hasActiveFilters && (
                                    <span className="absolute -top-1 -right-1 h-2.5 w-2.5 rounded-full border-2 border-background bg-primary" />
                                )}
                            </Button>
                            <div className="ml-auto flex rounded-md border p-0.5" aria-label="Portal view">
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    aria-pressed={mobileView === 'results'}
                                    onClick={() => setMobileView('results')}
                                    className={cn('h-8', mobileView === 'results' && 'bg-muted')}
                                    data-testid="portal-mobile-results-tab"
                                >
                                    <List className="mr-1.5 h-4 w-4" /> Results
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    aria-pressed={mobileView === 'map'}
                                    onClick={() => setMobileView('map')}
                                    className={cn('h-8', mobileView === 'map' && 'bg-muted')}
                                    data-testid="portal-mobile-map-tab"
                                >
                                    <MapIcon className="mr-1.5 h-4 w-4" /> Map
                                </Button>
                            </div>
                        </div>

                        <Sheet open={isFilterDrawerOpen} onOpenChange={setIsFilterDrawerOpen}>
                            <SheetContent side="left" className="w-[min(92vw,20rem)] gap-0 p-0 sm:max-w-xs">
                                <SheetHeader className="sr-only">
                                    <SheetTitle>Search filters</SheetTitle>
                                    <SheetDescription>Filter published datasets and samples.</SheetDescription>
                                </SheetHeader>
                                <PortalFilters
                                    {...sharedFilterProps}
                                    isCollapsed={false}
                                    onToggleCollapse={() => setIsFilterDrawerOpen(false)}
                                    showCollapseButton={false}
                                    className="w-full border-r-0"
                                />
                            </SheetContent>
                        </Sheet>

                        <div className="min-h-0 flex-1 overflow-hidden">
                            {mobileView === 'results' ? (
                                <div className="flex h-full flex-col" data-testid="portal-mobile-results-view">
                                    {results}
                                </div>
                            ) : (
                                <div className="h-full" data-testid="portal-mobile-map-view">
                                    {map}
                                </div>
                            )}
                        </div>
                    </div>
                )}
            </div>
        </PortalLayout>
    );
}
