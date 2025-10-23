import { MapPin } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

// ============================================================================
// Type Definitions
// ============================================================================

/**
 * Coverage entry with spatial (lat/lon) and temporal (dates) information
 */
interface Coverage {
    id?: number;
    lat_min?: number | null;
    lat_max?: number | null;
    lon_min?: number | null;
    lon_max?: number | null;
    start_date?: string | null;
    end_date?: string | null;
    start_time?: string | null;
    end_time?: string | null;
    timezone?: string | null;
    description?: string | null;
}

/**
 * Resource shape for LocationMap
 */
interface Resource {
    coverages?: Coverage[];
}

/**
 * Props for LocationMap component
 */
interface LocationMapProps {
    resource: Resource;
    heading?: string;
    height?: string; // CSS height value (e.g., '400px', '50vh')
    zoom?: number; // Initial zoom level (1-20)
    showLegend?: boolean;
}

// ============================================================================
// Helper Functions
// ============================================================================

/**
 * Check if coverage has valid spatial coordinates
 */
function hasValidCoordinates(coverage: Coverage): boolean {
    return (
        coverage.lat_min != null &&
        coverage.lat_max != null &&
        coverage.lon_min != null &&
        coverage.lon_max != null &&
        !isNaN(coverage.lat_min) &&
        !isNaN(coverage.lat_max) &&
        !isNaN(coverage.lon_min) &&
        !isNaN(coverage.lon_max)
    );
}

/**
 * Calculate center point of bounding box
 */
function calculateCenter(coverage: Coverage): { lat: number; lng: number } {
    const lat = ((coverage.lat_min || 0) + (coverage.lat_max || 0)) / 2;
    const lng = ((coverage.lon_min || 0) + (coverage.lon_max || 0)) / 2;
    return { lat, lng };
}

/**
 * Check if coverage is a point (not a box)
 */
function isPoint(coverage: Coverage): boolean {
    return (
        coverage.lat_min === coverage.lat_max &&
        coverage.lon_min === coverage.lon_max
    );
}

/**
 * Format date for display
 */
function formatDate(dateString: string | null | undefined): string {
    if (!dateString) return '';
    try {
        const date = new Date(dateString);
        return date.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
    } catch {
        return dateString;
    }
}

/**
 * Format temporal coverage for display
 */
function formatTemporalCoverage(coverage: Coverage): string {
    const parts: string[] = [];

    if (coverage.start_date) {
        parts.push(`From: ${formatDate(coverage.start_date)}`);
    }
    if (coverage.end_date) {
        parts.push(`To: ${formatDate(coverage.end_date)}`);
    }

    return parts.join(' | ');
}

// ============================================================================
// Main Component
// ============================================================================

/**
 * LocationMap displays spatial coverage on Google Maps
 * Falls back to static description if Google Maps API is not available
 */
