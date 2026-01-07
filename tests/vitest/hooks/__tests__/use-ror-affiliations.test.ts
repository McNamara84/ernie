import { renderHook, waitFor } from '@testing-library/react';
import type { Mock } from 'vitest';
import { afterAll, afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { useRorAffiliations } from '@/hooks/use-ror-affiliations';

describe('useRorAffiliations', () => {
    const originalFetch = global.fetch;

    beforeEach(() => {
        global.fetch = vi.fn();
    });

    afterEach(() => {
        vi.resetAllMocks();
    });

    afterAll(() => {
        global.fetch = originalFetch;
    });

    it('fetches and normalises affiliation suggestions', async () => {
        const response = {
            ok: true,
            json: vi.fn().mockResolvedValue([
                {
                    prefLabel: 'Example University',
                    rorId: 'https://ror.org/01',
                    otherLabel: ['Example University', 'EU'],
                },
                {
                    prefLabel: ' ',
                    rorId: 'https://ror.org/ignore',
                },
                {
                    prefLabel: 'Sample Institute',
                    rorId: 'https://ror.org/02',
                    otherLabel: null,
                },
            ]),
        } as unknown as Response;

        (global.fetch as unknown as Mock).mockResolvedValue(response);

        const { result } = renderHook(() => useRorAffiliations());

        expect(result.current.isLoading).toBe(true);

        await waitFor(() => {
            expect(result.current.isLoading).toBe(false);
        });

        expect(global.fetch).toHaveBeenCalledWith('/api/v1/ror-affiliations', expect.any(Object));

        expect(result.current.error).toBeNull();
        expect(result.current.suggestions).toEqual([
            {
                value: 'Example University',
                rorId: 'https://ror.org/01',
                searchTerms: ['Example University', 'EU'],
            },
            {
                value: 'Sample Institute',
                rorId: 'https://ror.org/02',
                searchTerms: ['Sample Institute'],
            },
        ]);
    });

    it('reports an error when the request fails', async () => {
        (global.fetch as unknown as Mock).mockResolvedValue({
            ok: false,
            status: 500,
            json: vi.fn().mockResolvedValue([]),
        } as unknown as Response);

        const { result } = renderHook(() => useRorAffiliations());

        await waitFor(() => {
            expect(result.current.isLoading).toBe(false);
        });

        expect(result.current.suggestions).toEqual([]);
        expect(result.current.error).toBeInstanceOf(Error);
    });
});
