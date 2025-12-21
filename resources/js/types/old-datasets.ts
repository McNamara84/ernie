/**
 * Shared type definitions for the old datasets feature.
 * These types are used across components and tests to ensure consistency.
 */

export type SortKey =
    | 'id'
    | 'identifier'
    | 'title'
    | 'resourcetypegeneral'
    | 'first_author'
    | 'publicationyear'
    | 'curator'
    | 'publicstatus'
    | 'created_at'
    | 'updated_at';

export type SortDirection = 'asc' | 'desc';

export interface SortState {
    key: SortKey;
    direction: SortDirection;
}

/**
 * Filter state for old datasets
 */
export interface FilterState {
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
export interface FilterOptions {
    resource_types: string[];
    curators: string[];
    year_range: {
        min: number;
        max: number;
    };
    statuses: string[];
}
