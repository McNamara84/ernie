import type { PortalFilters, PortalMapViewport } from '@/types/portal';

function appendArrayParams(params: URLSearchParams, key: string, values: string[]): void {
    values.forEach((value) => {
        params.append(`${key}[]`, value);
    });
}

export function mergePortalFilters(filters: PortalFilters, nextFilters: Partial<PortalFilters>): PortalFilters {
    const freeKeywords = nextFilters.freeKeywords !== undefined ? nextFilters.freeKeywords : (filters.freeKeywords ?? []);
    const thesaurusKeywords = nextFilters.thesaurusKeywords !== undefined ? nextFilters.thesaurusKeywords : (filters.thesaurusKeywords ?? []);
    const keywords =
        nextFilters.keywords !== undefined ? nextFilters.keywords : freeKeywords.length > 0 || thesaurusKeywords.length > 0 ? [] : filters.keywords;

    return {
        ...filters,
        ...nextFilters,
        exclude_type: nextFilters.type !== undefined ? null : filters.exclude_type,
        keywords,
        freeKeywords,
        thesaurusKeywords,
    };
}

export function buildPortalFilterUrl(filters: PortalFilters, page: number | null = null): string {
    const params = new URLSearchParams();
    const hasSplitKeywordFilters = (filters.freeKeywords?.length ?? 0) > 0 || (filters.thesaurusKeywords?.length ?? 0) > 0;

    if (filters.query && filters.query.trim() !== '') {
        params.set('q', filters.query.trim());
    }

    if (filters.type.length > 0) {
        appendArrayParams(params, 'type', filters.type);
    } else if (filters.exclude_type) {
        params.set('type', 'doi');
    }

    if (filters.datacenter.length > 0) {
        appendArrayParams(params, 'datacenter', filters.datacenter);
    }

    if (!hasSplitKeywordFilters && filters.keywords.length > 0) {
        appendArrayParams(params, 'keywords', filters.keywords);
    }

    if ((filters.freeKeywords?.length ?? 0) > 0) {
        appendArrayParams(params, 'free_keywords', filters.freeKeywords ?? []);
    }

    if ((filters.thesaurusKeywords?.length ?? 0) > 0) {
        appendArrayParams(params, 'thesaurus_keywords', filters.thesaurusKeywords ?? []);
    }

    if (filters.bounds) {
        params.set('north', filters.bounds.north.toFixed(6));
        params.set('south', filters.bounds.south.toFixed(6));
        params.set('east', filters.bounds.east.toFixed(6));
        params.set('west', filters.bounds.west.toFixed(6));
    }

    if (filters.temporal) {
        params.set('date_type', filters.temporal.dateType);
        params.set('year_from', String(filters.temporal.yearFrom));
        params.set('year_to', String(filters.temporal.yearTo));
    }

    if (page !== null && page > 1) {
        params.set('page', String(page));
    }

    const queryString = params.toString();

    return queryString ? `/search?${queryString}` : '/search';
}

/** Preserve the server's exact filter inputs so its count fingerprint stays identical. */
export function buildPortalCountUrl(currentSearch: string): string {
    const params = new URLSearchParams(currentSearch);
    params.delete('page');

    const queryString = params.toString();

    return queryString ? `/search/count?${queryString}` : '/search/count';
}

/** Build the lightweight map request while preserving the list's active filters. */
export function buildPortalMapUrl(filters: PortalFilters, viewport: PortalMapViewport, includeExtent = false): string {
    const filterUrl = buildPortalFilterUrl(filters);
    const queryString = filterUrl.includes('?') ? filterUrl.slice(filterUrl.indexOf('?') + 1) : '';
    const params = new URLSearchParams(queryString);

    params.set('viewport[north]', viewport.north.toFixed(6));
    params.set('viewport[south]', viewport.south.toFixed(6));
    params.set('viewport[east]', viewport.east.toFixed(6));
    params.set('viewport[west]', viewport.west.toFixed(6));
    params.set('viewport[width]', String(Math.max(1, Math.round(viewport.width))));
    params.set('viewport[height]', String(Math.max(1, Math.round(viewport.height))));
    params.set('zoom', String(Math.max(0, Math.min(18, Math.round(viewport.zoom)))));

    if (includeExtent) {
        params.set('include_extent', '1');
    }

    return `/search/map?${params.toString()}`;
}
