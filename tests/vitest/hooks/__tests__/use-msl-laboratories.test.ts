import { act, waitFor } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { fetchMslLaboratories, useMSLLaboratories } from '@/hooks/use-msl-laboratories';
import { apiEndpoints } from '@/lib/query-keys';

import { http, HttpResponse, server } from '../../helpers/msw-server';
import { renderHookWithQueryClient } from '../../helpers/render-with-query-client';

const LABORATORY = {
    identifier: 'lab:one',
    name: 'Rock Lab',
    display_name: 'Rock Lab (Example University)',
    affiliation_name: 'Example University',
    affiliation_ror: 'https://ror.org/04pp8hn57',
    scientific_domain: 'Rock physics',
    country: 'Netherlands',
};

const RESPONSE = {
    version: '1.1',
    lastUpdated: '2026-07-21T12:00:00+00:00',
    total: 1,
    data: [LABORATORY],
};

describe('useMSLLaboratories', () => {
    it('starts in a loading state', () => {
        server.use(
            http.get(
                apiEndpoints.mslLaboratories,
                () =>
                    new Promise(() => {
                        // Deliberately pending.
                    }),
            ),
        );

        const { result } = renderHookWithQueryClient(() => useMSLLaboratories());

        expect(result.current.laboratories).toBeNull();
        expect(result.current.isLoading).toBe(true);
        expect(result.current.isUnavailable).toBe(false);
        expect(result.current.error).toBeNull();
    });

    it('loads one validated wrapper from the internal endpoint', async () => {
        let requestCount = 0;
        server.use(
            http.get(apiEndpoints.mslLaboratories, () => {
                requestCount += 1;
                return HttpResponse.json(RESPONSE);
            }),
        );

        const { result } = renderHookWithQueryClient(() => useMSLLaboratories());

        await waitFor(() => expect(result.current.isLoading).toBe(false));

        expect(requestCount).toBe(1);
        expect(result.current.laboratories).toEqual([LABORATORY]);
        expect(result.current.version).toBe('1.1');
        expect(result.current.lastUpdated).toBe(RESPONSE.lastUpdated);
        expect(result.current.isUnavailable).toBe(false);
        expect(result.current.error).toBeNull();
    });

    it('represents a disabled or missing vocabulary as unavailable', async () => {
        server.use(http.get(apiEndpoints.mslLaboratories, () => new HttpResponse(null, { status: 404 })));

        const { result } = renderHookWithQueryClient(() => useMSLLaboratories());

        await waitFor(() => expect(result.current.isLoading).toBe(false));

        expect(result.current.laboratories).toBeNull();
        expect(result.current.isUnavailable).toBe(true);
        expect(result.current.error).toBeNull();
    });

    it('does not request the endpoint when explicitly disabled', async () => {
        let requestCount = 0;
        server.use(
            http.get(apiEndpoints.mslLaboratories, () => {
                requestCount += 1;
                return HttpResponse.json(RESPONSE);
            }),
        );

        const { result } = renderHookWithQueryClient(() => useMSLLaboratories({ enabled: false }));

        expect(result.current.isLoading).toBe(false);
        expect(result.current.isUnavailable).toBe(true);
        expect(result.current.error).toBeNull();
        expect(requestCount).toBe(0);
    });

    it('reports operational endpoint failures separately', async () => {
        server.use(http.get(apiEndpoints.mslLaboratories, () => new HttpResponse(null, { status: 500 })));

        const { result } = renderHookWithQueryClient(() => useMSLLaboratories());

        await waitFor(() => expect(result.current.isLoading).toBe(false));

        expect(result.current.isUnavailable).toBe(false);
        expect(result.current.error).toMatch(/status 500/i);
    });

    it.each([
        [{ ...RESPONSE, data: {} }, 'data'],
        [{ ...RESPONSE, total: 2 }, 'total'],
        [{ ...RESPONSE, data: [{ ...LABORATORY, country: undefined }] }, 'country'],
        [{ ...RESPONSE, data: [{ ...LABORATORY, affiliation_ror: 123 }] }, 'affiliation_ror'],
        [{ ...RESPONSE, data: [{ ...LABORATORY, affiliation_ror: 'javascript:alert(1)' }] }, 'affiliation_ror'],
    ])('rejects an invalid response payload (%s)', async (payload, expectedPath) => {
        server.use(http.get(apiEndpoints.mslLaboratories, () => HttpResponse.json(payload)));

        const { result } = renderHookWithQueryClient(() => useMSLLaboratories());

        await waitFor(() => expect(result.current.isLoading).toBe(false));

        expect(result.current.error).toContain('Invalid MSL laboratories response');
        expect(result.current.error).toContain(expectedPath);
    });

    it('accepts a nullable affiliation ROR', async () => {
        const response = { ...RESPONSE, data: [{ ...LABORATORY, affiliation_ror: null }] };
        server.use(http.get(apiEndpoints.mslLaboratories, () => HttpResponse.json(response)));

        await expect(fetchMslLaboratories()).resolves.toEqual(response);
    });

    it('refetches the local wrapper on demand', async () => {
        let requestCount = 0;
        server.use(
            http.get(apiEndpoints.mslLaboratories, () => {
                requestCount += 1;
                return HttpResponse.json({ ...RESPONSE, version: requestCount === 1 ? '1.1' : '1.2' });
            }),
        );

        const { result } = renderHookWithQueryClient(() => useMSLLaboratories());
        await waitFor(() => expect(result.current.version).toBe('1.1'));

        await act(async () => result.current.refetch());

        await waitFor(() => expect(result.current.version).toBe('1.2'));
        expect(requestCount).toBe(2);
    });
});
