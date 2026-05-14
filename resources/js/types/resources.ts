/**
 * Shared type definitions for the resources feature.
 * These types are used across components and tests to ensure consistency.
 */

export type ResourceSortKey =
    | 'id'
    | 'doi'
    | 'title'
    | 'resourcetypegeneral'
    | 'first_author'
    | 'year'
    | 'curator'
    | 'publicstatus'
    | 'created_at'
    | 'updated_at';

export type ResourceSortDirection = 'asc' | 'desc';

export interface ResourceSortState {
    key: ResourceSortKey;
    direction: ResourceSortDirection;
}

/**
 * Filter state for resources
 */
export interface ResourceFilterState {
    search?: string;
    resource_type?: string[];
    year_from?: number;
    year_to?: number;
    curator?: string[];
    status?: string[];
    created_from?: string;
    created_to?: string;
    updated_from?: string;
    updated_to?: string;
}

/**
 * Available filter options from the backend
 */
export interface ResourceFilterOptions {
    resource_types: Array<{ name: string; slug: string }>;
    curators: string[];
    year_range: {
        min: number;
        max: number;
    };
    statuses: string[];
}

export interface ResourceListAuthor {
    givenName?: string | null;
    familyName?: string | null;
    name?: string;
}

export interface ResourceListLandingPage {
    id: number;
    is_published: boolean;
    public_url: string;
}

export interface ResourceListItem {
    id: number;
    doi?: string | null;
    year: number;
    version?: string | null;
    created_at?: string;
    updated_at?: string;
    curator?: string;
    publicstatus?: string;
    resourcetypegeneral?: string;
    title?: string;
    first_author?: ResourceListAuthor | null;
    landingPage?: ResourceListLandingPage | null;
    [key: string]: unknown;
}

export interface ResourceDoiActionItem extends Pick<ResourceListItem, 'id' | 'doi' | 'publicstatus' | 'title' | 'landingPage'> {
    [key: string]: unknown;
}

export function shouldUseUpdateMetadataLabel(resource: Pick<ResourceListItem, 'doi' | 'publicstatus' | 'landingPage'>): boolean {
    const hasExistingDoi = Boolean(resource.doi);

    return hasExistingDoi && (resource.publicstatus === 'published' || resource.landingPage?.is_published === true);
}
