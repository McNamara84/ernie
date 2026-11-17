import { describe, expect, it } from 'vitest';

import { loadMimeTypes, mimeTypeLabel, mimeTypeOptions } from '@/lib/mime-types';

describe('readable resource formats', () => {
    it.each([
        ['text/csv', 'CSV'],
        ['application/json', 'JSON'],
        ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Excel (XLSX)'],
        ['application/geo+json', 'GeoJSON'],
        ['image/png', 'PNG'],
        ['model/gltf+json', 'glTF (JSON)'],
        ['application/x-netcdf', 'NetCDF'],
        [' TEXT/CSV; charset=UTF-8 ', 'CSV'],
    ])('displays %s as %s without changing the MIME value', (mime, label) => {
        expect(mimeTypeLabel(mime)).toBe(label);
        expect(mimeTypeOptions([mime])[0]).toEqual({ value: mime, label });
    });

    it('humanizes long-tail subtypes and retains free-text legacy values', () => {
        expect(mimeTypeLabel('application/vnd.example.custom-format+json')).toBe('Example Custom Format (JSON)');
        expect(mimeTypeLabel('Custom legacy format')).toBe('Custom legacy format');
        expect(mimeTypeLabel('application/')).toBe('application/');
        expect(mimeTypeLabel('path/to/legacy file')).toBe('path/to/legacy file');
    });

    it('sorts by resource usage, then readable name, then MIME value without mutating the catalog', () => {
        const types = ['application/zip', 'text/xml', 'image/jpeg', 'text/csv', 'application/pdf', 'application/json', 'application/xml'];
        const original = [...types];
        expect(mimeTypeOptions(types, { 'text/csv': 10, 'application/pdf': 3, 'application/json': 3 }).map((option) => option.value)).toEqual([
            'text/csv',
            'application/json',
            'application/pdf',
            'image/jpeg',
            'application/xml',
            'text/xml',
            'application/zip',
        ]);
        expect(types).toEqual(original);
    });

    it('keeps the entire official catalog available under readable names', async () => {
        const types = await loadMimeTypes();
        const options = mimeTypeOptions(types);
        expect(options).toHaveLength(types.length);
        expect(options.every((option) => option.label.trim() !== '')).toBe(true);
        expect(options.find((option) => option.value === 'text/csv')?.label).toBe('CSV');
    });
});
