import { act, renderHook, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { usePid4instInstruments } from '@/hooks/use-pid4inst-instruments';

describe('usePid4instInstruments', () => {
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

    beforeEach(() => {
        vi.stubGlobal('fetch', vi.fn());
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('fetches instruments on mount', async () => {
        vi.mocked(fetch).mockResolvedValueOnce({
            ok: true,
            json: async () => ({ data: mockInstruments }),
        } as Response);

        const { result } = renderHook(() => usePid4instInstruments());

        expect(result.current.isLoading).toBe(true);

        await waitFor(() => {
            expect(result.current.isLoading).toBe(false);
        });

        expect(result.current.instruments).toEqual(mockInstruments);
        expect(result.current.error).toBeNull();
    });

    it('handles 404 with backend error message', async () => {
        vi.mocked(fetch).mockResolvedValueOnce({
            ok: false,
            status: 404,
            statusText: 'Not Found',
            json: async () => ({ error: 'Registry not downloaded' }),
        } as unknown as Response);

        const { result } = renderHook(() => usePid4instInstruments());

        await waitFor(() => {
            expect(result.current.isLoading).toBe(false);
        });

        expect(result.current.error).toBe('Registry not downloaded');
        expect(result.current.instruments).toBeNull();
    });

    it('handles 404 with default message when JSON parse fails', async () => {
        vi.mocked(fetch).mockResolvedValueOnce({
            ok: false,
            status: 404,
            statusText: 'Not Found',
            json: async () => {
                throw new Error('invalid json');
            },
        } as unknown as Response);

        const { result } = renderHook(() => usePid4instInstruments());

        await waitFor(() => {
            expect(result.current.isLoading).toBe(false);
        });

        expect(result.current.error).toContain('administrator must first download');
    });

    it('handles non-404 errors', async () => {
        vi.mocked(fetch).mockResolvedValueOnce({
            ok: false,
            status: 500,
            statusText: 'Internal Server Error',
        } as Response);

        const { result } = renderHook(() => usePid4instInstruments());

        await waitFor(() => {
            expect(result.current.isLoading).toBe(false);
        });

        expect(result.current.error).toContain('500');
    });

    it('handles invalid data format', async () => {
        vi.mocked(fetch).mockResolvedValueOnce({
            ok: true,
            json: async () => ({ notData: 'wrong' }),
        } as Response);

        const { result } = renderHook(() => usePid4instInstruments());

        await waitFor(() => {
            expect(result.current.isLoading).toBe(false);
        });

        expect(result.current.error).toContain('Invalid data format');
    });

    it('refetch triggers new fetch', async () => {
        vi.mocked(fetch)
            .mockResolvedValueOnce({
                ok: true,
                json: async () => ({ data: [] }),
            } as Response)
            .mockResolvedValueOnce({
                ok: true,
                json: async () => ({ data: mockInstruments }),
            } as Response);

        const { result } = renderHook(() => usePid4instInstruments());

        await waitFor(() => {
            expect(result.current.isLoading).toBe(false);
        });

        expect(result.current.instruments).toEqual([]);

        act(() => {
            result.current.refetch();
        });

        await waitFor(() => {
            expect(result.current.instruments).toEqual(mockInstruments);
        });

        expect(fetch).toHaveBeenCalledTimes(2);
    });
});
