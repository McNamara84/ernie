import 'leaflet/dist/leaflet.css';

import L from 'leaflet';
import { ChevronDown, ChevronUp, Map as MapIcon } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { MapContainer, Polygon, Polyline, Popup, Rectangle, TileLayer, useMap } from 'react-leaflet';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { usePortalMapData } from '@/hooks/use-portal-map-data';
import { formatAuthorsShort, getShapePathOptions } from '@/lib/portal-map-config';
import { normalizeLongitude, unwrapLongitudeBounds, unwrapPathLongitudes } from '@/lib/portal-map-longitude';
import { cn } from '@/lib/utils';
import type { GeoBounds, PortalBasePath, PortalFilters, PortalMapFeature, PortalMapResourceFeature, PortalMapViewport } from '@/types/portal';

import { ClusterLayer } from './PortalMapCluster';
import { PortalMapLegend } from './PortalMapLegend';

interface PortalMapProps {
    basePath?: PortalBasePath;
    filters: PortalFilters;
    className?: string;
    hideHeader?: boolean;
    geoFilterEnabled?: boolean;
    onViewportChange?: (bounds: GeoBounds) => void;
    onLocationCountChange?: (count: number) => void;
    flyToBounds?: GeoBounds | null;
}

const VIEWPORT_RESIZE_DEBOUNCE_MS = 250;

function MapResizeHandler() {
    const map = useMap();

    useEffect(() => {
        const container = map.getContainer();
        let rafId: number | null = null;

        const scheduleInvalidate = () => {
            if (rafId !== null) return;
            rafId = requestAnimationFrame(() => {
                rafId = null;
                map.invalidateSize();
            });
        };

        if (typeof ResizeObserver !== 'undefined') {
            const observer = new ResizeObserver(scheduleInvalidate);
            observer.observe(container);
            return () => {
                observer.disconnect();
                if (rafId !== null) cancelAnimationFrame(rafId);
            };
        }

        window.addEventListener('resize', scheduleInvalidate);
        return () => {
            window.removeEventListener('resize', scheduleInvalidate);
            if (rafId !== null) cancelAnimationFrame(rafId);
        };
    }, [map]);

    return null;
}

function ViewportTracker({
    onTechnicalViewport,
    onFilterViewport,
    skipFilterUpdate,
}: {
    onTechnicalViewport: (viewport: PortalMapViewport) => void;
    onFilterViewport?: (bounds: GeoBounds) => void;
    skipFilterUpdate: React.RefObject<boolean>;
}) {
    const map = useMap();
    const technicalRef = useRef(onTechnicalViewport);
    const filterRef = useRef(onFilterViewport);

    useEffect(() => {
        technicalRef.current = onTechnicalViewport;
        filterRef.current = onFilterViewport;
    }, [onTechnicalViewport, onFilterViewport]);

    useEffect(() => {
        let resizeTimer: number | null = null;

        const reportViewport = (updateFilter: boolean) => {
            const container = map.getContainer();
            if (container.clientWidth === 0 || container.clientHeight === 0) return;

            const mapBounds = map.getBounds();
            const longitudeSpan = mapBounds.getEast() - mapBounds.getWest();
            const bounds: GeoBounds = {
                north: Math.min(90, mapBounds.getNorth()),
                south: Math.max(-90, mapBounds.getSouth()),
                west: longitudeSpan >= 360 ? -180 : normalizeLongitude(mapBounds.getWest()),
                east: longitudeSpan >= 360 ? 180 : normalizeLongitude(mapBounds.getEast()),
            };

            technicalRef.current({
                ...bounds,
                width: container.clientWidth,
                height: container.clientHeight,
                zoom: map.getZoom(),
            });

            if (updateFilter) {
                if (skipFilterUpdate.current) {
                    skipFilterUpdate.current = false;
                } else {
                    filterRef.current?.(bounds);
                }
            }
        };

        const reportMoveEnd = () => reportViewport(true);
        const reportResize = () => {
            if (resizeTimer !== null) window.clearTimeout(resizeTimer);
            resizeTimer = window.setTimeout(() => {
                resizeTimer = null;
                reportViewport(false);
            }, VIEWPORT_RESIZE_DEBOUNCE_MS);
        };

        map.on('moveend', reportMoveEnd);
        map.on('resize', reportResize);
        const initialTimer = window.setTimeout(() => reportViewport(false), 0);

        return () => {
            window.clearTimeout(initialTimer);
            if (resizeTimer !== null) window.clearTimeout(resizeTimer);
            map.off('moveend', reportMoveEnd);
            map.off('resize', reportResize);
        };
    }, [map, skipFilterUpdate]);

    return null;
}

