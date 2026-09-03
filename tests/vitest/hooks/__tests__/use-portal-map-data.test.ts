import { waitFor } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { usePortalMapData } from '@/hooks/use-portal-map-data';
import type { PortalFilters, PortalMapResponse, PortalMapViewport } from '@/types/portal';

import { http, HttpResponse, server } from '../../helpers/msw-server';
import { renderHookWithQueryClient } from '../../helpers/render-with-query-client';

const filters: PortalFilters = {
    query: 'volcano',
    type: [],
    keywords: [],
    freeKeywords: [],
    thesaurusKeywords: [],
    sampleTypes: [],
    materials: [],
    classifications: [],
    geologicalAges: [],
    geologicalUnits: [],
    datacenter: [],
    bounds: null,
    temporal: null,
};

const viewport: PortalMapViewport = {
    north: 54,
    south: 50,
    east: 15,
    west: 11,
    width: 1000,
    height: 700,
    zoom: 8,
};

const payload: PortalMapResponse = {
    schemaVersion: 1,
    features: [],
    meta: {
        requestedZoom: 8,
        effectiveZoom: 8,
        visibleLocations: 0,
        returnedFeatures: 0,
        totalLocations: null,
        extent: null,
        coarsened: false,
    },
};

describe('usePortalMapData', () => {
    it('waits until a visible Leaflet viewport is available', () => {
        let requests = 0;
        server.use(
            http.get('/doi-search/map', () => {
                requests++;
                return HttpResponse.json(payload);
            }),
        );

        const { result } = renderHookWithQueryClient(() => usePortalMapData(filters, null, false));

        expect(result.current.fetchStatus).toBe('idle');
        expect(requests).toBe(0);
    });

    it('loads the bounded endpoint with filters and extent flag', async () => {
        let requestUrl = '';
        server.use(
            http.get('/doi-search/map', ({ request }) => {
                requestUrl = request.url;
                return HttpResponse.json({ ...payload, meta: { ...payload.meta, totalLocations: 35_638 } });
            }),
        );

        const { result } = renderHookWithQueryClient(() => usePortalMapData(filters, viewport, true));
        await waitFor(() => expect(result.current.isSuccess).toBe(true));

        const url = new URL(requestUrl);
        expect(url.searchParams.get('q')).toBe('volcano');
        expect(url.searchParams.get('viewport[north]')).toBe('54.000000');
        expect(url.searchParams.get('include_extent')).toBe('1');
        expect(result.current.data?.meta.totalLocations).toBe(35_638);
    });

    it('surfaces endpoint errors without replacing them with empty data', async () => {
        server.use(http.get('/doi-search/map', () => HttpResponse.json({ message: 'Map unavailable' }, { status: 500 })));

        const { result } = renderHookWithQueryClient(() => usePortalMapData(filters, viewport, false));
        await waitFor(() => expect(result.current.isError).toBe(true), { timeout: 5_000 });

        expect(result.current.data).toBeUndefined();
        expect(result.current.error).toBeInstanceOf(Error);
    });

    it('loads map data from the IGSN endpoint when requested', async () => {
        let requestUrl = '';
        server.use(
            http.get('/igsn-search/map', ({ request }) => {
                requestUrl = request.url;
                return HttpResponse.json(payload);
            }),
        );

        const { result } = renderHookWithQueryClient(() =>
            usePortalMapData(
                {
                    ...filters,
                    sampleTypes: ['Core'],
                    materials: ['Rock'],
                    classifications: ['Igneous'],
                    geologicalAges: ['Jurassic'],
                    geologicalUnits: ['Unit A'],
                },
                viewport,
                false,
                '/igsn-search',
            ),
        );
        await waitFor(() => expect(result.current.isSuccess).toBe(true));

        const url = new URL(requestUrl);
        expect(url.searchParams.getAll('sample_types[]')).toEqual(['Core']);
        expect(url.searchParams.getAll('materials[]')).toEqual(['Rock']);
        expect(url.searchParams.getAll('classifications[]')).toEqual(['Igneous']);
        expect(url.searchParams.getAll('geological_ages[]')).toEqual(['Jurassic']);
        expect(url.searchParams.getAll('geological_units[]')).toEqual(['Unit A']);
    });
});
