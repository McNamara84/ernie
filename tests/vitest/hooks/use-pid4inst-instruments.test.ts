import { act, waitFor } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { fetchPid4instInstruments, usePid4instInstruments } from '@/hooks/use-pid4inst-instruments';
import { apiEndpoints } from '@/lib/query-keys';

import { http, HttpResponse, server } from '../helpers/msw-server';
import { renderHookWithQueryClient } from '../helpers/render-with-query-client';

const mockInstruments = [
    {
        id: '1',
        pid: '10.12345/inst-001',
        pidType: 'DOI',
        name: 'Seismometer',
        description: 'A seismic sensor',
        landingPage: 'https://example.com/inst/1',
        owners: ['GFZ'],
        manufacturers: ['Streckeisen'],
        model: 'STS-2',
        instrumentTypes: ['Seismometer'],
        measuredVariables: ['Ground Motion'],
    },
];

describe('usePid4instInstruments', () => {
    it('fetches instruments on mount', async () => {
        server.use(
            http.get(apiEndpoints.pid4instInstruments, () =>
                HttpResponse.json({ data: mockInstruments }),
            ),
        );

        const { result } = renderHookWithQueryClient(() => usePid4instInstruments());

        expect(result.current.isLoading).toBe(true);

        await waitFor(() => expect(result.current.isLoading).toBe(false));

        expect(result.current.instruments).toEqual(mockInstruments);
        expect(result.current.error).toBeNull();
    });

    it('handles 404 with backend error message', async () => {
        server.use(
            http.get(apiEndpoints.pid4instInstruments, () =>
                HttpResponse.json({ error: 'Registry not downloaded' }, { status: 404 }),
            ),
        );

        const { result } = renderHookWithQueryClient(() => usePid4instInstruments());

        await waitFor(() => expect(result.current.isLoading).toBe(false));

        expect(result.current.error).toBe('Registry not downloaded');
        expect(result.current.instruments).toBeNull();
    });

    it('handles 404 with default message when body is absent', async () => {
        server.use(
            http.get(apiEndpoints.pid4instInstruments, () => new HttpResponse(null, { status: 404 })),
        );

        const { result } = renderHookWithQueryClient(() => usePid4instInstruments());

        await waitFor(() => expect(result.current.isLoading).toBe(false));

        expect(result.current.error).toMatch(/not yet downloaded/i);
    });

    it('handles generic HTTP errors', async () => {
        server.use(
            http.get(apiEndpoints.pid4instInstruments, () => new HttpResponse(null, { status: 500 })),
        );

        const { result } = renderHookWithQueryClient(() => usePid4instInstruments());

        await waitFor(() => expect(result.current.isLoading).toBe(false));

        expect(result.current.error).toMatch(/status 500/i);
    });

    it('validates the payload shape', async () => {
        server.use(
            http.get(apiEndpoints.pid4instInstruments, () => HttpResponse.json({ unexpected: true })),
        );

        const { result } = renderHookWithQueryClient(() => usePid4instInstruments());

        await waitFor(() => expect(result.current.isLoading).toBe(false));

        expect(result.current.error).toBe('Invalid data format: expected { data: [...] }');
    });

    it('refetch triggers a new request', async () => {
        let callCount = 0;
        server.use(
            http.get(apiEndpoints.pid4instInstruments, () => {
                callCount += 1;
                return HttpResponse.json({ data: callCount === 1 ? [] : mockInstruments });
            }),
        );

        const { result } = renderHookWithQueryClient(() => usePid4instInstruments());

        await waitFor(() => expect(result.current.instruments).toEqual([]));

        await act(async () => {
            result.current.refetch();
        });

        await waitFor(() => expect(result.current.instruments).toEqual(mockInstruments));
        expect(callCount).toBe(2);
    });

    describe('fetchPid4instInstruments', () => {
        it('extracts the data array on success', async () => {
            server.use(
                http.get(apiEndpoints.pid4instInstruments, () =>
                    HttpResponse.json({ data: mockInstruments }),
                ),
            );

            await expect(fetchPid4instInstruments()).resolves.toEqual(mockInstruments);
        });

        it('throws user-friendly message on 404', async () => {
            server.use(
                http.get(apiEndpoints.pid4instInstruments, () =>
                    HttpResponse.json({ error: 'Custom msg' }, { status: 404 }),
                ),
            );

            await expect(fetchPid4instInstruments()).rejects.toThrow('Custom msg');
        });
    });
});
