import type { PortalFilters } from '@/types/portal';

function appendArrayParams(params: URLSearchParams, key: string, values: string[]): void {
    values.forEach((value) => {
        params.append(`${key}[]`, value);
    });
}

export function mergePortalFilters(filters: PortalFilters, nextFilters: Partial<PortalFilters>): PortalFilters {
    const freeKeywords = nextFilters.freeKeywords !== undefined ? nextFilters.freeKeywords : (filters.freeKeywords ?? []);
    const thesaurusKeywords =
        nextFilters.thesaurusKeywords !== undefined ? nextFilters.thesaurusKeywords : (filters.thesaurusKeywords ?? []);
    const keywords =
        nextFilters.keywords !== undefined
            ? nextFilters.keywords
            : freeKeywords.length > 0 || thesaurusKeywords.length > 0
              ? []
              : filters.keywords;

    return {
        ...filters,
        ...nextFilters,
        exclude_type: nextFilters.type !== undefined && nextFilters.type.length === 0 ? null : filters.exclude_type,
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

    return queryString ? `/portal?${queryString}` : '/portal';
}