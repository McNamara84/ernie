/**
 * Mock Data Factories
 *
 * Domain-specific factory functions for creating test data.
 * Extends the existing types.ts factories with Resource, IGSN, Portal,
 * and pagination mock data.
 *
 * @example
 * import { createMockResource, createMockIgsn, createMockPaginatedResponse } from '@test-helpers/factories';
 *
 * const resource = createMockResource({ doi: '10.5880/test.2026.001' });
 * const igsns = [createMockIgsn(), createMockIgsn({ igsn: 'IGSN0002' })];
 * const paginated = createMockPaginatedResponse(igsns);
 */

import type { PortalFilters, PortalPagination, PortalResource } from '@/types/portal';
import type { ResourceFilterOptions, ResourceFilterState, ResourceSortState } from '@/types/resources';

// ============================================================================
// Pagination
// ============================================================================

export interface PaginationMeta {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
    has_more?: boolean;
}

const DEFAULT_PAGINATION: PaginationMeta = {
    current_page: 1,
    last_page: 1,
    per_page: 15,
    total: 0,
    from: null,
    to: null,
    has_more: false,
};

/**
 * Creates a mock pagination meta object.
 */
export function createMockPagination(overrides?: Partial<PaginationMeta>): PaginationMeta {
    return { ...DEFAULT_PAGINATION, ...overrides };
}

/**
 * Creates a mock paginated response with automatic pagination calculation.
 */
export function createMockPaginatedResponse<T>(
    data: T[],
    meta?: Partial<PaginationMeta>,
): { data: T[]; pagination: PaginationMeta } {
    const perPage = meta?.per_page ?? 15;
    const total = meta?.total ?? data.length;
    const lastPage = Math.max(1, Math.ceil(total / perPage));
    const currentPage = meta?.current_page ?? 1;

    return {
        data,
        pagination: {
            current_page: currentPage,
            last_page: lastPage,
            per_page: perPage,
            total,
            from: data.length > 0 ? (currentPage - 1) * perPage + 1 : null,
            to: data.length > 0 ? Math.min(currentPage * perPage, total) : null,
            has_more: currentPage < lastPage,
            ...meta,
        },
    };
}

// ============================================================================
// Sort State
// ============================================================================

export interface SortState<K extends string = string> {
    key: K;
    direction: 'asc' | 'desc';
}

/**
 * Creates a mock sort state.
 */
export function createMockSortState<K extends string>(
    key: K,
    direction: 'asc' | 'desc' = 'asc',
): SortState<K> {
    return { key, direction };
}

// ============================================================================
// Resource (DataCite)
// ============================================================================

export interface MockResource {
    id: number;
    doi: string | null;
    title: string;
    resourcetypegeneral: string;
    first_author: string | null;
    year: number | null;
    curator: string | null;
    publicstatus: string;
    created_at: string;
    updated_at: string;
    has_landing_page: boolean;
}

let resourceIdCounter = 1;

/**
 * Creates a mock Resource object.
 */
export function createMockResource(overrides?: Partial<MockResource>): MockResource {
    const id = overrides?.id ?? resourceIdCounter++;
    return {
        id,
        doi: `10.5880/test.2026.${String(id).padStart(3, '0')}`,
        title: `Test Resource ${id}`,
        resourcetypegeneral: 'Dataset',
        first_author: 'Doe, John',
        year: 2026,
        curator: 'Test Curator',
        publicstatus: 'draft',
        created_at: '2026-01-01T00:00:00.000Z',
        updated_at: '2026-01-01T00:00:00.000Z',
        has_landing_page: false,
        ...overrides,
    };
}

/**
 * Creates multiple mock Resources.
 */
export function createMockResources(count: number, overrides?: Partial<MockResource>): MockResource[] {
    return Array.from({ length: count }, () => createMockResource(overrides));
}

/**
 * Creates mock resource filter options.
 */
export function createMockResourceFilterOptions(overrides?: Partial<ResourceFilterOptions>): ResourceFilterOptions {
    return {
        resource_types: [
            { name: 'Dataset', slug: 'dataset' },
            { name: 'Software', slug: 'software' },
            { name: 'Text', slug: 'text' },
        ],
        curators: ['Admin User', 'Test Curator'],
        year_range: { min: 2020, max: 2026 },
        statuses: ['draft', 'registered', 'findable'],
        ...overrides,
    };
}

/**
 * Creates mock resource filter state.
 */
export function createMockResourceFilterState(overrides?: Partial<ResourceFilterState>): ResourceFilterState {
    return {
        search: '',
        resource_type: [],
        curator: [],
        status: [],
        ...overrides,
    };
}

/**
 * Creates mock resource sort state.
 */
