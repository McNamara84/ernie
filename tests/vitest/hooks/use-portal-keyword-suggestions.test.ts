import { waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { usePortalKeywordSuggestions } from '@/hooks/use-portal-keyword-suggestions';

import { renderHookWithQueryClient } from '../helpers/render-with-query-client';

const apiRequestMock = vi.hoisted(() => vi.fn());

vi.mock('@/lib/api-client', async (importOriginal) => {
    const original = await importOriginal<typeof import('@/lib/api-client')>();
    return { ...original, apiRequest: apiRequestMock };
});

describe('usePortalKeywordSuggestions', () => {
    beforeEach(() => {
        apiRequestMock.mockReset();
    });

    it('waits for two characters and the debounce interval', async () => {
        apiRequestMock.mockResolvedValue({ data: [{ value: 'Seismology', scheme: null, count: 4 }] });
        const { result, rerender } = renderHookWithQueryClient(({ query }) => usePortalKeywordSuggestions(query), {
            initialProps: { query: 's' },
        });

        expect(result.current.fetchStatus).toBe('idle');
        rerender({ query: 'seis' });
        expect(apiRequestMock).not.toHaveBeenCalled();

        await waitFor(() => expect(apiRequestMock).toHaveBeenCalled());
        await waitFor(() => expect(result.current.data).toEqual([{ value: 'Seismology', scheme: null, count: 4 }]));

        expect(apiRequestMock).toHaveBeenCalledWith(
            '/doi-search/free-keyword-suggestions?q=seis',
            expect.objectContaining({ signal: expect.any(AbortSignal) }),
        );
    });

    it('trims the query before requesting suggestions', async () => {
        apiRequestMock.mockResolvedValue({ data: [] });
        renderHookWithQueryClient(() => usePortalKeywordSuggestions('  ocean  '));

        await waitFor(() => expect(apiRequestMock).toHaveBeenCalled());

        expect(apiRequestMock.mock.calls[0][0]).toBe('/doi-search/free-keyword-suggestions?q=ocean');
    });

    it('normalizes cache keys without using the visitor locale', () => {
        apiRequestMock.mockResolvedValue({ data: [] });
        const localeLowerCaseSpy = vi.spyOn(String.prototype, 'toLocaleLowerCase').mockReturnValue('locale-dependent');

        try {
            const { client } = renderHookWithQueryClient(() => usePortalKeywordSuggestions('ID'));

            expect(localeLowerCaseSpy).not.toHaveBeenCalled();
            expect(
                client.getQueryCache().find({
                    queryKey: ['portal', 'keyword-suggestions', '/doi-search', 'id'],
                    exact: true,
                }),
            ).toBeDefined();
        } finally {
            localeLowerCaseSpy.mockRestore();
        }
    });

    it('keeps IGSN suggestions on the IGSN endpoint and cache key', async () => {
        apiRequestMock.mockResolvedValue({ data: [] });
        const { client } = renderHookWithQueryClient(() => usePortalKeywordSuggestions('sample', '/igsn-search'));

        await waitFor(() => expect(apiRequestMock).toHaveBeenCalled());
        expect(apiRequestMock.mock.calls[0][0]).toBe('/igsn-search/free-keyword-suggestions?q=sample');
        expect(
            client.getQueryCache().find({
                queryKey: ['portal', 'keyword-suggestions', '/igsn-search', 'sample'],
                exact: true,
            }),
        ).toBeDefined();
    });
});
