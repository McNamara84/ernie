// The Leaflet/MapLibre adapter renders at Leaflet zoom - 1 and therefore requires Leaflet zoom 1 or higher.
export const PORTAL_MAP_MIN_ZOOM = 1;

export function normalizePortalMapMaxZoom(maxZoom: number): number {
    return Math.max(PORTAL_MAP_MIN_ZOOM, Math.floor(maxZoom));
}

export function clampPortalMapZoom(zoom: number, maxZoom: number): number {
    return Math.max(PORTAL_MAP_MIN_ZOOM, Math.min(normalizePortalMapMaxZoom(maxZoom), Math.round(zoom)));
}
