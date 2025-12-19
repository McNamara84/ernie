import L from 'leaflet';
import markerIcon2x from 'leaflet/dist/images/marker-icon-2x.png';
import markerIcon from 'leaflet/dist/images/marker-icon.png';
import markerShadow from 'leaflet/dist/images/marker-shadow.png';
import 'leaflet/dist/leaflet.css';
import { Maximize2, Minimize2 } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { MapContainer, Marker, Polygon, Rectangle, TileLayer, useMap } from 'react-leaflet';

// Fix Leaflet default marker icons (they don't load correctly with bundlers)
delete (L.Icon.Default.prototype as unknown as { _getIconUrl?: () => string })._getIconUrl;
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
}

interface LocationSectionProps {
    geoLocations: GeoLocation[];
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
    return geo.polygon_points !== null && geo.polygon_points.length >= 3;
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
    });

    if (allPoints.length === 0) {
        // Fallback: World view
        return L.latLngBounds([[-60, -180], [80, 180]]);
    }

    if (allPoints.length === 1) {
        // Single point: Create a small area around it
        const [lat, lng] = allPoints[0];
        return L.latLngBounds([[lat - 0.5, lng - 0.5], [lat + 0.5, lng + 0.5]]);
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
 * Custom Fullscreen Control Button
 */
function FullscreenControl() {
    const map = useMap();
    const [isFullscreen, setIsFullscreen] = useState(false);
    const containerRef = useRef<HTMLElement | null>(null);

    useEffect(() => {
        containerRef.current = map.getContainer();

        const handleFullscreenChange = () => {
            setIsFullscreen(!!document.fullscreenElement);
            // Invalidate map size after fullscreen change
            setTimeout(() => {
                map.invalidateSize();
            }, 100);
        };

        document.addEventListener('fullscreenchange', handleFullscreenChange);

        return () => {
            document.removeEventListener('fullscreenchange', handleFullscreenChange);
        };
    }, [map]);

    const toggleFullscreen = () => {
        if (!document.fullscreenElement && containerRef.current) {
            containerRef.current.requestFullscreen().catch((err) => {
                console.warn('Fullscreen request failed:', err);
            });
        } else if (document.fullscreenElement) {
            document.exitFullscreen().catch((err) => {
                console.warn('Exit fullscreen failed:', err);
            });
        }
    };

    return (
        <div className="leaflet-top leaflet-right">
            <div className="leaflet-control leaflet-bar">
                <button
                    onClick={toggleFullscreen}
                    className="flex h-[30px] w-[30px] items-center justify-center bg-white hover:bg-gray-100"
                    title={isFullscreen ? 'Exit Fullscreen' : 'Fullscreen'}
                    aria-label={isFullscreen ? 'Exit Fullscreen' : 'Fullscreen'}
                >
                    {isFullscreen ? (
                        <Minimize2 className="h-4 w-4 text-gray-700" />
                    ) : (
                        <Maximize2 className="h-4 w-4 text-gray-700" />
                    )}
                </button>
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
            {hasPoint(geoLocation) && (
                <Marker
                    position={[geoLocation.point_latitude!, geoLocation.point_longitude!]}
                />
            )}

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
export function LocationSection({ geoLocations }: LocationSectionProps) {
    const [isMounted, setIsMounted] = useState(false);

    // Client-side only rendering (Leaflet needs window/document)
    useEffect(() => {
        setIsMounted(true);
    }, []);

    // Filter: Only GeoLocations with actual coordinates
    const validLocations = useMemo(
        () => geoLocations.filter((geo) => hasPoint(geo) || hasBox(geo) || hasPolygon(geo)),
        [geoLocations],
    );

    // Calculate bounds for auto-zoom
    const bounds = useMemo(() => calculateBounds(validLocations), [validLocations]);

    // Don't render if no valid locations
    if (validLocations.length === 0) {
        return null;
    }

    // Show loading placeholder during SSR
    if (!isMounted) {
        return (
            <div className="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
                <h3 className="mb-4 text-lg font-semibold text-gray-900">Location</h3>
                <div className="h-[300px] w-full animate-pulse rounded-lg bg-gray-100" />
            </div>
        );
    }

    return (
        <div className="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
            <h3 className="mb-4 text-lg font-semibold text-gray-900">Location</h3>
            <div className="h-[300px] w-full overflow-hidden rounded-lg">
                <MapContainer
                    bounds={bounds}
                    className="h-full w-full"
                    scrollWheelZoom={true}
                    style={{ height: '100%', width: '100%' }}
                >
                    <TileLayer
                        attribution='&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
                        url="https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png"
                    />
                    <FitBoundsControl bounds={bounds} />
                    <FullscreenControl />

                    {validLocations.map((geo) => (
                        <GeoLocationLayer key={geo.id} geoLocation={geo} />
                    ))}
                </MapContainer>
            </div>
        </div>
    );
}
