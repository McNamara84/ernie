import type { PolygonPoint } from './types';

/** Stored malformed coordinates remain editable but must never reach Google Maps. */
export function isValidPolygonPoint(point: PolygonPoint): point is { lat: number; lon: number } {
    return (
        typeof point.lat === 'number' &&
        Number.isFinite(point.lat) &&
        Math.abs(point.lat) <= 90 &&
        typeof point.lon === 'number' &&
        Number.isFinite(point.lon) &&
        Math.abs(point.lon) <= 180
    );
}
