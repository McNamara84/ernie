import 'leaflet/dist/leaflet.css';

import L from 'leaflet';
import markerIcon from 'leaflet/dist/images/marker-icon.png';
import markerIcon2x from 'leaflet/dist/images/marker-icon-2x.png';
import markerShadow from 'leaflet/dist/images/marker-shadow.png';
import { Maximize2, Minimize2 } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { MapContainer, TileLayer, useMap } from 'react-leaflet';

import { ErrorBoundary } from '@/components/error-boundary';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { GLOBAL_COVERAGE_MESSAGE, isGlobalCoverageBounds } from '@/lib/geo-coverage';
import { type GeoLocation, hasBox, hasInvalidGeometry, hasLine, hasPoint, hasPolygon, LOCATION_FIT_OPTIONS } from '@/lib/landing-page-geolocation';
import type { LandingPageIgsnMetadata } from '@/types/landing-page';

import { LandingPageCard } from './LandingPageCard';
import { GeoLocationLayer } from './LocationGeometry';
import { hasVisibleMetadataRows, MetadataList, type MetadataRow } from './MetadataList';

// Fix Leaflet default marker icons (they don't load correctly with bundlers)
// Using unknown as intermediate step for safer type assertion
const iconPrototype: unknown = L.Icon.Default.prototype;
delete (iconPrototype as { _getIconUrl?: () => string })._getIconUrl;
L.Icon.Default.mergeOptions({
    iconUrl: markerIcon,
    iconRetinaUrl: markerIcon2x,
    shadowUrl: markerShadow,
});

interface LocationSectionProps {
    geoLocations: GeoLocation[];
    /** Whether system dark mode is active. Passed down from the page root. */
    isDark?: boolean;
    /** Enables the physical-sample metadata rows and heading. */
    samplingLocation?: boolean;
    igsn?: LandingPageIgsnMetadata | null;
    /** Render the legacy combined card, text-only details, or the map only. */
    content?: 'combined' | 'details' | 'map';
    heading?: string;
}

const WORLD_BOUNDS: L.LatLngBoundsExpression = [
    [-90, -180],
    [90, 180],
];

function formatCoordinate(value: number): string {
    return value.toFixed(6);
}

function formatMeasurement(value: string | number | null | undefined, unit: string | null | undefined): string | null {
    if (value === null || value === undefined || String(value).trim() === '') return null;

    return `${String(value).trim()}${unit ? ` ${unit}` : ''}`;
}

/**
 * Check if a GeoLocation box covers the whole world.
 */
