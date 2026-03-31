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
    type: 'point' | 'box' | 'polygon' | 'line' | 'unknown';
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
    type: string[];
    exclude_type?: string | null;
    keywords: string[];
    bounds: GeoBounds | null;
    temporal: TemporalFilterValue | null;
}

/**
 * Temporal date types available for filtering.
 */
export type TemporalDateType = 'Created' | 'Collected' | 'Coverage';

/**
 * Year range for a single date type.
 */
export interface TemporalYearRange {
    min: number;
    max: number;
}

/**
 * Available temporal ranges from backend (keyed by date type slug).
 */
export type TemporalRange = Partial<Record<TemporalDateType, TemporalYearRange>>;

/**
 * Active temporal filter value.
 */
export interface TemporalFilterValue {
    dateType: TemporalDateType;
    yearFrom: number;
    yearTo: number;
}

/**
 * Keyword suggestion for autocomplete.
 */
export interface KeywordSuggestion {
    value: string;
    scheme: string | null;
    count: number;
}

/**
 * Props for the portal page.
 */
export interface PortalPageProps {
    resources: PortalResource[];
    mapData: PortalResource[];
    pagination: PortalPagination;
    filters: PortalFilters;
    keywordSuggestions: KeywordSuggestion[];
    temporalRange: TemporalRange;
    resourceTypeFacets: ResourceTypeFacet[];
}

/**
 * Resource type facet for filtering.
 */
export interface ResourceTypeFacet {
    slug: string;
    name: string;
    count: number;
}

/**
 * Type filter: array of selected resource type slugs.
 * An empty array means no filter (all types shown).
 */
export type PortalTypeFilter = string[];
