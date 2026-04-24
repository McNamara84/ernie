import { renderHook, waitFor } from '@testing-library/react';
import { act } from 'react';
import { describe, expect, it } from 'vitest';

import { useRelatedItems } from '@/hooks/use-related-items';

import { http, HttpResponse, server } from '../helpers/msw-server';

const resourceId = 42;
const base = `/resources/${resourceId}/related-items`;

const makeItem = (overrides: Record<string, unknown> = {}) => ({
    id: 1,
    resource_id: resourceId,
    related_item_type: 'JournalArticle',
    relation_type_id: 1,
    position: 0,
    titles: [{ title: 'Foo', title_type: 'MainTitle', position: 0 }],
    creators: [],
    contributors: [],
    ...overrides,
});

describe('useRelatedItems', () => {
    it('loads items on mount', async () => {
        server.use(http.get(base, () => HttpResponse.json({ data: [makeItem()] })));

        const { result } = renderHook(() => useRelatedItems(resourceId));

        await waitFor(() => expect(result.current.isLoading).toBe(false));
        expect(result.current.items).toHaveLength(1);
        expect(result.current.error).toBeNull();
    });

    it('appends an item after create', async () => {
        server.use(
            http.get(base, () => HttpResponse.json({ data: [] })),
            http.post(base, () => HttpResponse.json({ data: makeItem({ id: 7 }) }, { status: 201 })),
        );

        const { result } = renderHook(() => useRelatedItems(resourceId));
        await waitFor(() => expect(result.current.isLoading).toBe(false));

        await act(async () => {
            await result.current.create({ related_item_type: 'JournalArticle' });
        });

        expect(result.current.items.map((it) => it.id)).toEqual([7]);
    });

    it('replaces an item after update', async () => {
        server.use(
            http.get(base, () => HttpResponse.json({ data: [makeItem({ id: 1 })] })),
            http.put(`${base}/1`, () =>
                HttpResponse.json({ data: makeItem({ id: 1, related_item_type: 'Book' }) }),
            ),
        );

        const { result } = renderHook(() => useRelatedItems(resourceId));
        await waitFor(() => expect(result.current.isLoading).toBe(false));

        await act(async () => {
            await result.current.update(1, { related_item_type: 'Book' });
        });

        expect(result.current.items[0].related_item_type).toBe('Book');
    });

    it('removes an item after delete', async () => {
        server.use(
            http.get(base, () =>
                HttpResponse.json({ data: [makeItem({ id: 1 }), makeItem({ id: 2 })] }),
            ),
            http.delete(`${base}/1`, () => new HttpResponse(null, { status: 204 })),
        );

        const { result } = renderHook(() => useRelatedItems(resourceId));
        await waitFor(() => expect(result.current.isLoading).toBe(false));

        await act(async () => {
            await result.current.remove(1);
        });

        expect(result.current.items.map((it) => it.id)).toEqual([2]);
    });

    it('reorders items locally after the reorder call succeeds', async () => {
        server.use(
            http.get(base, () =>
                HttpResponse.json({
                    data: [makeItem({ id: 1, position: 0 }), makeItem({ id: 2, position: 1 })],
                }),
            ),
            http.post(`${base}/reorder`, () => new HttpResponse(null, { status: 204 })),
        );

        const { result } = renderHook(() => useRelatedItems(resourceId));
        await waitFor(() => expect(result.current.isLoading).toBe(false));

        await act(async () => {
            await result.current.reorder([
                { id: 2, position: 0 },
                { id: 1, position: 1 },
            ]);
        });

        expect(result.current.items.map((it) => it.id)).toEqual([2, 1]);
    });
});
