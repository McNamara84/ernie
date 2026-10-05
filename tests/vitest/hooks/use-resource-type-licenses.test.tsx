import { act, renderHook, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { useResourceTypeLicenses } from '@/hooks/use-resource-type-licenses';
import type { License } from '@/types';

const licenses: License[] = [
    { id: 1, identifier: 'MIT', name: 'MIT', uri: null, scheme_uri: null },
    { id: 2, identifier: 'CC-BY-4.0', name: 'CC BY', uri: null, scheme_uri: null },
];

afterEach(() => vi.unstubAllGlobals());

describe('resource type license choices', () => {
    it('uses the catalog before selection and filters choices after selecting a type', async () => {
        const fetch = vi.fn().mockResolvedValue({ ok: true, json: async () => [licenses[1]] });
        vi.stubGlobal('fetch', fetch);
        const { result, rerender } = renderHook(({ id }) => useResourceTypeLicenses(licenses, id), { initialProps: { id: '' } });
        expect(result.current.choices).toEqual(licenses);
        expect(fetch).not.toHaveBeenCalled();
        rerender({ id: '42' });
        expect(result.current.loading).toBe(true);
        expect(result.current.choices).toEqual([]);
        await waitFor(() => expect(result.current.choices).toEqual([licenses[1]]));
        expect(fetch).toHaveBeenCalledWith(
            '/api/v1/licenses/ernie?resource_type_id=42',
            expect.objectContaining({ signal: expect.any(AbortSignal) }),
        );
    });

    it('ignores a stale response after switching resource type', async () => {
        let resolveFirst: (response: unknown) => void = () => {};
        const fetch = vi
            .fn()
            .mockImplementationOnce(
                () =>
                    new Promise((resolve) => {
                        resolveFirst = resolve;
                    }),
            )
            .mockResolvedValueOnce({ ok: true, json: async () => [licenses[1]] });
        vi.stubGlobal('fetch', fetch);
        const { result, rerender } = renderHook(({ id }) => useResourceTypeLicenses(licenses, id), { initialProps: { id: '1' } });
        rerender({ id: '2' });
        await waitFor(() => expect(result.current.choices).toEqual([licenses[1]]));
        await act(async () => resolveFirst({ ok: true, json: async () => [licenses[0]] }));
        expect(result.current.choices).toEqual([licenses[1]]);
        expect(fetch.mock.calls[0][1].signal.aborted).toBe(true);
        rerender({ id: '' });
        expect(result.current.choices).toEqual(licenses);
    });

    it.each([
        { ok: false, json: async () => [] },
        { ok: true, json: async () => ({ invalid: true }) },
    ])('reports unsuccessful or malformed responses without exposing excluded choices', async (response) => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(response));
        const { result } = renderHook(() => useResourceTypeLicenses(licenses, '1'));
        await waitFor(() => expect(result.current.error).toBe(true));
        expect(result.current.loading).toBe(false);
        expect(result.current.choices).toEqual([]);
    });
});
