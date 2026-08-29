import { describe, expect, it } from 'vitest';

import { buildPortalFilterUrl, buildPortalMapUrl, mergePortalFilters } from '@/lib/portal-filter-url';
import type { PortalFilters } from '@/types/portal';

const filters: PortalFilters = {
    query: '  seismic data  ',
    type: ['dataset', 'physical-object'],
    keywords: [],
    freeKeywords: ['gravity'],
    thesaurusKeywords: ['https://example.test/tectonics'],
    datacenter: ['GFZ Data Services'],
    bounds: { north: 54, south: 50, east: 15, west: 11 },
    temporal: { dateType: 'Created', yearFrom: 2020, yearTo: 2025 },
};

describe('portal filter URL builders', () => {
    it('serializes all list filters without empty defaults', () => {
        const url = new URL(buildPortalFilterUrl(filters), 'https://ernie.test');

        expect(url.pathname).toBe('/portal');
        expect(url.searchParams.get('q')).toBe('seismic data');
        expect(url.searchParams.getAll('type[]')).toEqual(['dataset', 'physical-object']);
        expect(url.searchParams.getAll('free_keywords[]')).toEqual(['gravity']);
        expect(url.searchParams.get('north')).toBe('54.000000');
        expect(url.searchParams.get('date_type')).toBe('Created');
    });

    it('adds the technical viewport and zoom to the same filter contract', () => {
        const url = new URL(
            buildPortalMapUrl(
                filters,
                {
                    north: 53.1234567,
                    south: 51.2,
                    east: 14.8,
                    west: 12.1,
                    width: 999.7,
                    height: 699.2,
                    zoom: 11.6,
                },
                true,
            ),
            'https://ernie.test',
        );

        expect(url.pathname).toBe('/portal/map');
        expect(url.searchParams.get('q')).toBe('seismic data');
        expect(url.searchParams.get('north')).toBe('54.000000');
        expect(url.searchParams.get('viewport[north]')).toBe('53.123457');
        expect(url.searchParams.get('viewport[width]')).toBe('1000');
        expect(url.searchParams.get('viewport[height]')).toBe('699');
        expect(url.searchParams.get('zoom')).toBe('12');
        expect(url.searchParams.get('include_extent')).toBe('1');
    });

    it('preserves the legacy DOI exclusion filter for map requests', () => {
        const legacyFilters = { ...filters, type: [], exclude_type: 'physical-object' };
        const url = new URL(
            buildPortalMapUrl(legacyFilters, {
                north: 90,
                south: -90,
                east: 180,
                west: -180,
                width: 800,
                height: 600,
                zoom: 2,
            }),
            'https://ernie.test',
        );

        expect(url.searchParams.get('type')).toBe('doi');
        expect(url.searchParams.has('include_extent')).toBe(false);
    });

    it('clears the legacy exclusion when a new explicit type selection is merged', () => {
        expect(mergePortalFilters({ ...filters, type: [], exclude_type: 'physical-object' }, { type: ['dataset'] }).exclude_type).toBeNull();

        expect(mergePortalFilters({ ...filters, type: ['dataset'], exclude_type: 'physical-object' }, { type: [] }).exclude_type).toBeNull();
    });
});
