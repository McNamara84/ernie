import 'leaflet/dist/leaflet.css';

import L from 'leaflet';
import markerIcon2x from 'leaflet/dist/images/marker-icon-2x.png';
import markerIcon from 'leaflet/dist/images/marker-icon.png';
import markerShadow from 'leaflet/dist/images/marker-shadow.png';
import { Maximize2, Minimize2 } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { MapContainer, Marker, Polygon, Polyline, Rectangle, TileLayer, useMap } from 'react-leaflet';

import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';

import { LandingPageCard } from './LandingPageCard';

// Fix Leaflet default marker icons (they don't load correctly with bundlers)
// Using unknown as intermediate step for safer type assertion
const iconPrototype: unknown = L.Icon.Default.prototype;
delete (iconPrototype as { _getIconUrl?: () => string })._getIconUrl;
L.Icon.Default.mergeOptions({
    iconUrl: markerIcon,
    iconRetinaUrl: markerIcon2x,
    shadowUrl: markerShadow,
});

/**
 * GeoLocation interface matching the backend model
 */
interface GeoLocation {
    id: number;
    place: string | null;
    point_longitude: number | null;
    point_latitude: number | null;
    west_bound_longitude: number | null;
    east_bound_longitude: number | null;
    south_bound_latitude: number | null;
    north_bound_latitude: number | null;
    polygon_points: Array<{ longitude: number; latitude: number }> | null;
    geo_type: string | null;
}

interface LocationSectionProps {
    geoLocations: GeoLocation[];
    /** Whether system dark mode is active. Passed down from the page root. */
    isDark?: boolean;
}

// GFZ Corporate Blue
const GFZ_BLUE = '#0C2A63';

/**
 * Check if a GeoLocation has a valid point defined
 */
function hasPoint(geo: GeoLocation): boolean {
    return geo.point_longitude !== null && geo.point_latitude !== null;
}

/**
 * Check if a GeoLocation has a valid bounding box defined
 */
function hasBox(geo: GeoLocation): boolean {
    return (
        geo.west_bound_longitude !== null &&
        geo.east_bound_longitude !== null &&
        geo.south_bound_latitude !== null &&
        geo.north_bound_latitude !== null
    );
}

/**
 * Check if a GeoLocation has a valid polygon defined (minimum 3 points)
 */
function hasPolygon(geo: GeoLocation): boolean {
    if (geo.geo_type === 'line') return false;
    return geo.polygon_points !== null && geo.polygon_points.length >= 3;
}

/**
 * Check if a GeoLocation has a valid line defined (minimum 2 points)
 */
function hasLine(geo: GeoLocation): boolean {
    return geo.geo_type === 'line' && geo.polygon_points !== null && geo.polygon_points.length >= 2;
}

/**
 * Calculate bounds that encompass all GeoLocations
 */
function calculateBounds(locations: GeoLocation[]): L.LatLngBounds {
    const allPoints: L.LatLngTuple[] = [];

    locations.forEach((geo) => {
        if (hasPoint(geo)) {
            allPoints.push([geo.point_latitude!, geo.point_longitude!]);
        }
        if (hasBox(geo)) {
            allPoints.push([geo.south_bound_latitude!, geo.west_bound_longitude!]);
            allPoints.push([geo.north_bound_latitude!, geo.east_bound_longitude!]);
        }
        if (hasPolygon(geo)) {
            geo.polygon_points!.forEach((p) => {
                allPoints.push([p.latitude, p.longitude]);
            });
        }
        if (hasLine(geo)) {
            geo.polygon_points!.forEach((p) => {
                allPoints.push([p.latitude, p.longitude]);
            });
        }
    });

    if (allPoints.length === 0) {
        // Fallback: World view
        return L.latLngBounds([
            [-60, -180],
            [80, 180],
        ]);
    }

    if (allPoints.length === 1) {
        // Single point: Create a small area around it
        const [lat, lng] = allPoints[0];
        return L.latLngBounds([
            [lat - 0.5, lng - 0.5],
            [lat + 0.5, lng + 0.5],
        ]);
    }

    return L.latLngBounds(allPoints);
}

/**
 * Component to fit map bounds when locations change
 */
function FitBoundsControl({ bounds }: { bounds: L.LatLngBounds }) {
    const map = useMap();

    useEffect(() => {
        if (bounds.isValid()) {
            map.fitBounds(bounds, { padding: [20, 20] });
        }
    }, [map, bounds]);

    return null;
}