export default function LocationMap({
    resource,
    heading = 'Location',
    height = '400px',
    zoom = 4,
    showLegend = true,
}: LocationMapProps) {
    const mapRef = useRef<HTMLDivElement>(null);
    const [mapError, setMapError] = useState<boolean>(false);

    const coverages = resource.coverages || [];
    const validCoverages = coverages.filter(hasValidCoordinates);

    useEffect(() => {
        // Don't initialize if no valid coverages
        if (validCoverages.length === 0) {
            return;
        }

        // Check if Google Maps is available
        if (typeof window.google === 'undefined' || !window.google.maps) {
            setMapError(true);
            return;
        }

        // Don't initialize if map ref is not ready
        if (!mapRef.current) {
            return;
        }

        // Initialize map centered on first coverage
        const firstCoverage = validCoverages[0];
        const center = calculateCenter(firstCoverage);

        try {
            const map = new window.google.maps.Map(mapRef.current, {
                center,
                zoom,
                mapTypeId: window.google.maps.MapTypeId.TERRAIN,
                streetViewControl: false,
                mapTypeControl: true,
                fullscreenControl: true,
            });

            // Add markers/rectangles for each coverage
            validCoverages.forEach((coverage, index) => {
                if (isPoint(coverage)) {
                    // Add marker for point location
                    const marker = new window.google.maps.Marker({
                        position: {
                            lat: coverage.lat_min!,
                            lng: coverage.lon_min!,
                        },
                        map,
                        title: coverage.description || `Location ${index + 1}`,
                    });

                    // Info window with details
                    const infoWindow = new window.google.maps.InfoWindow({
                        content: `
                            <div style="padding: 8px; max-width: 250px;">
                                <strong>${coverage.description || `Location ${index + 1}`}</strong>
                                <br />
                                <small>Lat: ${coverage.lat_min?.toFixed(6)}, Lng: ${coverage.lon_min?.toFixed(6)}</small>
                                ${formatTemporalCoverage(coverage) ? `<br /><small>${formatTemporalCoverage(coverage)}</small>` : ''}
                            </div>
                        `,
                    });

                    marker.addListener('click', () => {
                        infoWindow.open(map, marker);
                    });
                } else {
                    // Add rectangle for bounding box
                    const bounds = {
                        north: coverage.lat_max!,
                        south: coverage.lat_min!,
                        east: coverage.lon_max!,
                        west: coverage.lon_min!,
                    };

                    const rectangle = new window.google.maps.Rectangle({
                        bounds,
                        map,
                        fillColor: '#4F46E5',
                        fillOpacity: 0.2,
                        strokeColor: '#4F46E5',
                        strokeOpacity: 0.8,
                        strokeWeight: 2,
                    });

                    // Info window for rectangle
                    const infoWindow = new window.google.maps.InfoWindow();

                    rectangle.addListener('click', (e: google.maps.MapMouseEvent) => {
                        if (!e.latLng) return;

                        infoWindow.setContent(`
                            <div style="padding: 8px; max-width: 250px;">
                                <strong>${coverage.description || `Coverage Area ${index + 1}`}</strong>
                                <br />
                                <small>
                                    N: ${coverage.lat_max?.toFixed(6)}°, 
                                    S: ${coverage.lat_min?.toFixed(6)}°
                                    <br />
                                    E: ${coverage.lon_max?.toFixed(6)}°, 
                                    W: ${coverage.lon_min?.toFixed(6)}°
                                </small>
                                ${formatTemporalCoverage(coverage) ? `<br /><small>${formatTemporalCoverage(coverage)}</small>` : ''}
                            </div>
                        `);
                        infoWindow.setPosition(e.latLng);
                        infoWindow.open(map);
                    });
                }
            });

            // Fit bounds to show all coverages if multiple
            if (validCoverages.length > 1) {
                const bounds = new window.google.maps.LatLngBounds();
                validCoverages.forEach((coverage) => {
                    bounds.extend({ lat: coverage.lat_min!, lng: coverage.lon_min! });
                    bounds.extend({ lat: coverage.lat_max!, lng: coverage.lon_max! });
                });
                map.fitBounds(bounds);
            }
        } catch (error) {
            console.error('Failed to initialize Google Maps:', error);
            setMapError(true);
        }
    }, [validCoverages, zoom]);

    // Don't render if no coverages with coordinates
    if (validCoverages.length === 0) {
        return null;
    }

    return (
        <section className="space-y-4" aria-label={heading}>
            {/* Heading */}
            <div className="flex items-center gap-2">
                <MapPin className="h-5 w-5 text-indigo-600 dark:text-indigo-400" aria-hidden="true" />
                <h2 className="text-xl font-semibold text-gray-900 dark:text-gray-100">
                    {heading}
                </h2>
            </div>

            {/* Map Container */}
            {!mapError ? (
                <div
                    ref={mapRef}
                    data-testid="location-map"
                    style={{ height }}
                    className="w-full rounded-lg border border-gray-200 dark:border-gray-700"
                    role="application"
                    aria-label="Interactive map showing dataset spatial coverage"
                />
            ) : (
                <div
                    className="flex items-center justify-center rounded-lg border border-gray-200 bg-gray-50 p-8 dark:border-gray-700 dark:bg-gray-800"
                    style={{ height }}
                >
                    <div className="text-center">
                        <MapPin className="mx-auto h-12 w-12 text-gray-400" aria-hidden="true" />
                        <p className="mt-2 text-sm text-gray-600 dark:text-gray-400">
                            Map unavailable. Google Maps API not loaded.
                        </p>
                    </div>
                </div>
            )}

            {/* Legend with Coverage Details */}
            {showLegend && (
                <div className="space-y-2">
                    {validCoverages.map((coverage, index) => (
                        <div
                            key={coverage.id || index}
                            className="rounded-md border border-gray-200 bg-white p-3 text-sm dark:border-gray-700 dark:bg-gray-800"
                        >
                            <div className="font-medium text-gray-900 dark:text-gray-100">
                                {coverage.description || `Coverage ${index + 1}`}
                            </div>
                            <div className="mt-1 text-gray-600 dark:text-gray-400">
                                {isPoint(coverage) ? (
                                    <>
                                        <strong>Point:</strong> {coverage.lat_min?.toFixed(6)}°,{' '}
                                        {coverage.lon_min?.toFixed(6)}°
                                    </>
                                ) : (
                                    <>
                                        <strong>Bounding Box:</strong> N: {coverage.lat_max?.toFixed(4)}°, S:{' '}
                                        {coverage.lat_min?.toFixed(4)}°, E: {coverage.lon_max?.toFixed(4)}°, W:{' '}
                                        {coverage.lon_min?.toFixed(4)}°
                                    </>
                                )}
                            </div>
                            {formatTemporalCoverage(coverage) && (
                                <div className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                    {formatTemporalCoverage(coverage)}
                                </div>
                            )}
                        </div>
                    ))}
                </div>
            )}
        </section>
    );
}
