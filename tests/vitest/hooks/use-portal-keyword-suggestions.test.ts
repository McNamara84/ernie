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

        expect(apiRequestMock).toHaveBeenCalledWith('/search/free-keyword-suggestions?q=seis', expect.objectContaining({ signal: expect.any(AbortSignal) }));
    });

    it('trims the query before requesting suggestions', async () => {
        apiRequestMock.mockResolvedValue({ data: [] });
        renderHookWithQueryClient(() => usePortalKeywordSuggestions('  ocean  '));

        await waitFor(() => expect(apiRequestMock).toHaveBeenCalled());

        expect(apiRequestMock.mock.calls[0][0]).toBe('/search/free-keyword-suggestions?q=ocean');
    });
});
