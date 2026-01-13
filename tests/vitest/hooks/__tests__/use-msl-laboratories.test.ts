import { act, renderHook, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { useMSLLaboratories } from '@/hooks/use-msl-laboratories';

describe('useMSLLaboratories', () => {
    let originalFetch: typeof fetch;

    beforeEach(() => {
        originalFetch = global.fetch;
    });

    afterEach(() => {
        global.fetch = originalFetch;
        vi.restoreAllMocks();
    });

    it('starts with initial state', () => {
        global.fetch = vi.fn().mockImplementation(() => new Promise(() => {}));

        const { result } = renderHook(() => useMSLLaboratories());

        expect(result.current.laboratories).toBeNull();
        expect(result.current.isLoading).toBe(true);
        expect(result.current.error).toBeNull();
    });

    it('fetches laboratories successfully', async () => {
        const mockLaboratories = [
            { id: '1', name: 'Lab 1', url: 'https://lab1.example.com' },
            { id: '2', name: 'Lab 2', url: 'https://lab2.example.com' },
        ];

        global.fetch = vi.fn()
            .mockResolvedValueOnce({
                ok: true,
                json: () => Promise.resolve({ url: 'https://vocab.example.com/labs' }),
            })
            .mockResolvedValueOnce({
                ok: true,
                json: () => Promise.resolve(mockLaboratories),
            });

        const { result } = renderHook(() => useMSLLaboratories());

        await waitFor(() => {
            expect(result.current.isLoading).toBe(false);
        });

        expect(result.current.laboratories).toEqual(mockLaboratories);
        expect(result.current.error).toBeNull();
    });

    it('handles vocabulary URL fetch failure', async () => {
        global.fetch = vi.fn().mockResolvedValueOnce({
            ok: false,
            status: 500,
        });

        const consoleSpy = vi.spyOn(console, 'error').mockImplementation(() => {});

        const { result } = renderHook(() => useMSLLaboratories());

        await waitFor(() => {
            expect(result.current.isLoading).toBe(false);
        });

        expect(result.current.laboratories).toBeNull();
        expect(result.current.error).toContain('Failed to fetch vocabulary URL: 500');
        expect(consoleSpy).toHaveBeenCalled();
    });

    it('handles laboratories fetch failure', async () => {
        global.fetch = vi.fn()
            .mockResolvedValueOnce({
                ok: true,
                json: () => Promise.resolve({ url: 'https://vocab.example.com/labs' }),
            })
            .mockResolvedValueOnce({
                ok: false,
                status: 404,
                statusText: 'Not Found',
            });

        const consoleSpy = vi.spyOn(console, 'error').mockImplementation(() => {});

        const { result } = renderHook(() => useMSLLaboratories());

        await waitFor(() => {
            expect(result.current.isLoading).toBe(false);
        });

        expect(result.current.laboratories).toBeNull();
        expect(result.current.error).toContain('Failed to fetch laboratories: 404 Not Found');
        expect(consoleSpy).toHaveBeenCalled();
    });

    it('handles invalid data format', async () => {
        global.fetch = vi.fn()
            .mockResolvedValueOnce({
                ok: true,
                json: () => Promise.resolve({ url: 'https://vocab.example.com/labs' }),
            })
            .mockResolvedValueOnce({
                ok: true,
                json: () => Promise.resolve({ notAnArray: true }),
            });

        const consoleSpy = vi.spyOn(console, 'error').mockImplementation(() => {});

        const { result } = renderHook(() => useMSLLaboratories());

        await waitFor(() => {
            expect(result.current.isLoading).toBe(false);
        });

        expect(result.current.laboratories).toBeNull();
        expect(result.current.error).toBe('Invalid data format: expected an array');
        expect(consoleSpy).toHaveBeenCalled();
    });

    it('handles network error', async () => {
        global.fetch = vi.fn().mockRejectedValueOnce(new Error('Network failure'));

        const consoleSpy = vi.spyOn(console, 'error').mockImplementation(() => {});

        const { result } = renderHook(() => useMSLLaboratories());

        await waitFor(() => {
            expect(result.current.isLoading).toBe(false);
        });

        expect(result.current.laboratories).toBeNull();
        expect(result.current.error).toBe('Network failure');
        expect(consoleSpy).toHaveBeenCalled();
    });

    it('handles non-Error exceptions', async () => {
        global.fetch = vi.fn().mockRejectedValueOnce('string error');

        const consoleSpy = vi.spyOn(console, 'error').mockImplementation(() => {});

        const { result } = renderHook(() => useMSLLaboratories());

        await waitFor(() => {
            expect(result.current.isLoading).toBe(false);
        });

        expect(result.current.error).toBe('Unknown error occurred');
        expect(consoleSpy).toHaveBeenCalled();
    });

    it('refetch triggers new data fetch', async () => {
        const mockLaboratories1 = [{ id: '1', name: 'Lab 1' }];
        const mockLaboratories2 = [{ id: '2', name: 'Lab 2' }];

        global.fetch = vi.fn()
            .mockResolvedValueOnce({
                ok: true,
                json: () => Promise.resolve({ url: 'https://vocab.example.com/labs' }),
            })
            .mockResolvedValueOnce({
                ok: true,
                json: () => Promise.resolve(mockLaboratories1),
            })
            .mockResolvedValueOnce({
                ok: true,
                json: () => Promise.resolve({ url: 'https://vocab.example.com/labs' }),
            })
            .mockResolvedValueOnce({
                ok: true,
                json: () => Promise.resolve(mockLaboratories2),
            });

        const { result } = renderHook(() => useMSLLaboratories());

        await waitFor(() => {
            expect(result.current.isLoading).toBe(false);
        });

        expect(result.current.laboratories).toEqual(mockLaboratories1);

        // Trigger refetch
        act(() => {
            result.current.refetch();
        });

        await waitFor(() => {
            expect(result.current.laboratories).toEqual(mockLaboratories2);
        });
    });
});
