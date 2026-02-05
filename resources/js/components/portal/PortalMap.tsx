import 'leaflet/dist/leaflet.css';

import L from 'leaflet';
import markerIcon from 'leaflet/dist/images/marker-icon.png';
import markerIcon2x from 'leaflet/dist/images/marker-icon-2x.png';
import markerShadow from 'leaflet/dist/images/marker-shadow.png';
import { ChevronDown, ChevronUp, Map as MapIcon } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { MapContainer, Marker, Polygon, Popup, Rectangle, TileLayer, useMap } from 'react-leaflet';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { cn } from '@/lib/utils';
import type { PortalCreator, PortalResource } from '@/types/portal';

// Fix Leaflet default marker icons
const iconPrototype: unknown = L.Icon.Default.prototype;
delete (iconPrototype as { _getIconUrl?: () => string })._getIconUrl;
L.Icon.Default.mergeOptions({
    iconUrl: markerIcon,
    iconRetinaUrl: markerIcon2x,
    shadowUrl: markerShadow,
});

// GFZ Corporate Blue for shapes
const GFZ_BLUE = '#0C2A63';

interface PortalMapProps {
    resources: PortalResource[];
    className?: string;
    /** Hide the header (used when header is rendered externally) */
    hideHeader?: boolean;
}

/**
 * Format authors for popup display.
 */
function formatAuthorsShort(creators: PortalCreator[]): string {
    if (creators.length === 0) return 'Unknown';
    if (creators.length === 1) return creators[0].name;
    if (creators.length === 2) return `${creators[0].name} & ${creators[1].name}`;
    return `${creators[0].name} et al.`;
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
 * Component to fit map bounds when resources change.
 */
function FitBoundsControl({ resources }: { resources: PortalResource[] }) {
    const map = useMap();

    useEffect(() => {
        const bounds = calculateBounds(resources);
        if (bounds && bounds.isValid()) {
            map.fitBounds(bounds, { padding: [30, 30], maxZoom: 10 });
        } else {
            // Default world view
            map.setView([30, 0], 2);
        }
    }, [map, resources]);

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
export function PortalMap({ resources, className, hideHeader = false }: PortalMapProps) {
    const [isCollapsed, setIsCollapsed] = useState(false);
    const mapRef = useRef<L.Map | null>(null);

    // Filter resources that have geo locations
    const resourcesWithGeo = useMemo(
        () => resources.filter((r) => r.geoLocations.length > 0),
        [resources],
    );

    const geoCount = resourcesWithGeo.reduce((acc, r) => acc + r.geoLocations.length, 0);

    // Invalidate map size when collapsible state changes or on resize
    useEffect(() => {
        if (!isCollapsed && mapRef.current) {
            setTimeout(() => {
                mapRef.current?.invalidateSize();
            }, 300);
        }
    }, [isCollapsed]);

    // Re-invalidate map on window resize (for responsive layout changes)
    useEffect(() => {
        const handleResize = () => {
            if (mapRef.current) {
                mapRef.current.invalidateSize();
            }
        };

        window.addEventListener('resize', handleResize);
        return () => window.removeEventListener('resize', handleResize);
    }, []);

    const mapContent = resourcesWithGeo.length > 0 ? (
        <MapContainer
            center={[30, 0]}
            zoom={2}
            className="h-full w-full"
            ref={mapRef}
        >
            <TileLayer
                attribution='&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
                url="https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png"
            />
            <FitBoundsControl resources={resourcesWithGeo} />

            {resourcesWithGeo.map((resource) =>
                resource.geoLocations.map((geo) => {
                    const key = `${resource.id}-${geo.id}`;

                    // Render point marker
                    if (geo.type === 'point' && geo.point) {
                        return (
                            <Marker
                                key={key}
                                position={[geo.point.lat, geo.point.lng]}
                            >
                                <Popup>
                                    <ResourcePopupContent resource={resource} />
                                </Popup>
                            </Marker>
                        );
                    }

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
                                pathOptions={{
                                    color: GFZ_BLUE,
                                    weight: 2,
                                    fillOpacity: 0.2,
                                }}
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
                                pathOptions={{
                                    color: GFZ_BLUE,
                                    weight: 2,
                                    fillOpacity: 0.2,
                                }}
                            >
                                <Popup>
                                    <ResourcePopupContent resource={resource} />
                                </Popup>
                            </Polygon>
                        );
                    }

                    return null;
                }),
            )}
        </MapContainer>
    ) : (
        <div className="flex h-full items-center justify-center bg-muted/30">
            <p className="text-sm text-muted-foreground">
                No geographic data available for current results
            </p>
        </div>
    );

    return (
        <div className={cn('flex h-full flex-col', className)} data-testid="map">
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