function FitExtentControl({ extent, skipFilterUpdate }: { extent: GeoBounds | null; skipFilterUpdate: React.RefObject<boolean> }) {
    const map = useMap();
    const previousExtent = useRef<string | null>(null);

    useEffect(() => {
        if (!extent) return;

        const key = `${extent.north},${extent.south},${extent.east},${extent.west}`;
        if (previousExtent.current === key) return;
        previousExtent.current = key;
        skipFilterUpdate.current = true;
        map.invalidateSize();

        const displayLongitudes = unwrapLongitudeBounds(extent, map.getCenter().lng);
        const bounds = L.latLngBounds([extent.south, displayLongitudes.west], [extent.north, displayLongitudes.east]);
        if (bounds.isValid() && bounds.getNorthEast().equals(bounds.getSouthWest())) {
            map.setView(bounds.getCenter(), 10);
        } else if (bounds.isValid()) {
            map.fitBounds(bounds, { padding: [30, 30], maxZoom: 10 });
        }
    }, [extent, map, skipFilterUpdate]);

    return null;
}

function MapBoundsUpdater({ bounds, skipFilterUpdate }: { bounds: GeoBounds | null; skipFilterUpdate: React.RefObject<boolean> }) {
    const map = useMap();
    const previousBounds = useRef<string | null>(null);

    useEffect(() => {
        if (!bounds) return;

        const key = `${bounds.north},${bounds.south},${bounds.east},${bounds.west}`;
        if (previousBounds.current === key) return;
        previousBounds.current = key;
        skipFilterUpdate.current = true;

        const displayLongitudes = unwrapLongitudeBounds(bounds, map.getCenter().lng);
        map.fitBounds(
            [
                [bounds.south, displayLongitudes.west],
                [bounds.north, displayLongitudes.east],
            ],
            { padding: [20, 20], animate: true },
        );
    }, [bounds, map, skipFilterUpdate]);

    return null;
}

function ResourcePopupContent({ feature }: { feature: PortalMapResourceFeature }) {
    const resource = feature.resource;
    const resourceType = resource.resourceType;

    return (
        <div className="max-w-[280px] min-w-[200px]">
            <Badge variant={resourceType?.slug === 'physical-object' ? 'secondary' : 'default'} className="mb-2">
                {resourceType?.name ?? 'Other'}
            </Badge>
            <h4 className="mb-1 line-clamp-2 text-sm leading-tight font-semibold">{resource.title}</h4>
            <p className="mb-2 text-xs text-muted-foreground">{formatAuthorsShort(resource.creators)}</p>
            {resource.landingPageUrl && (
                <a
                    href={resource.landingPageUrl}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="inline-flex items-center text-xs font-medium text-primary hover:underline"
                >
                    View Details →
                </a>
            )}
        </div>
    );
}

function ResourceShapes({ features }: { features: PortalMapFeature[] }) {
    const map = useMap();
    const referenceLongitude = map.getCenter().lng;

    return features.map((feature) => {
        if (feature.kind !== 'resource' || feature.geometry.type === 'point') return null;

        const typeSlug = feature.resource.resourceType?.slug ?? null;
        const popup = <ResourcePopupContent feature={feature} />;

        if (feature.geometry.type === 'box') {
            const displayLongitudes = unwrapLongitudeBounds(feature.geometry, referenceLongitude);

            return (
                <Rectangle
                    key={feature.id}
                    bounds={[
                        [feature.geometry.south, displayLongitudes.west],
                        [feature.geometry.north, displayLongitudes.east],
                    ]}
                    pathOptions={getShapePathOptions(typeSlug, 'box')}
                >
                    <Popup>{popup}</Popup>
                </Rectangle>
            );
        }

        const positions: L.LatLngExpression[] = unwrapPathLongitudes(feature.geometry.points, referenceLongitude).map((point) => [
            point.latitude,
            point.longitude,
        ]);

        return feature.geometry.type === 'polygon' ? (
            <Polygon key={feature.id} positions={positions} pathOptions={getShapePathOptions(typeSlug, 'polygon')}>
                <Popup>{popup}</Popup>
            </Polygon>
        ) : (
            <Polyline key={feature.id} positions={positions} pathOptions={getShapePathOptions(typeSlug, 'line')}>
                <Popup>{popup}</Popup>
            </Polyline>
        );
    });
}

function filterSignature(filters: PortalFilters): string {
    return JSON.stringify(filters);
}