/**
 * Check if the Fullscreen API is available in the current browser.
 * Includes vendor prefix detection for webkit (Safari) and moz (older Firefox).
 */
function isFullscreenSupported(): boolean {
    if (typeof document === 'undefined') return false;

    const docEl = document.documentElement as HTMLElement & {
        webkitRequestFullscreen?: () => Promise<void>;
        mozRequestFullScreen?: () => Promise<void>;
    };

    return (
        typeof docEl.requestFullscreen === 'function' ||
        typeof docEl.webkitRequestFullscreen === 'function' ||
        typeof docEl.mozRequestFullScreen === 'function'
    );
}

/**
 * Request fullscreen with vendor prefix fallback.
 */
function requestFullscreen(element: HTMLElement): Promise<void> {
    const el = element as HTMLElement & {
        webkitRequestFullscreen?: () => Promise<void>;
        mozRequestFullScreen?: () => Promise<void>;
    };

    if (el.requestFullscreen) {
        return el.requestFullscreen();
    } else if (el.webkitRequestFullscreen) {
        return el.webkitRequestFullscreen();
    } else if (el.mozRequestFullScreen) {
        return el.mozRequestFullScreen();
    }
    return Promise.reject(new Error('Fullscreen API not supported'));
}

/**
 * Exit fullscreen with vendor prefix fallback.
 */
function exitFullscreen(): Promise<void> {
    const doc = document as Document & {
        webkitExitFullscreen?: () => Promise<void>;
        mozCancelFullScreen?: () => Promise<void>;
    };

    if (doc.exitFullscreen) {
        return doc.exitFullscreen();
    } else if (doc.webkitExitFullscreen) {
        return doc.webkitExitFullscreen();
    } else if (doc.mozCancelFullScreen) {
        return doc.mozCancelFullScreen();
    }
    return Promise.reject(new Error('Fullscreen API not supported'));
}

/**
 * Get the current fullscreen element with vendor prefix fallback.
 */
function getFullscreenElement(): Element | null {
    const doc = document as Document & {
        webkitFullscreenElement?: Element | null;
        mozFullScreenElement?: Element | null;
    };

    return doc.fullscreenElement || doc.webkitFullscreenElement || doc.mozFullScreenElement || null;
}

/**
 * Custom Fullscreen Control Button
 * Only renders if the Fullscreen API is available in the browser
 */
function FullscreenControl() {
    const map = useMap();
    const [isFullscreen, setIsFullscreen] = useState(false);
    const [isSupported, setIsSupported] = useState(false);
    const containerRef = useRef<HTMLElement | null>(null);

    useEffect(() => {
        // Check fullscreen support on mount
        setIsSupported(isFullscreenSupported());
    }, []);

    useEffect(() => {
        // Get the container reference from the map
        const container = map.getContainer();
        containerRef.current = container;

        const handleFullscreenChange = () => {
            setIsFullscreen(!!getFullscreenElement());
            // Invalidate map size after fullscreen change
            setTimeout(() => {
                map.invalidateSize();
            }, 100);
        };

        // Add event listeners for all vendor prefixes
        document.addEventListener('fullscreenchange', handleFullscreenChange);
        document.addEventListener('webkitfullscreenchange', handleFullscreenChange);
        document.addEventListener('mozfullscreenchange', handleFullscreenChange);

        return () => {
            document.removeEventListener('fullscreenchange', handleFullscreenChange);
            document.removeEventListener('webkitfullscreenchange', handleFullscreenChange);
            document.removeEventListener('mozfullscreenchange', handleFullscreenChange);
        };
    }, [map]);

    const toggleFullscreen = () => {
        const container = containerRef.current;
        if (!container) return;

        if (!getFullscreenElement()) {
            requestFullscreen(container).catch((err) => {
                console.warn('Fullscreen request failed:', err);
            });
        } else {
            exitFullscreen().catch((err) => {
                console.warn('Exit fullscreen failed:', err);
            });
        }
    };

    // Don't render the button if fullscreen is not supported
    if (!isSupported) {
        return null;
    }

    return (
        <div className="leaflet-top leaflet-right">
            <div className="leaflet-control leaflet-bar">
                <Button
                    variant="ghost"
                    size="icon"
                    onClick={toggleFullscreen}
                    className="min-h-11 min-w-11 rounded-none bg-white hover:bg-gray-100"
                    title={isFullscreen ? 'Exit Fullscreen' : 'Fullscreen'}
                    aria-label={isFullscreen ? 'Exit Fullscreen' : 'Fullscreen'}
                >
                    {isFullscreen ? <Minimize2 className="h-4 w-4 text-gray-700" /> : <Maximize2 className="h-4 w-4 text-gray-700" />}
                </Button>
            </div>
        </div>
    );
}

