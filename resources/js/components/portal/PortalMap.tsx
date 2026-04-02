import 'leaflet/dist/leaflet.css';
import 'leaflet.markercluster/dist/MarkerCluster.css';
import 'leaflet.markercluster/dist/MarkerCluster.Default.css';

import L from 'leaflet';
import { ChevronDown, ChevronUp, Map as MapIcon } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { MapContainer, Polygon, Polyline, Popup, Rectangle, TileLayer, useMap } from 'react-leaflet';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { formatAuthorsShort, getShapePathOptions } from '@/lib/portal-map-config';
import { cn } from '@/lib/utils';
import type { GeoBounds, PortalResource } from '@/types/portal';

import { ClusterLayer } from './PortalMapCluster';
import { PortalMapLegend } from './PortalMapLegend';



interface PortalMapProps {
    resources: PortalResource[];
    className?: string;
    /** Hide the header (used when header is rendered externally) */
    hideHeader?: boolean;
    /** Whether the geo filter is currently active */
    geoFilterEnabled?: boolean;
    /** Callback when the map viewport changes (debounced externally) */
    onViewportChange?: (bounds: GeoBounds) => void;
    /** Bounds to fly to when set from coordinate input */
    flyToBounds?: GeoBounds | null;
}



/**
 * Calculate bounds that encompass all resources.
 */
function calculateBounds(resources: PortalResource[]): L.LatLngBounds | null {
    const allPoints: L.LatLngTuple[] = [];

    resources.forEach((resource) => {
        resource.geoLocations.forEach((geo) => {
            if (geo.point) {
                allPoints.push([geo.point.lat, geo.point.lng]);
            }
            if (geo.bounds) {
                allPoints.push([geo.bounds.south, geo.bounds.west]);
                allPoints.push([geo.bounds.north, geo.bounds.east]);
            }
            if (geo.polygon) {
                geo.polygon.forEach((p) => {
                    allPoints.push([p.lat, p.lng]);
                });
            }
        });
    });

    if (allPoints.length === 0) {
        return null;
    }

    if (allPoints.length === 1) {
        const [lat, lng] = allPoints[0];
        return L.latLngBounds(
            [lat - 5, lng - 5],
            [lat + 5, lng + 5],
        );
    }

    return L.latLngBounds(allPoints);
}

/**
 * Component to fit map bounds to show all markers.
 * - On initial mount (when geoFilterEnabled is false): waits for container
 *   layout to settle, then fits once. Skipped when geoFilterEnabled is true
 *   so user-specified viewports (including flyToBounds) are not overridden.
 * - When geo filter is active: does NOT auto-fit (user controls viewport)
 * - When geo filter is turned off: re-fits to show all markers
 */
