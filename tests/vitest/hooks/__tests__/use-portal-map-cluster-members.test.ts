import { waitFor } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { usePortalMapClusterMembers } from '@/hooks/use-portal-map-cluster-members';
import type { PortalFilters, PortalMapClusterMembersResponse, PortalMapViewport } from '@/types/portal';

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
    zoom: 18,
};

const payload: PortalMapClusterMembersResponse = {
    schemaVersion: 1,
    clusterId: 'z18:140812:37114',
    total: 2,
    members: [],
    pagination: { currentPage: 1, lastPage: 1, perPage: 50 },
};

describe('usePortalMapClusterMembers', () => {
    it('does not request data while no terminal cluster is selected', () => {
        let requests = 0;
        server.use(
            http.get('/doi-search/map/clusters/:clusterId', () => {
                requests++;
                return HttpResponse.json(payload);
            }),
        );

        const { result } = renderHookWithQueryClient(() => usePortalMapClusterMembers(filters, viewport, null, 1, '/doi-search', 18));

        expect(result.current.fetchStatus).toBe('idle');
        expect(requests).toBe(0);
    });

    it('loads the requested cluster page with the original viewport and filters', async () => {
        let requestUrl = '';
        let routeClusterId = '';
        server.use(
            http.get('/doi-search/map/clusters/:clusterId', ({ params, request }) => {
                requestUrl = request.url;
                routeClusterId = String(params.clusterId);
                return HttpResponse.json({
                    ...payload,
                    pagination: { ...payload.pagination, currentPage: 2, lastPage: 3 },
                });
            }),
        );

        const { result } = renderHookWithQueryClient(() => usePortalMapClusterMembers(filters, viewport, payload.clusterId, 2, '/doi-search', 18));
        await waitFor(() => expect(result.current.isSuccess).toBe(true));

        const url = new URL(requestUrl);
        expect(routeClusterId).toBe(payload.clusterId);
        expect(url.searchParams.get('q')).toBe('volcano');
        expect(url.searchParams.get('viewport[north]')).toBe('54.000000');
        expect(url.searchParams.get('zoom')).toBe('18');
        expect(url.searchParams.get('page')).toBe('2');
        expect(result.current.data?.pagination.currentPage).toBe(2);
    });
});
