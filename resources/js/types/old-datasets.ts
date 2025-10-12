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
