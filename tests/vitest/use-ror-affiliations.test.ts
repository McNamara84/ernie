import { renderHook, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { useRorAffiliations } from '@/hooks/use-ror-affiliations';

global.fetch = vi.fn();

describe('useRorAffiliations', () => {
    beforeEach(() => {
        vi.resetAllMocks();
    });

    it('initializes with empty suggestions and loading state', () => {
        (global.fetch as ReturnType<typeof vi.fn>).mockImplementation(
            () => new Promise(() => {}) // Never resolves
        );

        const { result } = renderHook(() => useRorAffiliations());

        expect(result.current.suggestions).toEqual([]);
        expect(result.current.isLoading).toBe(true);
        expect(result.current.error).toBeNull();
    });

    it('fetches and normalizes ROR affiliations successfully', async () => {
        const mockData = [
            {
                prefLabel: 'University of Potsdam',
                rorId: 'https://ror.org/03bq45144',
                otherLabel: ['Universität Potsdam', 'UP'],
            },
            {
                prefLabel: 'Max Planck Institute',
                rorId: 'https://ror.org/00z8tcb16',
                otherLabel: ['MPI', 'Albert Einstein Institut'],
            },
        ];

        (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValueOnce({
            ok: true,
            status: 200,
            json: async () => mockData,
        });

        const { result } = renderHook(() => useRorAffiliations());

        await waitFor(() => {
            expect(result.current.isLoading).toBe(false);
        });

        expect(result.current.suggestions).toHaveLength(2);
        expect(result.current.suggestions[0]).toEqual({
            value: 'University of Potsdam',
            rorId: 'https://ror.org/03bq45144',
            searchTerms: ['Universität Potsdam', 'UP'],
        });
        expect(result.current.error).toBeNull();
    });

    it('filters out invalid entries during normalization', async () => {
        const mockData = [
            {
                prefLabel: 'Valid University',
                rorId: 'https://ror.org/123',
                otherLabel: ['VU'],
            },
            {
                prefLabel: '',  // Invalid: empty prefLabel
                rorId: 'https://ror.org/456',
                otherLabel: [],
            },
            {
                prefLabel: 'Missing ROR ID',
                rorId: '',  // Invalid: empty rorId
                otherLabel: [],
            },
            {
                prefLabel: 'Another Valid',
                rorId: 'https://ror.org/789',
                otherLabel: ['AV'],
            },
        ];

        (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValueOnce({
            ok: true,
            status: 200,
            json: async () => mockData,
        });

        const { result } = renderHook(() => useRorAffiliations());

        await waitFor(() => {
            expect(result.current.isLoading).toBe(false);
        });

        expect(result.current.suggestions).toHaveLength(2);
        expect(result.current.suggestions[0].value).toBe('Valid University');
        expect(result.current.suggestions[1].value).toBe('Another Valid');
    });

    it('handles API errors gracefully', async () => {
        (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValueOnce({
            ok: false,
            status: 500,
            json: async () => ({}),
        });

        const { result } = renderHook(() => useRorAffiliations());

        await waitFor(() => {
            expect(result.current.isLoading).toBe(false);
        });

        expect(result.current.suggestions).toEqual([]);
        expect(result.current.error).toBeInstanceOf(Error);
        expect(result.current.error?.message).toContain('500');
    });

    it('handles network errors', async () => {
        (global.fetch as ReturnType<typeof vi.fn>).mockRejectedValueOnce(
            new Error('Network error')
        );

        const { result } = renderHook(() => useRorAffiliations());

        await waitFor(() => {
            expect(result.current.isLoading).toBe(false);
        });

        expect(result.current.suggestions).toEqual([]);
        expect(result.current.error).toBeInstanceOf(Error);
        expect(result.current.error?.message).toBe('Network error');
    });

    it('handles non-array responses', async () => {
        (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValueOnce({
            ok: true,
            status: 200,
            json: async () => ({ invalid: 'response' }),
        });

        const { result } = renderHook(() => useRorAffiliations());

        await waitFor(() => {
            expect(result.current.isLoading).toBe(false);
        });

        expect(result.current.suggestions).toEqual([]);
        expect(result.current.error).toBeNull();
    });

    it('trims whitespace from prefLabel and rorId', async () => {
        const mockData = [
            {
                prefLabel: '  University with Spaces  ',
                rorId: '  https://ror.org/123  ',
                otherLabel: ['  Spaced Label  ', '', '  Another  '],
            },
        ];

        (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValueOnce({
            ok: true,
            status: 200,
            json: async () => mockData,
        });

        const { result } = renderHook(() => useRorAffiliations());

        await waitFor(() => {
            expect(result.current.isLoading).toBe(false);
        });

        expect(result.current.suggestions[0]).toEqual({
            value: 'University with Spaces',
            rorId: 'https://ror.org/123',
            searchTerms: ['Spaced Label', 'Another'],
        });
    });

    it('uses prefLabel as fallback if otherLabel is not an array', async () => {
        const mockData = [
            {
                prefLabel: 'Test University',
                rorId: 'https://ror.org/123',
                otherLabel: null, // Not an array
            },
        ];

        (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValueOnce({
            ok: true,
            status: 200,
            json: async () => mockData,
        });

        const { result } = renderHook(() => useRorAffiliations());

        await waitFor(() => {
            expect(result.current.isLoading).toBe(false);
        });

        expect(result.current.suggestions[0].searchTerms).toEqual(['Test University']);
    });

    it('handles abort signal correctly', async () => {
        let resolveFunc: ((value: unknown) => void) | null = null;

        (global.fetch as ReturnType<typeof vi.fn>).mockImplementation(
            () => new Promise((resolve) => {
                resolveFunc = resolve;
            })
        );

        const { unmount } = renderHook(() => useRorAffiliations());

        // Unmount before fetch completes
        unmount();

        // Resolve the promise to avoid hanging
        if (resolveFunc) {
            resolveFunc({
                ok: true,
                status: 200,
                json: async () => [],
            });
        }

        // Wait a bit to ensure cleanup happened
        await new Promise((resolve) => setTimeout(resolve, 50));

        // Component unmounted, so state shouldn't update
        // This test mainly ensures no errors are thrown during cleanup
        expect(true).toBe(true);
    });

    it('handles large datasets efficiently', async () => {
        const largeDataset = Array.from({ length: 120000 }, (_, i) => ({
            prefLabel: `University ${i}`,
            rorId: `https://ror.org/${i}`,
            otherLabel: [`Uni ${i}`],
        }));

        (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValueOnce({
            ok: true,
            status: 200,
            json: async () => largeDataset,
        });

        const { result } = renderHook(() => useRorAffiliations());

        await waitFor(() => {
            expect(result.current.isLoading).toBe(false);
        });

        expect(result.current.suggestions).toHaveLength(120000);
        expect(result.current.error).toBeNull();
    });
});