function FitBoundsControl({
    resources,
    geoFilterEnabled,
    skipNextMoveEnd,
}: {
    resources: PortalResource[];
    geoFilterEnabled: boolean;
    skipNextMoveEnd: React.RefObject<boolean>;
}) {
    const map = useMap();
    const hasInitialFit = useRef(false);
    const prevGeoFilterEnabled = useRef(geoFilterEnabled);
    const prevResourceKey = useRef('');

    const fitToAllMarkers = useCallback(() => {
        skipNextMoveEnd.current = true;
        // Ensure Leaflet knows the current container size before calculating zoom
        map.invalidateSize();
        const bounds = calculateBounds(resources);
        if (bounds && bounds.isValid()) {
            map.fitBounds(bounds, { padding: [30, 30], maxZoom: 10 });
        } else {
            map.setView([30, 0], 2);
        }
    }, [map, resources, skipNextMoveEnd]);

    // Initial fit: use ResizeObserver with debounce to wait for the
    // ResizablePanel layout to settle before fitting bounds. Skipped when
    // geoFilterEnabled is true (user controls the viewport via geo filter).
    useEffect(() => {
        if (hasInitialFit.current) return;
        if (geoFilterEnabled) return;

        const container = map.getContainer();

        const performFit = () => {
            if (hasInitialFit.current) return;
            if (container.clientWidth > 0 && container.clientHeight > 0) {
                hasInitialFit.current = true;
                fitToAllMarkers();
                // Disconnect immediately — observer is only needed until initial fit
                observer?.disconnect();
            }
        };

        // Debounce resize events — the ResizablePanel may fire multiple
        // resize events as it settles into its final dimensions.
        let timer: ReturnType<typeof setTimeout> | null = null;
        let observer: ResizeObserver | null = null;

        if (typeof ResizeObserver !== 'undefined') {
            observer = new ResizeObserver(() => {
                if (hasInitialFit.current) {
                    observer?.disconnect();
                    return;
                }
                if (timer) clearTimeout(timer);
                timer = setTimeout(performFit, 150);
            });
            observer.observe(container);
        } else {
            // Fallback for environments without ResizeObserver (SSR, older browsers)
            performFit();
        }

        return () => {
            observer?.disconnect();
            if (timer) clearTimeout(timer);
        };
    }, [map, geoFilterEnabled, fitToAllMarkers]);

    // Re-fit when geo filter is turned OFF (restore "show all" view)
    useEffect(() => {
        const wasEnabled = prevGeoFilterEnabled.current;
        prevGeoFilterEnabled.current = geoFilterEnabled;

        if (wasEnabled && !geoFilterEnabled && hasInitialFit.current) {
            fitToAllMarkers();
        }
    }, [geoFilterEnabled, fitToAllMarkers]);

    // Re-fit when resources change while geo filter is off (e.g. text search
    // or type filter changed the dataset) so new markers are not off-screen.
    useEffect(() => {
        const resourceKey = resources.map(r => r.id).join(',');
        const changed = prevResourceKey.current !== resourceKey;
        prevResourceKey.current = resourceKey;

        // Skip the initial render (handled by the ResizeObserver above)
        if (!hasInitialFit.current) return;
        // Only auto-fit when geo filter is not active (user controls viewport when filtering)
        if (geoFilterEnabled) return;
        // Guard: only re-fit when the set of resources actually changed
        if (!changed) return;

        fitToAllMarkers();
    }, [resources, geoFilterEnabled, fitToAllMarkers]);

    return null;
}

/**
 * Observe the map container for size changes and call invalidateSize().
 * Uses rAF to coalesce multiple resize events per frame and avoid unnecessary reflows.
 * Falls back to a window resize listener when ResizeObserver is unavailable.
 * Must be rendered inside a MapContainer.
 */
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

        // Fallback for environments without ResizeObserver (older browsers, embedded webviews)
        window.addEventListener('resize', scheduleInvalidate);
        return () => {
            window.removeEventListener('resize', scheduleInvalidate);
            if (rafId !== null) cancelAnimationFrame(rafId);
        };
    }, [map]);

    return null;
}

/**
 * Track map viewport changes and report bounds.
 * Skips the next moveend event when skipNextMoveEnd flag is set (after programmatic fly-to).
 * Only reports when the map container is actually visible (has non-zero dimensions)
 * to prevent hidden duplicate map instances from sending incorrect bounds.
 *
 * Uses a ref to always call the latest onViewportChange without re-subscribing
 * the Leaflet event handler on every callback change.
 */
function ViewportTracker({ onViewportChange, skipNextMoveEnd }: { onViewportChange: (bounds: GeoBounds) => void; skipNextMoveEnd: React.RefObject<boolean> }) {
    const map = useMap();
    const callbackRef = useRef(onViewportChange);

    // Keep the ref in sync with the latest callback
    useEffect(() => {
        callbackRef.current = onViewportChange;
    }, [onViewportChange]);

    // Reset any stale skip flag that was set while ViewportTracker was
    // not mounted (e.g. FitBoundsControl set it when geoFilterEnabled was false).
    // eslint-disable-next-line react-hooks/exhaustive-deps -- mount-only: reset stale flag once
    useEffect(() => {
        skipNextMoveEnd.current = false;
    }, []);

    useEffect(() => {
        const handler = () => {
            // Check container visibility FIRST — hidden map instances (e.g.
            // CSS display:none) must not consume the shared skip flag.
            const container = map.getContainer();
            if (container.clientWidth === 0 || container.clientHeight === 0) {
                return;
            }

            if (skipNextMoveEnd.current) {
                skipNextMoveEnd.current = false;
                return;
            }

            const b = map.getBounds();
            callbackRef.current({
                north: b.getNorth(),
                south: b.getSouth(),
                east: b.getEast(),
                west: b.getWest(),
            });
        };

        map.on('moveend', handler);
        return () => {
            map.off('moveend', handler);
        };
    }, [map, skipNextMoveEnd]);

    return null;
}