export function createMockResourceSortState(overrides?: Partial<ResourceSortState>): ResourceSortState {
    return {
        key: 'updated_at',
        direction: 'desc',
        ...overrides,
    };
}

// ============================================================================
// IGSN (Physical Samples)
// ============================================================================

export interface MockIgsn {
    id: number;
    igsn: string | null;
    title: string;
    sample_type: string | null;
    material: string | null;
    collection_date: string | null;
    latitude: number | null;
    longitude: number | null;
    upload_status: string;
    upload_error_message: string | null;
    parent_resource_id: number | null;
    collector: string | null;
    created_at: string | null;
    updated_at: string | null;
}

let igsnIdCounter = 1;

/**
 * Creates a mock IGSN object.
 */
export function createMockIgsn(overrides?: Partial<MockIgsn>): MockIgsn {
    const id = overrides?.id ?? igsnIdCounter++;
    return {
        id,
        igsn: `IGSN${String(id).padStart(4, '0')}`,
        title: `Test Sample ${id}`,
        sample_type: 'Rock',
        material: 'Granite',
        collection_date: '2026-01-15',
        latitude: 52.3829,
        longitude: 13.0644,
        upload_status: 'pending',
        upload_error_message: null,
        parent_resource_id: null,
        collector: 'Doe, John',
        created_at: '2026-01-01T00:00:00.000Z',
        updated_at: '2026-01-01T00:00:00.000Z',
        ...overrides,
    };
}

/**
 * Creates multiple mock IGSNs.
 */
export function createMockIgsns(count: number, overrides?: Partial<MockIgsn>): MockIgsn[] {
    return Array.from({ length: count }, () => createMockIgsn(overrides));
}

/**
 * Creates a mock IGSN with error state.
 */
export function createMockIgsnWithError(errorMessage = 'Validation failed'): MockIgsn {
    return createMockIgsn({
        upload_status: 'error',
        upload_error_message: errorMessage,
    });
}

// ============================================================================
// Portal
// ============================================================================

let portalResourceIdCounter = 1;

/**
 * Creates a mock PortalResource.
 */
export function createMockPortalResource(overrides?: Partial<PortalResource>): PortalResource {
    const id = overrides?.id ?? portalResourceIdCounter++;
    return {
        id,
        doi: `10.5880/portal.2026.${String(id).padStart(3, '0')}`,
        title: `Portal Resource ${id}`,
        creators: [{ name: 'Doe, John', givenName: 'John' }],
        year: 2026,
        resourceType: 'Dataset',
        resourceTypeSlug: 'dataset',
        isIgsn: false,
        geoLocations: [],
        landingPageUrl: `/landing/${id}`,
        ...overrides,
    };
}

/**
 * Creates a mock PortalResource with geo location.
 */
export function createMockPortalResourceWithLocation(
    lat = 52.3829,
    lng = 13.0644,
    overrides?: Partial<PortalResource>,
): PortalResource {
    return createMockPortalResource({
        geoLocations: [
            {
                id: 1,
                type: 'point',
                point: { lat, lng },
                bounds: null,
                polygon: null,
            },
        ],
        ...overrides,
    });
}

/**
 * Creates mock PortalPagination.
 */
export function createMockPortalPagination(overrides?: Partial<PortalPagination>): PortalPagination {
    return {
        current_page: 1,
        last_page: 1,
        per_page: 15,
        total: 0,
        from: 0,
        to: 0,
        ...overrides,
    };
}

/**
 * Creates mock PortalFilters.
 */
export function createMockPortalFilters(overrides?: Partial<PortalFilters>): PortalFilters {
    return {
        query: null,
        type: 'all',
        keywords: [],
        bounds: null,
        temporal: null,
        ...overrides,
    };
}

/**
 * Creates a complete mock PortalPageProps object.
 */
export function createMockPortalPageProps(overrides?: {
    resources?: PortalResource[];
    mapData?: PortalResource[];
    pagination?: Partial<PortalPagination>;
    filters?: Partial<PortalFilters>;
}) {
    const resources = overrides?.resources ?? [];
    return {
        resources,
        mapData: overrides?.mapData ?? resources,
        pagination: createMockPortalPagination({
            total: resources.length,
            ...overrides?.pagination,
        }),
        filters: createMockPortalFilters(overrides?.filters),
        keywordSuggestions: [],
        temporalRange: {},
    };
}

// ============================================================================
// Counter Reset (for test isolation)
// ============================================================================

/**
 * Resets all factory ID counters. Call in `beforeEach` if tests depend on specific IDs.
 */
export function resetFactoryCounters() {
    resourceIdCounter = 1;
    igsnIdCounter = 1;
    portalResourceIdCounter = 1;
}
