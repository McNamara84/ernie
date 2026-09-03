import { describe, expect, it } from 'vitest';

import { buildPortalCountUrl, buildPortalFilterUrl, buildPortalMapUrl, mergePortalFilters } from '@/lib/portal-filter-url';
import type { PortalFilters } from '@/types/portal';

const filters: PortalFilters = {
    query: '  seismic data  ',
    type: ['dataset', 'physical-object'],
    keywords: [],
    freeKeywords: ['gravity'],
    thesaurusKeywords: ['https://example.test/tectonics'],
    sampleTypes: ['Core', 'Core Sample'],
    materials: ['Liquid'],
    classifications: ['Igneous', 'Metamorphic'],
    geologicalAges: ['Jurassic'],
    geologicalUnits: ['Upper Rhine Graben'],
    datacenter: ['GFZ Data Services'],
    bounds: { north: 54, south: 50, east: 15, west: 11 },
    temporal: { dateType: 'Created', yearFrom: 2020, yearTo: 2025 },
};

describe('portal filter URL builders', () => {
    it('serializes all list filters without empty defaults', () => {
        const url = new URL(buildPortalFilterUrl(filters), 'https://ernie.test');

        expect(url.pathname).toBe('/doi-search');
        expect(url.searchParams.get('q')).toBe('seismic data');
        expect(url.searchParams.getAll('type[]')).toEqual(['dataset', 'physical-object']);
        expect(url.searchParams.getAll('free_keywords[]')).toEqual(['gravity']);
        expect(url.searchParams.has('sample_types[]')).toBe(false);
        expect(url.searchParams.has('materials[]')).toBe(false);
        expect(url.searchParams.has('classifications[]')).toBe(false);
        expect(url.searchParams.has('geological_ages[]')).toBe(false);
        expect(url.searchParams.has('geological_units[]')).toBe(false);
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

        expect(url.pathname).toBe('/doi-search/map');
        expect(url.searchParams.get('q')).toBe('seismic data');
        expect(url.searchParams.get('north')).toBe('54.000000');
        expect(url.searchParams.get('viewport[north]')).toBe('53.123457');
        expect(url.searchParams.get('viewport[width]')).toBe('1000');
        expect(url.searchParams.get('viewport[height]')).toBe('699');
        expect(url.searchParams.get('zoom')).toBe('12');
        expect(url.searchParams.get('include_extent')).toBe('1');
    });

    it('preserves exact direct-URL filters for counts while dropping pagination', () => {
        const url = new URL(buildPortalCountUrl('?q=%20climate%20&north=53.123456789&type%5B%5D=dataset&page=4'), 'https://ernie.test');

        expect(url.pathname).toBe('/doi-search/count');
        expect(url.searchParams.get('q')).toBe(' climate ');
        expect(url.searchParams.get('north')).toBe('53.123456789');
        expect(url.searchParams.getAll('type[]')).toEqual(['dataset']);
        expect(url.searchParams.has('page')).toBe(false);
    });

    it('uses the IGSN route family without serializing a resource type filter', () => {
        const listUrl = new URL(buildPortalFilterUrl(filters, '/igsn-search'), 'https://ernie.test');
        const countUrl = new URL(
            buildPortalCountUrl('?q=sample&type%5B%5D=dataset&thesaurus_keywords%5B%5D=legacy&sample_types%5B%5D=Core&page=2', '/igsn-search'),
            'https://ernie.test',
        );
        const mapUrl = new URL(
            buildPortalMapUrl(filters, { north: 54, south: 50, east: 15, west: 11, width: 800, height: 600, zoom: 8 }, false, '/igsn-search'),
            'https://ernie.test',
        );

        expect(listUrl.pathname).toBe('/igsn-search');
        expect(listUrl.searchParams.has('type[]')).toBe(false);
        expect(listUrl.searchParams.has('thesaurus_keywords[]')).toBe(false);
        expect(listUrl.searchParams.getAll('sample_types[]')).toEqual(['Core', 'Core Sample']);
        expect(listUrl.searchParams.getAll('materials[]')).toEqual(['Liquid']);
        expect(listUrl.searchParams.getAll('classifications[]')).toEqual(['Igneous', 'Metamorphic']);
        expect(listUrl.searchParams.getAll('geological_ages[]')).toEqual(['Jurassic']);
        expect(listUrl.searchParams.getAll('geological_units[]')).toEqual(['Upper Rhine Graben']);
        expect(countUrl.pathname).toBe('/igsn-search/count');
        expect(countUrl.searchParams.has('type[]')).toBe(false);
        expect(countUrl.searchParams.has('thesaurus_keywords[]')).toBe(false);
        expect(countUrl.searchParams.getAll('sample_types[]')).toEqual(['Core']);
        expect(mapUrl.pathname).toBe('/igsn-search/map');
        expect(mapUrl.searchParams.has('type[]')).toBe(false);
        expect(mapUrl.searchParams.getAll('sample_types[]')).toEqual(['Core', 'Core Sample']);
        expect(mapUrl.searchParams.getAll('materials[]')).toEqual(['Liquid']);
        expect(mapUrl.searchParams.getAll('classifications[]')).toEqual(['Igneous', 'Metamorphic']);
        expect(mapUrl.searchParams.getAll('geological_ages[]')).toEqual(['Jurassic']);
        expect(mapUrl.searchParams.getAll('geological_units[]')).toEqual(['Upper Rhine Graben']);
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
