import { useCallback, useEffect, useState } from 'react';

import { apiRequest } from '@/lib/api-client';
import type { RelatedItem } from '@/types/related-item';

interface UseRelatedItemsReturn {
    items: RelatedItem[];
    isLoading: boolean;
    error: string | null;
    refresh: () => Promise<void>;
    create: (payload: Partial<RelatedItem>) => Promise<RelatedItem>;
    update: (id: number, payload: Partial<RelatedItem>) => Promise<RelatedItem>;
    remove: (id: number) => Promise<void>;
    reorder: (order: { id: number; position: number }[]) => Promise<void>;
}

interface ApiEnvelope<T> {
    data: T;
}

/**
 * CRUD wrapper around `/resources/{resource}/related-items` endpoints.
 *
 * Keeps an in-memory list of {@link RelatedItem}s that mirrors the server
 * state and exposes optimistic helpers for create / update / delete / reorder.
 */
export function useRelatedItems(resourceId: number | null): UseRelatedItemsReturn {
    const [items, setItems] = useState<RelatedItem[]>([]);
    const [isLoading, setIsLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const base = resourceId !== null ? `/resources/${resourceId}/related-items` : null;

    const refresh = useCallback(async () => {
        if (base === null) {
            setItems([]);
            setIsLoading(false);
            setError(null);
            return;
        }
        setIsLoading(true);
        setError(null);
        try {
            const res = await apiRequest<ApiEnvelope<RelatedItem[]>>(base);
            setItems(res.data);
        } catch (err) {
            setError(err instanceof Error ? err.message : 'Failed to load related items');
        } finally {
            setIsLoading(false);
        }
    }, [base]);

    useEffect(() => {
        void refresh();
    }, [refresh]);

    const create = useCallback(
        async (payload: Partial<RelatedItem>): Promise<RelatedItem> => {
            if (base === null) {
                throw new Error('Cannot create related items before the resource is saved.');
            }
            const res = await apiRequest<ApiEnvelope<RelatedItem>>(base, {
                method: 'POST',
                body: payload as Record<string, unknown>,
            });
            setItems((prev) => [...prev, res.data]);
            return res.data;
        },
        [base],
    );

    const update = useCallback(
        async (id: number, payload: Partial<RelatedItem>): Promise<RelatedItem> => {
            if (base === null) {
                throw new Error('Cannot update related items before the resource is saved.');
            }
            const res = await apiRequest<ApiEnvelope<RelatedItem>>(`${base}/${id}`, {
                method: 'PUT',
                body: payload as Record<string, unknown>,
            });
            setItems((prev) => prev.map((it) => (it.id === id ? res.data : it)));
            return res.data;
        },
        [base],
    );

    const remove = useCallback(
        async (id: number): Promise<void> => {
            if (base === null) {
                throw new Error('Cannot delete related items before the resource is saved.');
            }
            await apiRequest(`${base}/${id}`, { method: 'DELETE' });
            setItems((prev) => prev.filter((it) => it.id !== id));
        },
        [base],
    );

    const reorder = useCallback(
        async (order: { id: number; position: number }[]): Promise<void> => {
            if (base === null) {
                throw new Error('Cannot reorder related items before the resource is saved.');
            }
            await apiRequest(`${base}/reorder`, {
                method: 'POST',
                body: { order },
            });
            setItems((prev) => {
                const byId = new Map(prev.map((it) => [it.id, it] as const));
                return order
                    .map(({ id, position }) => {
                        const item = byId.get(id);
                        return item ? { ...item, position } : null;
                    })
                    .filter((it): it is RelatedItem => it !== null)
                    .sort((a, b) => a.position - b.position);
            });
        },
        [base],
    );

    return { items, isLoading, error, refresh, create, update, remove, reorder };
}
