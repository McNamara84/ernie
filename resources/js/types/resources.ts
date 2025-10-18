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
    language?: string[];
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
    languages: Array<{ code: string; name: string }>;
    curators: string[];
    year_range: {
        min: number;
        max: number;
    };
    statuses: string[];
}
