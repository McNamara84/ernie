import { act, renderHook, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { useCitationLookup } from '@/hooks/use-citation-lookup';

import { http, HttpResponse, server } from '../helpers/msw-server';

const endpoint = '/api/v1/citation-lookup';

const hit = {
    source: 'crossref',
    identifier: '10.1234/abcd',
    identifier_type: 'DOI',
    related_item_type: 'JournalArticle',
    title: 'Example Article',
    publication_year: 2023,
    publisher: 'Science Journal',
    creators: [{ name: 'Doe, Jane', name_type: 'Personal', given_name: 'Jane', family_name: 'Doe' }],
};

describe('useCitationLookup', () => {
    beforeEach(() => vi.useFakeTimers());
    afterEach(() => vi.useRealTimers());

    it('debounces the lookup and returns the result', async () => {
        server.use(http.get(endpoint, () => HttpResponse.json(hit)));

        const { result } = renderHook(() => useCitationLookup({ debounceMs: 500 }));

        act(() => result.current.lookup('10.1234/abcd'));
        expect(result.current.isLoading).toBe(true);
        expect(result.current.result).toBeNull();

        await act(async () => {
            vi.advanceTimersByTime(500);
        });
        vi.useRealTimers();

        await waitFor(() => expect(result.current.isLoading).toBe(false));
        expect(result.current.result?.title).toBe('Example Article');
        expect(result.current.error).toBeNull();
    });

    it('returns cached result without re-requesting for the same DOI', async () => {
        let calls = 0;
        server.use(
            http.get(endpoint, () => {
                calls += 1;
                return HttpResponse.json(hit);
            }),
        );

        const { result } = renderHook(() => useCitationLookup({ debounceMs: 100 }));

        act(() => result.current.lookup('10.1234/abcd'));
        await act(async () => {
            vi.advanceTimersByTime(100);
        });
        vi.useRealTimers();
        await waitFor(() => expect(result.current.isLoading).toBe(false));
        expect(calls).toBe(1);

        vi.useFakeTimers();
        act(() => result.current.lookup('10.1234/abcd'));
        // Cache hit: result is set synchronously without HTTP call.
        expect(result.current.isLoading).toBe(false);
        expect(result.current.result?.title).toBe('Example Article');
        expect(calls).toBe(1);
    });

    it('resets state when an empty DOI is passed', () => {
        const { result } = renderHook(() => useCitationLookup());
        act(() => result.current.lookup(''));
        expect(result.current.result).toBeNull();
        expect(result.current.isLoading).toBe(false);
    });

    it('surfaces a rate-limit error on 429', async () => {
        server.use(
            http.get(endpoint, () => HttpResponse.json({ message: 'rate limited' }, { status: 429 })),
        );

        const { result } = renderHook(() => useCitationLookup({ debounceMs: 100 }));
        act(() => result.current.lookup('10.1234/xyz'));
        await act(async () => {
            vi.advanceTimersByTime(100);
        });
        vi.useRealTimers();
        await waitFor(() => expect(result.current.isLoading).toBe(false));
        expect(result.current.error).toMatch(/Too many/i);
        expect(result.current.result).toBeNull();
    });
});