/**
 * Fly the map to specific bounds (triggered by manual coordinate input).
 */
function MapBoundsUpdater({ bounds, skipNextMoveEnd }: { bounds: GeoBounds | null; skipNextMoveEnd: React.RefObject<boolean> }) {
    const map = useMap();
    const prevBoundsRef = useRef<string | null>(null);

    useEffect(() => {
        if (!bounds) return;

        const boundsKey = `${bounds.north},${bounds.south},${bounds.east},${bounds.west}`;

        // Only fly if bounds actually changed (avoid loops when viewport reports same bounds)
        if (prevBoundsRef.current === boundsKey) return;
        prevBoundsRef.current = boundsKey;

        // Suppress the next moveend so ViewportTracker doesn't overwrite manual bounds
        skipNextMoveEnd.current = true;

        // Handle anti-meridian crossing (west > east means the box wraps around 180°)
        if (bounds.west > bounds.east) {
            // Calculate center and zoom manually for wrapped bounds
            const centerLat = (bounds.north + bounds.south) / 2;
            // Shift longitudes to 0..360 system to find the true center
            const westNorm = bounds.west < 0 ? bounds.west + 360 : bounds.west;
            const eastNorm = bounds.east < 0 ? bounds.east + 360 : bounds.east;
            let centerLng = (westNorm + eastNorm) / 2;
            // Normalize back to -180..180
            if (centerLng > 180) centerLng -= 360;

            // Estimate appropriate zoom from longitude span
            const lngSpan = (eastNorm - westNorm + 360) % 360 || 360;
            const latSpan = bounds.north - bounds.south;
            const maxSpan = Math.max(lngSpan, latSpan);
            // Rough zoom estimate: 360° ≈ zoom 1, halving span ≈ +1 zoom
            const zoom = Math.max(1, Math.min(18, Math.round(Math.log2(360 / maxSpan)) + 1));

            map.setView([centerLat, centerLng], zoom, { animate: true });
        } else {
            map.fitBounds(
                [
                    [bounds.south, bounds.west],
                    [bounds.north, bounds.east],
                ],
                { padding: [20, 20], animate: true },
            );
        }
    }, [map, bounds, skipNextMoveEnd]);

    return null;
}

/**
 * Popup content for a resource marker.
 */
