import { describe, expect, it } from 'vitest';

import {
    contentMetadataErrors,
    contentMetadataSnapshot,
    formatPayload,
    normalizedSizeNumber,
    normalizedSizeUnit,
    remapContentMetadataErrors,
    sizePayload,
} from '@/lib/content-metadata';

describe('resource content metadata', () => {
    it('maps compacted server errors to their current UI rows and preserves unrelated errors', () => {
        expect(
            remapContentMetadataErrors(
                {
                    'formats.0.value': ['Invalid MIME type'],
                    'sizes.0.unit': ['Invalid unit'],
                    doi: ['DOI error'],
                },
                { formats: ['format-value'], sizes: ['size-value'] },
                {
                    formats: [
                        { clientId: 'empty-format', value: '' },
                        { clientId: 'format-value', value: 'text/csv' },
                    ],
                    sizes: [
                        { clientId: 'empty-size', numeric_value: '', unit: '', type: null },
                        { clientId: 'size-value', numeric_value: '2', unit: 'MB', type: null },
                    ],
                },
            ),
        ).toEqual({ 'formats.1.value': ['Invalid MIME type'], 'sizes.1.unit': ['Invalid unit'], doi: ['DOI error'] });
    });

    it('keeps errors for rows deleted in flight at section level instead of highlighting another row', () => {
        expect(
            remapContentMetadataErrors(
                {
                    formats: ['Collection error'],
                    'formats.0.value': ['Removed row error'],
                    'formats.1.value': ['Remaining row error'],
                    'sizes.99.unit': ['Unmapped size error'],
                },
                { formats: ['removed', 'remaining'], sizes: [] },
                {
                    formats: [{ clientId: 'remaining', value: 'text/csv' }],
                    sizes: [],
                },
            ),
        ).toEqual({
            formats: ['Collection error', 'Removed row error'],
            'formats.0.value': ['Remaining row error'],
            sizes: ['Unmapped size error'],
        });
    });

    it('keeps client validation indexes when earlier new size placeholders are empty', () => {
        expect(
            contentMetadataErrors([
                { numeric_value: '', unit: '', type: null },
                { numeric_value: '-2', unit: 'MB', type: null },
            ]),
        ).toEqual({ 'sizes.1.numeric_value': expect.any(String) });
    });

    it.each([
        ['1,5', '1.5'],
        ['001.5000', '1.5'],
        ['9007199254740993', '9007199254740993'],
        ['0.0001', '0.0001'],
    ])('normalizes %s without floating point loss', (value, expected) => {
        expect(normalizedSizeNumber(value)).toBe(expected);
    });

    it('ignores empty placeholders and storage identities when comparing editable content', () => {
        expect(
            contentMetadataSnapshot([{ id: 3, value: 'text/csv' }], [{ id: 4, numeric_value: '1.5000', unit: 'bytes', type: 'Primary Data Size' }]),
        ).toBe(contentMetadataSnapshot([{ value: 'text/csv' }, { value: '' }], [{ numeric_value: '1,5', unit: 'B', type: 'Primary Data Size' }]));
        expect(formatPayload([{ value: '' }])).toEqual([]);
        expect(sizePayload([{ numeric_value: '', unit: '', type: null }])).toEqual([]);
    });

    it.each(['-1', '0', '1e3', '1.23456', '10000000000000000', '1,234.5'])('rejects invalid manual sizes: %s', (value) => {
        expect(contentMetadataErrors([{ numeric_value: value, unit: 'MB', type: null }])).toHaveProperty('sizes.0.numeric_value');
    });

    it('retains preserved non-digital values and validates partial digital rows', () => {
        expect(contentMetadataErrors([{ id: 1, numeric_value: '15.0000', unit: 'pages', type: null, editable: false }])).toEqual({});
        expect(contentMetadataErrors([{ numeric_value: '2', unit: null, type: null }])).toHaveProperty('sizes.0.unit');
        expect(normalizedSizeUnit('KB')).toBe('kB');
        expect(sizePayload([{ id: 1, numeric_value: '15.0000', unit: 'pages', type: null, editable: false }])).toEqual([
            { id: 1, numeric_value: '15.0000', unit: 'pages', type: null },
        ]);
    });

    it('preserves blank stored formats while distinguishing missing numbers from empty new size rows', () => {
        expect(formatPayload([{ id: 3, value: '' }, { value: '' }])).toEqual([{ id: 3, value: '' }]);
        expect(contentMetadataErrors([{ numeric_value: null, unit: 'B', type: null }])).toHaveProperty('sizes.0.numeric_value');
        expect(normalizedSizeNumber(null)).toBe('');
        expect(normalizedSizeUnit('byte')).toBe('B');
    });
});
