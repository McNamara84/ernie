import { describe, expect, it } from 'vitest';

import {
    contentMetadataErrors,
    contentMetadataSnapshot,
    formatPayload,
    normalizedSizeNumber,
    normalizedSizeUnit,
    sizePayload,
} from '@/lib/content-metadata';

describe('resource content metadata', () => {
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
