import { QueryClient } from '@tanstack/react-query';
import { renderHook, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { useEditorPrefetch } from '@/hooks/use-editor-prefetch';
import { queryKeys } from '@/lib/query-keys';

import { http, HttpResponse, server } from '../helpers/msw-server';
import { renderHookWithQueryClient } from '../helpers/render-with-query-client';

describe('useEditorPrefetch', () => {
    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('returns a stable callback across renders', () => {
        const { result, rerender } = renderHookWithQueryClient(() => useEditorPrefetch());

        const first = result.current;
        rerender();
        const second = result.current;

        expect(second).toBe(first);
    });

    it('prefetches ROR affiliations into the cache', async () => {
        let hits = 0;
        server.use(
            http.get('/api/v1/ror-affiliations', () => {
                hits += 1;
                return HttpResponse.json([
                    { prefLabel: 'Test Org', rorId: 'https://ror.org/000000001' },
                ]);
            }),
        );

        // Use a client with a non-zero gcTime so the prefetched data survives
        // long enough to be asserted against (the default test client uses
        // gcTime: 0, which evicts unobserved queries immediately).
        const client = new QueryClient({
            defaultOptions: {
                queries: { retry: false, gcTime: 5_000, staleTime: 0 },
            },
        });

        const { result } = renderHookWithQueryClient(() => useEditorPrefetch(), { client });

        result.current();

        await waitFor(() => {
            expect(hits).toBe(1);
        });

        await waitFor(() => {
            expect(client.getQueryData(queryKeys.ror.all())).toEqual([
                {
                    value: 'Test Org',
                    rorId: 'https://ror.org/000000001',
                    searchTerms: ['Test Org'],
                },
            ]);
        });
    });

    it('does not throw when the prefetch request fails', async () => {
        server.use(http.get('/api/v1/ror-affiliations', () => HttpResponse.error()));

        const { result } = renderHookWithQueryClient(() => useEditorPrefetch());

        expect(() => result.current()).not.toThrow();
    });

    it('returns a safe no-op when used without a QueryClientProvider', () => {
        // Render the hook without wrapping it in a provider. Tests for
        // unrelated components (e.g. AppSidebar) render the hook this way
        // and must not crash.
        const { result } = renderHook(() => useEditorPrefetch());

        expect(() => result.current()).not.toThrow();
    });
});
