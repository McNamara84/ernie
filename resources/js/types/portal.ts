/**
 * Portal types for the public dataset discovery page.
 */

/**
 * Creator information in citation format.
 */
export interface PortalCreator {
    name: string;
    givenName?: string | null;
}

/**
 * Geographic point coordinates.
 */
export interface GeoPoint {
    lat: number;
    lng: number;
}

/**
 * Geographic bounding box.
 */
export interface GeoBounds {
    north: number;
    south: number;
    east: number;
    west: number;
}

/**
 * Geographic location data for map display.
 */
export interface PortalGeoLocation {
    id: number;
    type: 'point' | 'box' | 'polygon' | 'unknown';
    point: GeoPoint | null;
    bounds: GeoBounds | null;
    polygon: GeoPoint[] | null;
}

/**
 * Resource data for portal display.
 */
export interface PortalResource {
    id: number;
    doi: string | null;
    title: string;
    creators: PortalCreator[];
    year: number | null;
    resourceType: string;
    resourceTypeSlug: string | null;
    isIgsn: boolean;
    geoLocations: PortalGeoLocation[];
    landingPageUrl: string | null;
}

/**
 * Pagination information.
 */
export interface PortalPagination {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number;
    to: number;
}

/**
 * Active filter state.
 */
export interface PortalFilters {
    query: string | null;
    type: 'all' | 'doi' | 'igsn';
}

/**
 * Props for the portal page.
 */
export interface PortalPageProps {
    resources: PortalResource[];
    mapData: PortalResource[];
    pagination: PortalPagination;
    filters: PortalFilters;
}

/**
 * Type filter option.
 */
export type PortalTypeFilter = 'all' | 'doi' | 'igsn';