export function PortalMap({
    basePath = '/doi-search',
    filters,
    className,
    hideHeader = false,
    geoFilterEnabled = false,
    onViewportChange,
    onLocationCountChange,
    flyToBounds,
}: PortalMapProps) {
    const [isCollapsed, setIsCollapsed] = useState(false);
    const [request, setRequest] = useState<{ viewport: PortalMapViewport; includeExtent: boolean } | null>(null);
    const [locationCount, setLocationCount] = useState(0);
    const skipFilterUpdate = useRef(false);
    const requestExtent = useRef(!geoFilterEnabled);
    const knownTotalLocations = useRef<number | null>(null);
    const signature = filterSignature(filters);
    const previousSignature = useRef(signature);

    useEffect(() => {
        if (previousSignature.current === signature) return;
        previousSignature.current = signature;
        requestExtent.current = !geoFilterEnabled;
        knownTotalLocations.current = null;
        setLocationCount(0);
        setRequest((current) => (current ? { ...current, includeExtent: !geoFilterEnabled } : current));
    }, [geoFilterEnabled, signature]);

    const handleTechnicalViewport = useCallback(
        (viewport: PortalMapViewport) => {
            const includeExtent = requestExtent.current && !geoFilterEnabled;
            requestExtent.current = false;
            setRequest({ viewport, includeExtent });
        },
        [geoFilterEnabled],
    );

    const mapQuery = usePortalMapData(filters, request?.viewport ?? null, request?.includeExtent ?? false, basePath);
    const features = mapQuery.data?.features ?? [];

    useEffect(() => {
        const meta = mapQuery.data?.meta;
        if (!meta) return;

        if (meta.totalLocations !== null) {
            knownTotalLocations.current = meta.totalLocations;
        }
        const count = knownTotalLocations.current ?? meta.visibleLocations;
        setLocationCount(count);
        onLocationCountChange?.(count);
    }, [mapQuery.data?.meta, onLocationCountChange]);

    const extent = request?.includeExtent ? (mapQuery.data?.meta.extent ?? null) : null;

    const mapContent = (
        <div className="relative h-full w-full">
            <MapContainer center={[30, 0]} zoom={2} className="h-full w-full">
                <TileLayer
                    attribution='&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
                    url="https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png"
                />
                <MapResizeHandler />
                <ViewportTracker
                    onTechnicalViewport={handleTechnicalViewport}
                    onFilterViewport={geoFilterEnabled ? onViewportChange : undefined}
                    skipFilterUpdate={skipFilterUpdate}
                />
                {!geoFilterEnabled && <FitExtentControl extent={extent} skipFilterUpdate={skipFilterUpdate} />}
                <MapBoundsUpdater bounds={flyToBounds ?? null} skipFilterUpdate={skipFilterUpdate} />
                <ClusterLayer features={features} />
                <ResourceShapes features={features} />
            </MapContainer>

            <PortalMapLegend features={features} />

            {mapQuery.isFetching && (
                <div
                    className="pointer-events-none absolute top-3 left-1/2 z-1000 -translate-x-1/2 rounded-md bg-background/90 px-3 py-1.5 text-xs shadow"
                    role="status"
                >
                    Updating map...
                </div>
            )}

            {mapQuery.isError && (
                <div
                    className="absolute inset-x-4 top-4 z-1000 rounded-md border border-destructive/30 bg-background/95 p-3 text-sm shadow"
                    role="alert"
                >
                    Map data could not be loaded.{' '}
                    <Button variant="link" size="xs" className="h-auto px-1 py-0 align-baseline" onClick={() => void mapQuery.refetch()}>
                        Try again
                    </Button>
                </div>
            )}

            {!mapQuery.isLoading && !mapQuery.isError && mapQuery.data?.meta.visibleLocations === 0 && (
                <div className="pointer-events-none absolute inset-x-4 top-4 z-1000 rounded-md bg-background/90 p-3 text-center text-sm text-muted-foreground shadow">
                    No geographic data in this map area
                </div>
            )}
        </div>
    );

    const headerCount = useMemo(() => `(${locationCount.toLocaleString()} ${locationCount === 1 ? 'location' : 'locations'})`, [locationCount]);

    return (
        <div className={cn('flex h-full flex-col', className)} data-testid="portal-map-container">
            {hideHeader && <div className="h-full w-full">{mapContent}</div>}

            {!hideHeader && (
                <Collapsible open={!isCollapsed} onOpenChange={(open) => setIsCollapsed(!open)} className="2xl:hidden">
                    <CollapsibleTrigger asChild>
                        <Button
                            variant="ghost"
                            className="flex w-full items-center justify-between rounded-none border-b px-4 py-3 hover:bg-muted/50"
                        >
                            <div className="flex items-center gap-2">
                                <MapIcon className="h-4 w-4" />
                                <span className="font-medium">Map</span>
                                <span className="text-sm text-muted-foreground">{headerCount}</span>
                            </div>
                            {isCollapsed ? <ChevronDown className="h-4 w-4" /> : <ChevronUp className="h-4 w-4" />}
                        </Button>
                    </CollapsibleTrigger>
                    <CollapsibleContent>
                        <div className="h-[300px] w-full">{mapContent}</div>
                    </CollapsibleContent>
                </Collapsible>
            )}

            {!hideHeader && (
                <div className="hidden h-full flex-col 2xl:flex">
                    <div className="flex items-center gap-2 border-b px-4 py-3">
                        <MapIcon className="h-4 w-4" />
                        <span className="font-medium">Map</span>
                        <span className="text-sm text-muted-foreground">{headerCount}</span>
                    </div>
                    <div className="flex-1">{mapContent}</div>
                </div>
            )}
        </div>
    );
}
