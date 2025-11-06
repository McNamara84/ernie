import type { FilterState } from '@/types/old-datasets';
import type { ResourceFilterState } from '@/types/resources';

/**
 * Internal helper to parse array filter parameters.
 * Handles both array notation (param[]) and indexed arrays (param[0], param[1], ...).
 */
function parseArrayParam(params: URLSearchParams, paramName: string): string[] | undefined {
    // First try the standard PHP array notation with empty brackets: param[]
    const arrayValues = params.getAll(`${paramName}[]`);
    if (arrayValues.length > 0) {
        return arrayValues;
    }

    // Then try indexed array notation: param[0], param[1], etc.
    // Collect all parameters that match the pattern param[number]
    const indexedValues: string[] = [];
    const indexedPattern = new RegExp(`^${paramName}\\[(\\d+)\\]$`);
    for (const [key, value] of params.entries()) {
        // Match patterns like "resource_type[0]", "resource_type[1]", etc.
        const match = key.match(indexedPattern);
        if (match) {
            const index = parseInt(match[1], 10);
            indexedValues[index] = value;
        }
    }
    
    // Filter out undefined values (in case of sparse arrays) and return
    const filteredValues = indexedValues.filter(v => v !== undefined);
    if (filteredValues.length > 0) {
        return filteredValues;
    }

    // Finally, try single value without array notation
    const singleValue = params.get(paramName);
    if (singleValue) {
        return [singleValue];
    }

    return undefined;
}

/**
 * Internal helper to parse positive integer parameters.
 */
function parsePositiveInt(params: URLSearchParams, paramName: string): number | undefined {
    const value = params.get(paramName);
    if (!value) {
        return undefined;
    }

    const parsed = parseInt(value, 10);
    if (!Number.isNaN(parsed) && parsed > 0) {
        return parsed;
    }

    return undefined;
}

/**
 * Internal helper to parse trimmed string parameters.
 */
function parseTrimmedString(params: URLSearchParams, paramName: string): string | undefined {
    const value = params.get(paramName);
    if (!value) {
        return undefined;
    }

    const trimmed = value.trim();
    return trimmed.length > 0 ? trimmed : undefined;
}

/**
 * Generic filter parser that works for both ResourceFilterState and FilterState.
 * These types have identical structure, so we can use a single implementation.
 */
function parseFiltersFromUrl<T extends ResourceFilterState | FilterState>(searchParams: string): T {
    const params = new URLSearchParams(searchParams);
    const filters: Partial<T> = {};

    // Parse array filters
    const resourceType = parseArrayParam(params, 'resource_type');
    if (resourceType) {
        (filters as ResourceFilterState).resource_type = resourceType;
    }

    const status = parseArrayParam(params, 'status');
    if (status) {
        (filters as ResourceFilterState).status = status;
    }

    const curator = parseArrayParam(params, 'curator');
    if (curator) {
        (filters as ResourceFilterState).curator = curator;
    }

    // Parse numeric filters
    const yearFrom = parsePositiveInt(params, 'year_from');
    if (yearFrom !== undefined) {
        (filters as ResourceFilterState).year_from = yearFrom;
    }

    const yearTo = parsePositiveInt(params, 'year_to');
    if (yearTo !== undefined) {
        (filters as ResourceFilterState).year_to = yearTo;
    }

    // Parse string filters
    const searchTerm = parseTrimmedString(params, 'search');
    if (searchTerm !== undefined) {
        (filters as ResourceFilterState).search = searchTerm;
    }

    // Parse date filters
    const createdFrom = parseTrimmedString(params, 'created_from');
    if (createdFrom !== undefined) {
        (filters as ResourceFilterState).created_from = createdFrom;
    }

    const createdTo = parseTrimmedString(params, 'created_to');
    if (createdTo !== undefined) {
        (filters as ResourceFilterState).created_to = createdTo;
    }

    const updatedFrom = parseTrimmedString(params, 'updated_from');
    if (updatedFrom !== undefined) {
        (filters as ResourceFilterState).updated_from = updatedFrom;
    }

    const updatedTo = parseTrimmedString(params, 'updated_to');
    if (updatedTo !== undefined) {
        (filters as ResourceFilterState).updated_to = updatedTo;
    }

    return filters as T;
}

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
    return parseFiltersFromUrl<ResourceFilterState>(search);
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
    return parseFiltersFromUrl<FilterState>(search);
}