function ResourcePopupContent({ resource }: { resource: PortalResource }) {
    return (
        <div className="min-w-[200px] max-w-[280px]">
            <Badge
                variant={resource.isIgsn ? 'secondary' : 'default'}
                className="mb-2"
            >
                {resource.resourceType}
            </Badge>
            <h4 className="mb-1 line-clamp-2 text-sm font-semibold leading-tight">
                {resource.title}
            </h4>
            <p className="mb-2 text-xs text-muted-foreground">
                {formatAuthorsShort(resource.creators)}
                {resource.year && ` • ${resource.year}`}
            </p>
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

/**
 * Interactive map displaying resources with geo locations.
 */
export function PortalMap({ resources, className, hideHeader = false, geoFilterEnabled = false, onViewportChange, flyToBounds }: PortalMapProps) {
    const [isCollapsed, setIsCollapsed] = useState(false);
    const skipNextMoveEnd = useRef(false);

    // Filter resources that have geo locations
    const resourcesWithGeo = useMemo(
        () => resources.filter((r) => r.geoLocations.length > 0),
        [resources],
    );

    const geoCount = resourcesWithGeo.reduce((acc, r) => acc + r.geoLocations.length, 0);

    const mapContent = resourcesWithGeo.length > 0 ? (
        <div className="relative h-full w-full">
            <MapContainer
                center={[30, 0]}
                zoom={2}
                className="h-full w-full"
            >
                <TileLayer
                attribution='&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
                url="https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png"
            />
            <MapResizeHandler />
            <FitBoundsControl resources={resourcesWithGeo} geoFilterEnabled={geoFilterEnabled} skipNextMoveEnd={skipNextMoveEnd} />

            {geoFilterEnabled && onViewportChange && (
                <ViewportTracker onViewportChange={onViewportChange} skipNextMoveEnd={skipNextMoveEnd} />
            )}

            {flyToBounds && (
                <MapBoundsUpdater bounds={flyToBounds} skipNextMoveEnd={skipNextMoveEnd} />
            )}

            <ClusterLayer resources={resourcesWithGeo} />

            {resourcesWithGeo.map((resource) =>
                resource.geoLocations.map((geo) => {
                    const key = `${resource.id}-${geo.id}`;

                    // Render bounding box
                    if (geo.type === 'box' && geo.bounds) {
                        const bounds: L.LatLngBoundsExpression = [
                            [geo.bounds.south, geo.bounds.west],
                            [geo.bounds.north, geo.bounds.east],
                        ];
                        return (
                            <Rectangle
                                key={key}
                                bounds={bounds}
                                pathOptions={getShapePathOptions(resource.resourceTypeSlug, 'box')}
                            >
                                <Popup>
                                    <ResourcePopupContent resource={resource} />
                                </Popup>
                            </Rectangle>
                        );
                    }

                    // Render polygon
                    if (geo.type === 'polygon' && geo.polygon) {
                        const positions: L.LatLngExpression[] = geo.polygon.map(
                            (p) => [p.lat, p.lng],
                        );
                        return (
                            <Polygon
                                key={key}
                                positions={positions}
                                pathOptions={getShapePathOptions(resource.resourceTypeSlug, 'polygon')}
                            >
                                <Popup>
                                    <ResourcePopupContent resource={resource} />
                                </Popup>
                            </Polygon>
                        );
                    }

                    // Render line as polyline
                    if (geo.type === 'line' && geo.polygon) {
                        const positions: L.LatLngExpression[] = geo.polygon.map(
                            (p) => [p.lat, p.lng],
                        );
                        return (
                            <Polyline
                                key={key}
                                positions={positions}
                                pathOptions={getShapePathOptions(resource.resourceTypeSlug, 'line')}
                            >
                                <Popup>
                                    <ResourcePopupContent resource={resource} />
                                </Popup>
                            </Polyline>
                        );
                    }

                    return null;
                }),
            )}
            </MapContainer>
            <PortalMapLegend resources={resourcesWithGeo} />
        </div>
    ) : (
        <div className="flex h-full items-center justify-center bg-muted/30">
            <p className="text-sm text-muted-foreground">
                No geographic data available for current results
            </p>
        </div>
    );

    return (
        <div className={cn('flex h-full flex-col', className)} data-testid="portal-map-container">
            {/* Header-less mode for external header (resizable panel layout) */}
            {hideHeader && (
                <div className="h-full w-full">
                    {mapContent}
                </div>
            )}

            {/* Collapsible header for stacked layout (below 2xl) - only when header not hidden */}
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
                                <span className="text-sm text-muted-foreground">
                                    ({geoCount} {geoCount === 1 ? 'location' : 'locations'})
                                </span>
                            </div>
                            {isCollapsed ? (
                                <ChevronDown className="h-4 w-4" />
                            ) : (
                                <ChevronUp className="h-4 w-4" />
                            )}
                        </Button>
                    </CollapsibleTrigger>

                    <CollapsibleContent>
                        <div className="h-[300px] w-full">
                            {mapContent}
                        </div>
                    </CollapsibleContent>
                </Collapsible>
            )}

            {/* Non-collapsible full-height map for side panel (2xl+) - only when header not hidden */}
            {!hideHeader && (
                <div className="hidden h-full flex-col 2xl:flex">
                    <div className="flex items-center gap-2 border-b px-4 py-3">
                        <MapIcon className="h-4 w-4" />
                        <span className="font-medium">Map</span>
                        <span className="text-sm text-muted-foreground">
                            ({geoCount} {geoCount === 1 ? 'location' : 'locations'})
                        </span>
                    </div>
                    <div className="flex-1">
                        {mapContent}
                    </div>
                </div>
            )}
        </div>
    );
}
