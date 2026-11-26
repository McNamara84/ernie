import type { GeoBounds } from '@/types/portal';

export const PORTAL_WORLD_BOUNDS: GeoBounds = { north: 90, south: -90, east: 180, west: -180 };

export function isPortalWorld(bounds: GeoBounds): boolean {
    return bounds.north === 90 && bounds.south === -90 && bounds.east === 180 && bounds.west === -180;
}

/** Search URLs round Leaflet coordinates to six decimal places. */
export function portalBoundsKey(bounds: GeoBounds): string {
    return [bounds.north, bounds.south, bounds.east, bounds.west].map((value) => value.toFixed(6)).join(',');
}
