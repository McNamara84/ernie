import { waitFor } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { fetchRorAffiliations, useRorAffiliations } from '@/hooks/use-ror-affiliations';
import { apiEndpoints } from '@/lib/query-keys';

import { http, HttpResponse, server } from '../../helpers/msw-server';
import { renderHookWithQueryClient } from '../../helpers/render-with-query-client';

describe('useRorAffiliations', () => {
    it('fetches and normalises affiliation suggestions', async () => {
        server.use(
            http.get(apiEndpoints.rorAffiliations, () =>
                HttpResponse.json([
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
            ),
        );

        const { result } = renderHookWithQueryClient(() => useRorAffiliations());

        expect(result.current.isLoading).toBe(true);

        await waitFor(() => expect(result.current.isLoading).toBe(false));

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
        server.use(
            http.get(apiEndpoints.rorAffiliations, () =>
                HttpResponse.json({ message: 'Server exploded' }, { status: 500 }),
            ),
        );

        const { result } = renderHookWithQueryClient(() => useRorAffiliations());

        await waitFor(() => expect(result.current.isLoading).toBe(false));

        expect(result.current.suggestions).toEqual([]);
        expect(result.current.error).toBeInstanceOf(Error);
        expect(result.current.error?.message).toContain('Server exploded');
    });

    it('returns an empty array when the payload is not an array', async () => {
        server.use(
            http.get(apiEndpoints.rorAffiliations, () => HttpResponse.json({ oops: true })),
        );

        const { result } = renderHookWithQueryClient(() => useRorAffiliations());

        await waitFor(() => expect(result.current.isLoading).toBe(false));

        expect(result.current.suggestions).toEqual([]);
        expect(result.current.error).toBeNull();
    });

    it('filters out entries without a valid prefLabel or rorId', async () => {
        server.use(
            http.get(apiEndpoints.rorAffiliations, () =>
                HttpResponse.json([
                    { prefLabel: '', rorId: 'https://ror.org/01' },
                    { prefLabel: 'Valid', rorId: '' },
                    { prefLabel: 'Valid', rorId: 'https://ror.org/ok', otherLabel: [] },
                    null,
                    'not-an-object',
                ]),
            ),
        );

        const { result } = renderHookWithQueryClient(() => useRorAffiliations());

        await waitFor(() => expect(result.current.isLoading).toBe(false));

        expect(result.current.suggestions).toEqual([
            { value: 'Valid', rorId: 'https://ror.org/ok', searchTerms: [] },
        ]);
    });

    it('dedupes concurrent fetches through the shared cache', async () => {
        let callCount = 0;
        server.use(
            http.get(apiEndpoints.rorAffiliations, () => {
                callCount += 1;
                return HttpResponse.json([{ prefLabel: 'A', rorId: 'https://ror.org/a' }]);
            }),
        );

        const { result: first, client } = renderHookWithQueryClient(() => useRorAffiliations());
        const { result: second } = renderHookWithQueryClient(() => useRorAffiliations(), { client });

        await waitFor(() => expect(first.current.isLoading).toBe(false));
        await waitFor(() => expect(second.current.isLoading).toBe(false));

        expect(callCount).toBe(1);
        expect(first.current.suggestions).toHaveLength(1);
        expect(second.current.suggestions).toHaveLength(1);
    });

    describe('fetchRorAffiliations (direct call)', () => {
        it('propagates HTTP errors', async () => {
            server.use(
                http.get(apiEndpoints.rorAffiliations, () => new HttpResponse(null, { status: 503 })),
            );

            await expect(fetchRorAffiliations()).rejects.toThrow();
        });

        it('respects an AbortSignal', async () => {
            server.use(
                http.get(apiEndpoints.rorAffiliations, async () => {
                    await new Promise((resolve) => setTimeout(resolve, 500));
                    return HttpResponse.json([]);
                }),
            );

            const controller = new AbortController();
            const promise = fetchRorAffiliations(controller.signal);
            controller.abort();

            await expect(promise).rejects.toThrow();
        });
    });
});