/**
 * Renders the appropriate geometry for a single GeoLocation
 */
function GeoLocationLayer({ geoLocation }: { geoLocation: GeoLocation }) {
    return (
        <>
            {/* Point as Marker */}
            {hasPoint(geoLocation) && <Marker position={[geoLocation.point_latitude!, geoLocation.point_longitude!]} />}

            {/* Bounding Box as Rectangle */}
            {hasBox(geoLocation) && (
                <Rectangle
                    bounds={[
                        [geoLocation.south_bound_latitude!, geoLocation.west_bound_longitude!],
                        [geoLocation.north_bound_latitude!, geoLocation.east_bound_longitude!],
                    ]}
                    pathOptions={{
                        color: GFZ_BLUE,
                        weight: 2,
                        fillOpacity: 0.2,
                    }}
                />
            )}

            {/* Polygon as filled area */}
            {hasPolygon(geoLocation) && (
                <Polygon
                    positions={geoLocation.polygon_points!.map((p) => [p.latitude, p.longitude])}
                    pathOptions={{
                        color: GFZ_BLUE,
                        weight: 2,
                        fillOpacity: 0.2,
                    }}
                />
            )}

            {/* Line as polyline (open path) */}
            {hasLine(geoLocation) && (
                <Polyline
                    positions={geoLocation.polygon_points!.map((p) => [p.latitude, p.longitude])}
                    pathOptions={{
                        color: GFZ_BLUE,
                        weight: 3,
                        dashArray: '8, 4',
                    }}
                />
            )}
        </>
    );
}

/**
 * LocationSection Component
 *
 * Displays spatial coverage on an interactive OpenStreetMap.
 * Supports points, bounding boxes, and polygons.
 * Auto-zooms to fit all locations with padding.
 * Hidden when no valid geo locations are available.
 */
export function LocationSection({ geoLocations, isDark = false }: LocationSectionProps) {
    const [isMounted, setIsMounted] = useState(false);

    // Client-side only rendering (Leaflet needs window/document)
    useEffect(() => {
        setIsMounted(true);
    }, []);

    // Filter: Only GeoLocations with actual coordinates
    const validLocations = useMemo(
        () => geoLocations.filter((geo) => hasPoint(geo) || hasBox(geo) || hasPolygon(geo) || hasLine(geo)),
        [geoLocations],
    );

    // Calculate bounds for auto-zoom
    const bounds = useMemo(() => calculateBounds(validLocations), [validLocations]);

    // Don't render if no valid locations
    if (validLocations.length === 0) {
        return null;
    }

    // Tile layer URL based on dark mode
    // Light: OpenStreetMap (free, no API key). Dark: CartoDB dark_all (free, no API key required).
    const tileUrl = isDark ? 'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png' : 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png';
    const tileAttribution = isDark
        ? '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors &copy; <a href="https://carto.com/attributions">CARTO</a>'
        : '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors';

    // Show loading placeholder during SSR — always visible (no fade-in gating)
    if (!isMounted) {
        return (
            <LandingPageCard disableFadeIn aria-labelledby="heading-location">
                <h2 id="heading-location" className="mb-4 text-lg font-semibold text-gray-900 dark:text-gray-100">
                    Location
                </h2>
                <Skeleton className="h-[300px] w-full rounded-lg" />
            </LandingPageCard>
        );
    }

    return (
        <LandingPageCard aria-labelledby="heading-location" data-testid="geolocation-section">
            <h2 id="heading-location" className="mb-4 text-lg font-semibold text-gray-900 dark:text-gray-100">
                Location
            </h2>
            <div
                className="relative z-0 h-[300px] w-full overflow-hidden rounded-lg"
                data-testid="map-container"
                aria-label="Map showing the geographic location of the dataset"
            >
                <MapContainer bounds={bounds} className="h-full w-full" scrollWheelZoom={true} style={{ height: '100%', width: '100%' }}>
                    <TileLayer attribution={tileAttribution} url={tileUrl} />
                    <FitBoundsControl bounds={bounds} />
                    <FullscreenControl />

                    {validLocations.map((geo) => (
                        <GeoLocationLayer key={geo.id} geoLocation={geo} />
                    ))}
                </MapContainer>
            </div>
        </LandingPageCard>
    );
}
