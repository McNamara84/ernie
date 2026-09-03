/**
 * Portal types for the public dataset discovery page.
 */

export type PortalKind = 'doi' | 'igsn';
export type PortalBasePath = '/doi-search' | '/igsn-search';

export interface PortalContext {
    kind: PortalKind;
    title: 'Data Portal' | 'IGSN Portal';
    basePath: PortalBasePath;
    showResourceTypeFilter: boolean;
}

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
    abstract: string | null;
    creators: PortalCreator[];
    year: number | null;
    resourceType: string;
    resourceTypeSlug: string | null;
    isIgsn: boolean;
    geoLocations: PortalGeoLocation[];
    landingPageUrl: string | null;
    citationAuthorDisplayLimit?: number;
}

/**
 * Pagination information.
 */
export interface PortalPagination {
    current_page: number;
    last_page: number | null;
    per_page: number;
    total: number | null;
    from: number;
    to: number;
    has_more: boolean;
    count_status: 'pending' | 'ready' | 'failed';
    filter_fingerprint: string;
}

/**
 * Active filter state.
 */
export interface PortalFilters {
    query: string | null;
    type: string[];
    exclude_type?: string | null;
    keywords: string[];
    freeKeywords?: string[];
    thesaurusKeywords?: string[];
    sampleTypes: string[];
    materials: string[];
    classifications: string[];
    geologicalAges: string[];
    geologicalUnits: string[];
    datacenter: string[];
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
 * Thesaurus tree data used by the portal filter sidebar.
 */
export interface PortalThesaurusFacet {
    scheme: string;
    roots: import('@/types/vocabulary').VocabularyKeyword[];
}

/**
 * Props for the portal page.
 */
export interface PortalPageProps {
    portal: PortalContext;
    resources: PortalResource[];
    pagination: PortalPagination;
    filters: PortalFilters;
    thesaurusFacets?: PortalThesaurusFacet[];
    igsnFacets?: PortalIgsnFacets | null;
    temporalRange: TemporalRange;
    resourceTypeFacets: ResourceTypeFacet[];
    datacenterFacets: DatacenterFacet[];
}

/** A counted exact-value option for an IGSN metadata facet. */
export interface PortalValueFacet {
    value: string;
    label: string;
    count: number;
}

/** A counted node in the controlled IGSN material hierarchy. */
export interface PortalTreeFacet extends PortalValueFacet {
    children: PortalTreeFacet[];
}

export type PortalClassificationFacetType = 'rock' | 'mineral' | 'biology' | 'unclassified';

export interface PortalClassificationFacetGroup {
    type: PortalClassificationFacetType;
    label: string;
    options: PortalValueFacet[];
}

export interface PortalIgsnFacets {
    sampleTypes: PortalValueFacet[];
    materials: PortalTreeFacet[];
    classifications: PortalClassificationFacetGroup[];
    geologicalAges: PortalValueFacet[];
    geologicalUnits: PortalValueFacet[];
}

/** Technical map viewport used by the asynchronous map endpoint. */
export interface PortalMapViewport extends GeoBounds {
    width: number;
    height: number;
    zoom: number;
}

export interface PortalMapResourceSummary {
    id: number;
    identifier: string | null;
    title: string;
    resourceType: { slug: string; name: string } | null;
    creators: PortalCreator[];
    landingPageUrl: string | null;
}

export type PortalMapGeometry =
    | { type: 'point'; latitude: number; longitude: number }
    | { type: 'box'; south: number; west: number; north: number; east: number }
    | { type: 'polygon' | 'line'; points: Array<{ latitude: number; longitude: number }> };

export interface PortalMapClusterFeature {
    kind: 'cluster';
    id: string;
    position: GeoPoint;
    bounds: GeoBounds;
    count: number;
    resourceTypeCounts: Record<string, number>;
}

export interface PortalMapResourceFeature {
    kind: 'resource';
    id: string;
    position: GeoPoint;
    bounds: GeoBounds;
    geometry: PortalMapGeometry;
    resource: PortalMapResourceSummary;
}

export type PortalMapFeature = PortalMapClusterFeature | PortalMapResourceFeature;

export interface PortalMapResponse {
    schemaVersion: 1;
    features: PortalMapFeature[];
    meta: {
        requestedZoom: number;
        effectiveZoom: number;
        visibleLocations: number;
        returnedFeatures: number;
        totalLocations: number | null;
        extent: GeoBounds | null;
        coarsened: boolean;
    };
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
 * Datacenter facet for filtering.
 */
export interface DatacenterFacet {
    name: string;
    count: number;
}

/**
 * Type filter: array of selected resource type slugs.
 * An empty array means no filter (all types shown).
 */
export type PortalTypeFilter = string[];
