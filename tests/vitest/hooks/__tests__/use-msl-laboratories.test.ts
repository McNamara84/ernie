import { act, waitFor } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { fetchMslLaboratories, useMSLLaboratories } from '@/hooks/use-msl-laboratories';
import { apiEndpoints } from '@/lib/query-keys';

import { http, HttpResponse, server } from '../../helpers/msw-server';
import { renderHookWithQueryClient } from '../../helpers/render-with-query-client';

const VOCAB_URL = 'https://vocab.example.test/msl';

describe('useMSLLaboratories', () => {
    it('starts with initial state', () => {
        server.use(
            http.get(apiEndpoints.mslVocabularyUrl, () =>
                new Promise(() => {
                    /* never resolves */
                }),
            ),
        );

        const { result } = renderHookWithQueryClient(() => useMSLLaboratories());

        expect(result.current.laboratories).toBeNull();
        expect(result.current.isLoading).toBe(true);
        expect(result.current.error).toBeNull();
    });

    it('fetches laboratories successfully', async () => {
        const mockLaboratories = [
            { id: '1', name: 'Lab 1', url: 'https://lab1.example.com' },
            { id: '2', name: 'Lab 2', url: 'https://lab2.example.com' },
        ];

        server.use(
            http.get(apiEndpoints.mslVocabularyUrl, () => HttpResponse.json({ url: VOCAB_URL })),
            http.get(VOCAB_URL, () => HttpResponse.json(mockLaboratories)),
        );

        const { result } = renderHookWithQueryClient(() => useMSLLaboratories());

        await waitFor(() => expect(result.current.isLoading).toBe(false));

        expect(result.current.laboratories).toEqual(mockLaboratories);
        expect(result.current.error).toBeNull();
    });

    it('handles vocabulary URL fetch failure', async () => {
        server.use(
            http.get(apiEndpoints.mslVocabularyUrl, () => new HttpResponse(null, { status: 500 })),
        );

        const { result } = renderHookWithQueryClient(() => useMSLLaboratories());

        await waitFor(() => expect(result.current.isLoading).toBe(false));

        expect(result.current.laboratories).toBeNull();
        expect(result.current.error).toMatch(/status 500/i);
    });

    it('handles laboratories fetch failure', async () => {
        server.use(
            http.get(apiEndpoints.mslVocabularyUrl, () => HttpResponse.json({ url: VOCAB_URL })),
            http.get(VOCAB_URL, () => new HttpResponse(null, { status: 404 })),
        );

        const { result } = renderHookWithQueryClient(() => useMSLLaboratories());

        await waitFor(() => expect(result.current.isLoading).toBe(false));

        expect(result.current.laboratories).toBeNull();
        expect(result.current.error).toMatch(/404/);
    });

    it('handles invalid data format', async () => {
        server.use(
            http.get(apiEndpoints.mslVocabularyUrl, () => HttpResponse.json({ url: VOCAB_URL })),
            http.get(VOCAB_URL, () => HttpResponse.json({ notAnArray: true })),
        );

        const { result } = renderHookWithQueryClient(() => useMSLLaboratories());

        await waitFor(() => expect(result.current.isLoading).toBe(false));

        expect(result.current.laboratories).toBeNull();
        expect(result.current.error).toBe('Invalid data format: expected an array');
    });

    it('rejects an empty vocabulary URL', async () => {
        server.use(http.get(apiEndpoints.mslVocabularyUrl, () => HttpResponse.json({ url: '' })));

        const { result } = renderHookWithQueryClient(() => useMSLLaboratories());

        await waitFor(() => expect(result.current.isLoading).toBe(false));

        expect(result.current.error).toBe('Invalid vocabulary URL received from backend');
    });

    it('refetch triggers a new data fetch', async () => {
        const lab1 = [{ id: '1', name: 'Lab 1' }];
        const lab2 = [{ id: '2', name: 'Lab 2' }];
        let callCount = 0;

        server.use(
            http.get(apiEndpoints.mslVocabularyUrl, () => HttpResponse.json({ url: VOCAB_URL })),
            http.get(VOCAB_URL, () => {
                callCount += 1;
                return HttpResponse.json(callCount === 1 ? lab1 : lab2);
            }),
        );

        const { result } = renderHookWithQueryClient(() => useMSLLaboratories());

        await waitFor(() => expect(result.current.laboratories).toEqual(lab1));

        await act(async () => {
            result.current.refetch();
        });

        await waitFor(() => expect(result.current.laboratories).toEqual(lab2));
    });

    describe('fetchMslLaboratories', () => {
        it('fetches laboratories via a plain cross-origin GET', async () => {
            server.use(
                http.get(apiEndpoints.mslVocabularyUrl, () => HttpResponse.json({ url: VOCAB_URL })),
                http.get(VOCAB_URL, () => HttpResponse.json([{ id: '1' }])),
            );

            await expect(fetchMslLaboratories()).resolves.toEqual([{ id: '1' }]);
        });
    });
});