function isGlobalCoverage(geo: GeoLocation): boolean {
    return isGlobalCoverageBounds(
        {
            west: geo.west_bound_longitude,
            east: geo.east_bound_longitude,
            south: geo.south_bound_latitude,
            north: geo.north_bound_latitude,
        },
        geo.geo_type,
    );
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

    if (allPoints.every(([lat, lng]) => lat === allPoints[0][0] && lng === allPoints[0][1])) {
        // Single point: Create a small area around it
        const [lat, lng] = allPoints[0];
        return L.latLngBounds([
            [Math.max(-90, lat - 0.5), Math.max(-180, lng - 0.5)],
            [Math.min(90, lat + 0.5), Math.min(180, lng + 0.5)],
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
            map.fitBounds(bounds, LOCATION_FIT_OPTIONS);
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
    const controlRef = useCallback((element: HTMLDivElement | null) => {
        if (!element) return;
        // Treat this React-rendered button like a native Leaflet control, not a background map click.
        L.DomEvent.disableClickPropagation(element);
        return () => {
            L.DomEvent.off(element);
        };
    }, []);

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
            <div ref={controlRef} className="leaflet-control leaflet-bar">
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

function GlobalCoverageNotice() {
    return (
        <p
            className="mb-4 rounded-md border border-blue-200 bg-blue-50 px-3 py-2 text-sm text-gray-700 dark:border-blue-900/60 dark:bg-blue-950/30 dark:text-gray-200"
            data-testid="global-coverage-message"
        >
            {GLOBAL_COVERAGE_MESSAGE}
        </p>
    );
}

function LocationMap({ locations, tileUrl, attribution }: { locations: GeoLocation[]; tileUrl: string; attribution: string }) {
    const bounds = useMemo(() => calculateBounds(locations), [locations]);

    return (
        <MapContainer
            bounds={bounds}
            boundsOptions={LOCATION_FIT_OPTIONS}
            className="h-full w-full"
            maxBounds={WORLD_BOUNDS}
            maxBoundsViscosity={1}
            scrollWheelZoom={true}
            style={{ height: '100%', width: '100%' }}
            worldCopyJump={false}
        >
            <TileLayer attribution={attribution} noWrap={true} url={tileUrl} />
            <FitBoundsControl bounds={bounds} />
            <FullscreenControl />
            {locations.map((geo) => (
                <GeoLocationLayer key={geo.id} geoLocation={geo} />
            ))}
        </MapContainer>
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
export function LocationSection({
    geoLocations,
    isDark = false,
    samplingLocation = false,
    igsn = null,
    content = 'combined',
    heading: headingOverride,
}: LocationSectionProps) {
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

    const globalLocations = useMemo(() => validLocations.filter((geo) => isGlobalCoverage(geo)), [validLocations]);
    const mappableLocations = useMemo(() => validLocations.filter((geo) => !isGlobalCoverage(geo)), [validLocations]);

    const invalidLocations = useMemo(() => geoLocations.filter(hasInvalidGeometry), [geoLocations]);
    const hasGlobalCoverage = globalLocations.length > 0;
    const hasMappableLocations = mappableLocations.length > 0;
    const primary = geoLocations[0];
    const primaryElevationRange = igsn?.elevation_ranges?.[0];
    const elevation =
        formatMeasurement(primaryElevationRange?.start, primaryElevationRange?.unit) ??
        formatMeasurement(primary?.elevation, primary?.elevation_unit);
    const finalDepth = formatMeasurement(primaryElevationRange?.end, primaryElevationRange?.end_unit ?? primaryElevationRange?.unit);
    const rows: MetadataRow[] = samplingLocation
        ? [
              { label: 'Latitude', value: primary && hasPoint(primary) ? formatCoordinate(primary.point_latitude!) : null },
              { label: 'Longitude', value: primary && hasPoint(primary) ? formatCoordinate(primary.point_longitude!) : null },
              { label: 'South Bound Latitude', value: primary && hasBox(primary) ? formatCoordinate(primary.south_bound_latitude!) : null },
              { label: 'West Bound Longitude', value: primary && hasBox(primary) ? formatCoordinate(primary.west_bound_longitude!) : null },
              { label: 'North Bound Latitude', value: primary && hasBox(primary) ? formatCoordinate(primary.north_bound_latitude!) : null },
              { label: 'East Bound Longitude', value: primary && hasBox(primary) ? formatCoordinate(primary.east_bound_longitude!) : null },
              { label: 'Polygon', value: primary && hasPolygon(primary) ? `${primary.polygon_points!.length} coordinate pairs` : null },
              { label: 'Coordinate System', value: igsn?.coordinate_system ?? null },
              { label: 'Elevation', value: elevation },
              { label: 'Final Depth', value: finalDepth },
              { label: 'Location Type', value: primary?.location_type ?? null },
              { label: 'Location Name', value: primary?.place ?? null },
              { label: 'Location Description', value: primary?.location_description ?? null },
              { label: 'Locality Description', value: primary?.locality_description ?? null },
              { label: 'Country', value: primary?.country ?? null },
              { label: 'Province', value: primary?.province ?? null },
              { label: 'County', value: primary?.county ?? null },
              { label: 'City', value: primary?.city ?? null },
          ]
        : [];
    const hasDetails = hasVisibleMetadataRows(rows);
    const heading = headingOverride ?? (samplingLocation ? 'Sampling Location' : 'Location');
    const headingId = content === 'map' ? 'heading-map' : 'heading-location';
    const showDetails = content !== 'map' && hasDetails;
    const showMap = content !== 'details' && (hasGlobalCoverage || hasMappableLocations);

    if (!showDetails && !showMap && invalidLocations.length === 0) {
        return null;
    }

    // Tile layer URL based on dark mode
    // Light: OpenStreetMap (free, no API key). Dark: CartoDB dark_all (free, no API key required).
    const tileUrl = isDark ? 'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png' : 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png';
    const tileAttribution = isDark
        ? '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors &copy; <a href="https://carto.com/attributions">CARTO</a>'
        : '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors';

    // Show loading placeholder during SSR — always visible (no fade-in gating)
    if (!isMounted && content !== 'details' && hasMappableLocations) {
        return (
            <LandingPageCard disableFadeIn aria-labelledby={headingId}>
                <h2 id={headingId} className="mb-4 text-lg font-semibold text-gray-900 dark:text-gray-100">
                    {heading}
                </h2>
                {showDetails && (
                    <div className="mb-4">
                        <MetadataList rows={rows} />
                    </div>
                )}
                {showMap && hasGlobalCoverage && <GlobalCoverageNotice />}
                <Skeleton className="aspect-square w-full rounded-lg" />
            </LandingPageCard>
        );
    }

    return (
        <LandingPageCard aria-labelledby={headingId} data-testid="geolocation-section">
            <h2 id={headingId} className="mb-4 text-lg font-semibold text-gray-900 dark:text-gray-100">
                {heading}
            </h2>
            {showDetails && (
                <div className="mb-4">
                    <MetadataList rows={rows} />
                </div>
            )}
            {showMap && hasGlobalCoverage && <GlobalCoverageNotice />}
            {invalidLocations.length > 0 && (
                <p className="mb-4 text-sm text-gray-600 dark:text-gray-300" data-testid="invalid-geolocation-message">
                    Some location information could not be displayed on the map.
                </p>
            )}
            {showMap && hasMappableLocations && (
                <div
                    className="relative z-0 aspect-square w-full overflow-hidden rounded-lg"
                    data-testid="map-container"
                    aria-label="Map showing the geographic location of the dataset"
                >
                    <ErrorBoundary
                        key={JSON.stringify(mappableLocations)}
                        fallback={
                            <p role="status" className="p-4 text-sm">
                                The location map is currently unavailable.
                            </p>
                        }
                    >
                        <LocationMap locations={mappableLocations} tileUrl={tileUrl} attribution={tileAttribution} />
                    </ErrorBoundary>
                </div>
            )}
        </LandingPageCard>
    );
}
