import { act, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { usePortalResourcePreview } from '@/hooks/use-portal-resource-preview';
import { ApiError } from '@/lib/api-client';
import type { PortalResourcePreview } from '@/types/portal';

import { renderHookWithQueryClient } from '../../helpers/render-with-query-client';

const apiRequestMock = vi.hoisted(() => vi.fn());

vi.mock('@/lib/api-client', async (importOriginal) => {
    const original = await importOriginal<typeof import('@/lib/api-client')>();
    return { ...original, apiRequest: apiRequestMock };
});

const preview: PortalResourcePreview = {
    resourceId: 42,
    citation: { styleId: 'apa-7', label: 'APA 7', text: 'Preview citation' },
    abstract: 'Preview abstract',
};

describe('usePortalResourcePreview', () => {
    beforeEach(() => {
        apiRequestMock.mockReset();
    });

    it('does not load before the explicit open state', () => {
        const { result } = renderHookWithQueryClient(() => usePortalResourcePreview(42, '/doi-search', false));

        expect(result.current.fetchStatus).toBe('idle');
        expect(apiRequestMock).not.toHaveBeenCalled();
    });

    it('loads the DOI endpoint with an abort signal after opening', async () => {
        apiRequestMock.mockResolvedValue(preview);
        const { result } = renderHookWithQueryClient(() => usePortalResourcePreview(42, '/doi-search', true));

        await waitFor(() => expect(result.current.isSuccess).toBe(true), { timeout: 5_000 });

        expect(result.current.data).toEqual(preview);
        expect(apiRequestMock).toHaveBeenCalledWith('/doi-search/resources/42/preview', expect.objectContaining({ signal: expect.any(AbortSignal) }));
    });

    it('keeps the IGSN endpoint and cache key scoped independently', async () => {
        apiRequestMock.mockResolvedValue(preview);
        const { result, client } = renderHookWithQueryClient(() => usePortalResourcePreview(42, '/igsn-search', true));

        await waitFor(() => expect(result.current.isSuccess).toBe(true), { timeout: 5_000 });

        expect(apiRequestMock.mock.calls[0][0]).toBe('/igsn-search/resources/42/preview');
        expect(
            client.getQueryCache().find({
                queryKey: ['portal', 'resource-preview', '/igsn-search', 42],
                exact: true,
            }),
        ).toBeDefined();
    });

    it('reuses successful cached data after closing and reopening', async () => {
        apiRequestMock.mockResolvedValue(preview);
        const { result, rerender } = renderHookWithQueryClient(({ open }) => usePortalResourcePreview(42, '/doi-search', open), {
            initialProps: { open: true },
        });
        await waitFor(() => expect(result.current.isSuccess).toBe(true));

        rerender({ open: false });
        rerender({ open: true });
        await waitFor(() => expect(result.current.data).toEqual(preview));

        expect(apiRequestMock).toHaveBeenCalledTimes(1);
    });

    it('cancels an in-flight request when the popover closes', async () => {
        let requestSignal: AbortSignal | undefined;
        apiRequestMock.mockImplementation((_url: string, init: { signal?: AbortSignal }) => {
            requestSignal = init.signal;

            return new Promise((_resolve, reject) => {
                init.signal?.addEventListener('abort', () => reject(new DOMException('Aborted', 'AbortError')));
            });
        });
        const { rerender } = renderHookWithQueryClient(({ open }) => usePortalResourcePreview(42, '/doi-search', open), {
            initialProps: { open: true },
        });
        await waitFor(() => expect(requestSignal).toBeDefined());

        rerender({ open: false });

        await waitFor(() => expect(requestSignal?.aborted).toBe(true));
    });

    it('does not retry deterministic client errors', async () => {
        apiRequestMock.mockRejectedValue(new ApiError('Missing', 404));
        const { result } = renderHookWithQueryClient(() => usePortalResourcePreview(42, '/doi-search', true));

        await waitFor(() => expect(result.current.isError).toBe(true));

        expect(apiRequestMock).toHaveBeenCalledTimes(1);
    });

    it('allows one retry for a transient server error', async () => {
        apiRequestMock.mockRejectedValueOnce(new ApiError('Unavailable', 503)).mockResolvedValueOnce(preview);
        const { result } = renderHookWithQueryClient(() => usePortalResourcePreview(42, '/doi-search', true));

        await waitFor(() => expect(result.current.isSuccess).toBe(true), { timeout: 5_000 });

        expect(apiRequestMock).toHaveBeenCalledTimes(2);
    });

    it('can be retried explicitly after a terminal failure', async () => {
        apiRequestMock.mockRejectedValueOnce(new ApiError('Missing', 404)).mockResolvedValueOnce(preview);
        const { result } = renderHookWithQueryClient(() => usePortalResourcePreview(42, '/doi-search', true));
        await waitFor(() => expect(result.current.isError).toBe(true));

        await act(async () => {
            await result.current.refetch();
        });

        await waitFor(() => expect(result.current.data).toEqual(preview));
        expect(apiRequestMock).toHaveBeenCalledTimes(2);
    });
});
