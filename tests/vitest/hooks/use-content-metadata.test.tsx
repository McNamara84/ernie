import { act, renderHook } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { useContentMetadata } from '@/hooks/use-content-metadata';

describe('content metadata save reconciliation', () => {
    it('updates stored IDs without resetting input typed while a save was in flight', () => {
        const { result } = renderHook(() => useContentMetadata());
        act(() => result.current.setFormats([{ clientId: 'local', value: 'text/csv' }]));
        const submitted = result.current.payload;
        act(() => result.current.setFormats([{ clientId: 'local', value: 'application/json' }]));
        act(() => result.current.applySaved({ formats: [{ id: 7, value: 'text/csv' }], sizes: [], fingerprint: 'a'.repeat(64) }, submitted));
        expect(result.current.formats).toMatchObject([{ clientId: 'local', id: 7, value: 'application/json' }]);
        expect(result.current.payload.contentMetadataChanged).toBe(true);
        expect(result.current.payload.contentMetadataFingerprint).toBe('a'.repeat(64));
    });

    it('does not reinsert a row deleted while saving and retains new assistant rows', () => {
        const { result } = renderHook(() => useContentMetadata({ formats: [{ id: 1, value: 'text/csv' }], sizes: [], fingerprint: 'a'.repeat(64) }));
        const submitted = result.current.payload;
        act(() => result.current.setFormats([]));
        act(() =>
            result.current.applySaved(
                {
                    formats: [
                        { id: 1, value: 'text/csv' },
                        { id: 2, value: 'application/zip' },
                    ],
                    sizes: [],
                    fingerprint: 'b'.repeat(64),
                },
                submitted,
            ),
        );
        expect(result.current.formats.map((row) => row.value)).toEqual(['application/zip']);
        expect(result.current.payload.contentMetadataChanged).toBe(true);
    });

    it('starts imported metadata as unsaved and clears dirty state after saving', () => {
        const initial = { formats: [{ value: 'text/csv' }], sizes: [{ numeric_value: '1,5', unit: 'GB', type: null }], fingerprint: null };
        const { result } = renderHook(() => useContentMetadata(initial));
        expect(result.current.payload.contentMetadataChanged).toBe(true);
        const submitted = result.current.payload;
        act(() =>
            result.current.applySaved(
                {
                    formats: [{ id: 3, value: 'text/csv' }],
                    sizes: [{ id: 4, numeric_value: '1.5000', unit: 'GB', type: null }],
                    fingerprint: 'c'.repeat(64),
                },
                submitted,
            ),
        );
        expect(result.current.payload.contentMetadataChanged).toBe(false);
        expect(result.current.sizes[0].id).toBe(4);
        expect(result.current.sizes[0].numeric_value).toBe('1.5000');
    });

    it('replaces a server-removed row after an unrelated save without resurrecting its old ID', () => {
        const { result } = renderHook(() => useContentMetadata({ formats: [{ id: 1, value: 'text/csv' }], sizes: [], fingerprint: 'a'.repeat(64) }));
        const submitted = result.current.payload;
        act(() => result.current.applySaved({ formats: [{ id: 2, value: 'application/json' }], sizes: [], fingerprint: 'b'.repeat(64) }, submitted));
        expect(result.current.formats.map((row) => row.id)).toEqual([2]);
        expect(result.current.payload.contentMetadataChanged).toBe(false);
    });

    it('keeps local changes when an older save response contains no content metadata', () => {
        const { result } = renderHook(() => useContentMetadata());
        act(() => result.current.setFormats([{ clientId: 'local', value: 'text/csv' }]));
        act(() => result.current.applySaved(undefined, result.current.payload));
        expect(result.current.formats[0].value).toBe('text/csv');
        expect(result.current.payload.contentMetadataChanged).toBe(true);
    });
});
