import type { GeoBounds } from '@/types/portal';

const EPSILON = 1e-9;

export function normalizeLongitude(longitude: number): number {
    const normalized = ((((longitude + 180) % 360) + 360) % 360) - 180;

    return Object.is(normalized, -0) ? 0 : normalized;
}

export function longitudeSpan(west: number, east: number): number {
    const difference = east - west;
    if (Math.abs(difference) >= 360 - EPSILON) return 360;

    return ((difference % 360) + 360) % 360;
}

/** Return the equivalent longitude on the world copy nearest a reference. */
export function rebaseLongitude(longitude: number, reference: number): number {
    const normalized = normalizeLongitude(longitude);

    return normalized + 360 * Math.round((reference - normalized) / 360);
}

/**
 * Convert circular bounds into an ordinary increasing interval on the world
 * copy nearest the current map center. Leaflet can safely consume the result.
 */
export function unwrapLongitudeBounds(bounds: Pick<GeoBounds, 'west' | 'east'>, reference: number): { west: number; east: number } {
    const span = longitudeSpan(bounds.west, bounds.east);
    let west = rebaseLongitude(bounds.west, reference);
    let east = west + span;
    const intervalCenter = (west + east) / 2;
    const shift = 360 * Math.round((reference - intervalCenter) / 360);

    west += shift;
    east += shift;

    return { west, east };
}

/** Keep consecutive path vertices on the same nearby Leaflet world copy. */
export function unwrapPathLongitudes<T extends { latitude: number; longitude: number }>(points: T[], reference: number): T[] {
    let previousLongitude: number | null = null;

    return points.map((point) => {
        const longitude = rebaseLongitude(point.longitude, previousLongitude ?? reference);
        previousLongitude = longitude;

        return { ...point, longitude };
    });
}
