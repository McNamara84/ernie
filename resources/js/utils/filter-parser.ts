import type { ResourceFilterState } from '@/types/resources';
import type { FilterState } from '@/types/old-datasets';

/**
 * Parse resource filters from URL search parameters.
 * Handles both array notation (resource_type[]) and single values.
 * 
 * @param search - URL search string (e.g., "?resource_type[]=dataset&year_from=2020")
 * @returns Parsed filter state object
 * 
 * @example
 * ```typescript
 * const filters = parseResourceFiltersFromUrl('?resource_type[]=dataset&status[]=published');
 * // Returns: { resource_type: ['dataset'], status: ['published'] }
 * ```
 */
export function parseResourceFiltersFromUrl(search: string): ResourceFilterState {
    const params = new URLSearchParams(search);
    const filters: ResourceFilterState = {};

    // Parse resource_type (array)
    const resourceTypes = params.getAll('resource_type[]');
    if (resourceTypes.length > 0) {
        filters.resource_type = resourceTypes;
    } else if (params.has('resource_type')) {
        const singleType = params.get('resource_type');
        if (singleType) {
            filters.resource_type = [singleType];
        }
    }

    // Parse status (array)
    const statuses = params.getAll('status[]');
    if (statuses.length > 0) {
        filters.status = statuses;
    } else if (params.has('status')) {
        const singleStatus = params.get('status');
        if (singleStatus) {
            filters.status = [singleStatus];
        }
    }

    // Parse curator (array)
    const curators = params.getAll('curator[]');
    if (curators.length > 0) {
        filters.curator = curators;
    } else if (params.has('curator')) {
        const singleCurator = params.get('curator');
        if (singleCurator) {
            filters.curator = [singleCurator];
        }
    }

    // Parse year_from (number)
    if (params.has('year_from')) {
        const yearFrom = params.get('year_from');
        if (yearFrom) {
            const parsed = parseInt(yearFrom, 10);
            if (!Number.isNaN(parsed) && parsed > 0) {
                filters.year_from = parsed;
            }
        }
    }

    // Parse year_to (number)
    if (params.has('year_to')) {
        const yearTo = params.get('year_to');
        if (yearTo) {
            const parsed = parseInt(yearTo, 10);
            if (!Number.isNaN(parsed) && parsed > 0) {
                filters.year_to = parsed;
            }
        }
    }

    // Parse search (string)
    if (params.has('search')) {
        const search = params.get('search');
        if (search && search.trim().length > 0) {
            filters.search = search.trim();
        }
    }

    // Parse created_from (date string)
    if (params.has('created_from')) {
        const createdFrom = params.get('created_from');
        if (createdFrom && createdFrom.trim().length > 0) {
            filters.created_from = createdFrom.trim();
        }
    }

    // Parse created_to (date string)
    if (params.has('created_to')) {
        const createdTo = params.get('created_to');
        if (createdTo && createdTo.trim().length > 0) {
            filters.created_to = createdTo.trim();
        }
    }

    // Parse updated_from (date string)
    if (params.has('updated_from')) {
        const updatedFrom = params.get('updated_from');
        if (updatedFrom && updatedFrom.trim().length > 0) {
            filters.updated_from = updatedFrom.trim();
        }
    }

    // Parse updated_to (date string)
    if (params.has('updated_to')) {
        const updatedTo = params.get('updated_to');
        if (updatedTo && updatedTo.trim().length > 0) {
            filters.updated_to = updatedTo.trim();
        }
    }

    return filters;
}

/**
 * Parse old dataset filters from URL search parameters.
 * Similar to parseResourceFiltersFromUrl but for old-datasets page.
 * 
 * @param search - URL search string
 * @returns Parsed filter state object
 * 
 * @example
 * ```typescript
 * const filters = parseOldDatasetFiltersFromUrl('?resource_type[]=dataset');
 * // Returns: { resource_type: ['dataset'] }
 * ```
 */
export function parseOldDatasetFiltersFromUrl(search: string): FilterState {
    const params = new URLSearchParams(search);
    const filters: FilterState = {};

    // Parse resource_type (array)
    const resourceTypes = params.getAll('resource_type[]');
    if (resourceTypes.length > 0) {
        filters.resource_type = resourceTypes;
    } else if (params.has('resource_type')) {
        const singleType = params.get('resource_type');
        if (singleType) {
            filters.resource_type = [singleType];
        }
    }

    // Parse status (array)
    const statuses = params.getAll('status[]');
    if (statuses.length > 0) {
        filters.status = statuses;
    } else if (params.has('status')) {
        const singleStatus = params.get('status');
        if (singleStatus) {
            filters.status = [singleStatus];
        }
    }

    // Parse curator (array)
    const curators = params.getAll('curator[]');
    if (curators.length > 0) {
        filters.curator = curators;
    } else if (params.has('curator')) {
        const singleCurator = params.get('curator');
        if (singleCurator) {
            filters.curator = [singleCurator];
        }
    }

    // Parse year_from (number)
    if (params.has('year_from')) {
        const yearFrom = params.get('year_from');
        if (yearFrom) {
            const parsed = parseInt(yearFrom, 10);
            if (!Number.isNaN(parsed) && parsed > 0) {
                filters.year_from = parsed;
            }
        }
    }

    // Parse year_to (number)
    if (params.has('year_to')) {
        const yearTo = params.get('year_to');
        if (yearTo) {
            const parsed = parseInt(yearTo, 10);
            if (!Number.isNaN(parsed) && parsed > 0) {
                filters.year_to = parsed;
            }
        }
    }

    // Parse search (string)
    if (params.has('search')) {
        const search = params.get('search');
        if (search && search.trim().length > 0) {
            filters.search = search.trim();
        }
    }

    // Parse created_from (date string)
    if (params.has('created_from')) {
        const createdFrom = params.get('created_from');
        if (createdFrom && createdFrom.trim().length > 0) {
            filters.created_from = createdFrom.trim();
        }
    }

    // Parse created_to (date string)
    if (params.has('created_to')) {
        const createdTo = params.get('created_to');
        if (createdTo && createdTo.trim().length > 0) {
            filters.created_to = createdTo.trim();
        }
    }

    // Parse updated_from (date string)
    if (params.has('updated_from')) {
        const updatedFrom = params.get('updated_from');
        if (updatedFrom && updatedFrom.trim().length > 0) {
            filters.updated_from = updatedFrom.trim();
        }
    }

    // Parse updated_to (date string)
    if (params.has('updated_to')) {
        const updatedTo = params.get('updated_to');
        if (updatedTo && updatedTo.trim().length > 0) {
            filters.updated_to = updatedTo.trim();
        }
    }

    return filters;
}
