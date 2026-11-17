import { act, renderHook } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { useContentMetadata } from '@/hooks/use-content-metadata';

describe('content metadata save reconciliation', () => {
    it('keeps newly added rows that were not part of the in-flight request', () => {
        const { result } = renderHook(() => useContentMetadata({ formats: [{ id: 1, value: 'text/csv' }], sizes: [], fingerprint: 'a'.repeat(64) }));
        const submitted = result.current.payload;
        act(() => {
            result.current.setFormats((rows) => [...rows, { clientId: 'new-format', value: 'application/json' }]);
            result.current.setSizes([{ clientId: 'new-size', numeric_value: '2', unit: 'MB', type: null }]);
        });
        act(() => result.current.applySaved({ formats: [{ id: 2, value: 'text/csv' }], sizes: [], fingerprint: 'b'.repeat(64) }, submitted));
        expect(result.current.formats).toMatchObject([
            { id: 2, value: 'text/csv' },
            { clientId: 'new-format', value: 'application/json' },
        ]);
        expect(result.current.sizes).toEqual([{ clientId: 'new-size', numeric_value: '2', unit: 'MB', type: null }]);
        expect(result.current.errors).toEqual({});
        expect(result.current.payload.contentMetadataChanged).toBe(true);
    });

    it('maps errors using current rows even when the request handler predates a row removal', () => {
        const { result } = renderHook(() => useContentMetadata());
        act(() =>
            result.current.setFormats([
                { clientId: 'empty', value: '' },
                { clientId: 'submitted', value: 'text/csv' },
            ]),
        );
        const submitted = result.current.payload;
        const mapErrors = result.current.mapServerErrors;
        act(() => result.current.setFormats((rows) => rows.filter((row) => row.clientId !== 'empty')));
        expect(mapErrors({ 'formats.0.value': ['Invalid format'] }, submitted.contentMetadataClientIds)).toEqual({
            'formats.0.value': ['Invalid format'],
        });
    });

    it('does not recreate an equivalent replacement that the user deleted while saving', () => {
        const { result } = renderHook(() => useContentMetadata({ formats: [{ id: 1, value: 'text/csv' }], sizes: [], fingerprint: 'a'.repeat(64) }));
        const submitted = result.current.payload;
        act(() => result.current.setFormats([]));
        act(() => result.current.applySaved({ formats: [{ id: 2, value: 'text/csv' }], sizes: [], fingerprint: 'b'.repeat(64) }, submitted));
        expect(result.current.formats).toEqual([]);
        expect(result.current.payload.contentMetadataChanged).toBe(true);
    });

    it('retains equivalent replacement IDs for formats and protected sizes after an unrelated save', () => {
        const { result } = renderHook(() =>
            useContentMetadata({
                formats: [{ id: 1, value: 'application/x-netcdf' }],
                sizes: [{ id: 2, numeric_value: '2048.0000', unit: 'bytes', type: 'Primary Data Size' }],
                fingerprint: 'a'.repeat(64),
            }),
        );
        const submitted = result.current.payload;
        const clientIds = [result.current.formats[0].clientId, result.current.sizes[0].clientId];
        act(() =>
            result.current.applySaved(
                {
                    formats: [{ id: 3, value: 'application/x-netcdf' }],
                    sizes: [{ id: 4, numeric_value: '2048.0000', unit: 'bytes', type: 'Primary Data Size' }],
                    fingerprint: 'b'.repeat(64),
                },
                submitted,
            ),
        );
        expect(result.current.formats).toEqual([{ id: 3, value: 'application/x-netcdf', clientId: clientIds[0] }]);
        expect(result.current.sizes).toMatchObject([{ id: 4, type: 'Primary Data Size', clientId: clientIds[1] }]);
        expect(result.current.payload.formats).toEqual([{ id: 3, value: 'application/x-netcdf' }]);
        expect(result.current.payload.sizes).toHaveLength(1);
        expect(result.current.payload.contentMetadataChanged).toBe(false);
    });

    it('keeps edits made in flight while rebinding equivalent replacement IDs', () => {
        const { result } = renderHook(() =>
            useContentMetadata({
                formats: [{ id: 1, value: 'text/csv' }],
                sizes: [{ id: 2, numeric_value: '1.0000', unit: 'GB', type: 'Primary Data Size' }],
                fingerprint: 'a'.repeat(64),
            }),
        );
        const submitted = result.current.payload;
        act(() => {
            result.current.setFormats((rows) => rows.map((row) => ({ ...row, value: 'image/png' })));
            result.current.setSizes((rows) => rows.map((row) => ({ ...row, numeric_value: '2' })));
        });
        act(() =>
            result.current.applySaved(
                {
                    formats: [{ id: 3, value: 'text/csv' }],
                    sizes: [{ id: 4, numeric_value: '1.0000', unit: 'GB', type: 'Primary Data Size' }],
                    fingerprint: 'b'.repeat(64),
                },
                submitted,
            ),
        );
        expect(result.current.formats).toMatchObject([{ id: 3, value: 'image/png' }]);
        expect(result.current.sizes).toMatchObject([{ id: 4, numeric_value: '2', type: 'Primary Data Size' }]);
        expect(result.current.payload.contentMetadataChanged).toBe(true);
    });

    it('holds locally edited server-deleted entries as recoverable conflicts without reusing deleted IDs or import evidence', () => {
        const { result } = renderHook(() =>
            useContentMetadata({
                formats: [{ id: 1, value: 'application/x-netcdf', importKey: 'old-format-evidence' }],
                sizes: [{ id: 2, numeric_value: '1.0000', unit: 'GB', type: 'Primary Data Size', importKey: 'old-size-evidence' }],
                fingerprint: 'a'.repeat(64),
            }),
        );
        const submitted = result.current.payload;
        act(() => {
            result.current.setFormats((rows) => rows.map((row) => ({ ...row, value: 'image/png' })));
            result.current.setSizes((rows) => rows.map((row) => ({ ...row, numeric_value: '2' })));
        });
        act(() => result.current.applySaved({ formats: [], sizes: [], fingerprint: 'b'.repeat(64) }, submitted));
        expect(result.current.formats).toMatchObject([{ value: 'image/png', id: undefined, importKey: undefined, removedOnServer: true }]);
        expect(result.current.sizes).toMatchObject([
            { numeric_value: '2', type: 'Primary Data Size', id: undefined, importKey: undefined, removedOnServer: true },
        ]);
        expect(result.current.errors).toHaveProperty('formats.0.value');
        expect(result.current.errors).toHaveProperty('sizes.0.numeric_value');
        expect(result.current.payload.contentMetadataChanged).toBe(true);
        expect(result.current.payload.contentMetadataFingerprint).toBe('b'.repeat(64));
    });

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
