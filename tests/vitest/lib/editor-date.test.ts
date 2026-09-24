import { describe, expect, it } from 'vitest';

import {
    editorDateToCalendarDate,
    formatEditorDate,
    isEditorDateRangeReversed,
    parseEditorDate,
    requireEditorDateIso,
    resolveEditorDateLocale,
    validateEditorDate,
} from '@/lib/editor-date';

const today = new Date(2026, 8, 24);

describe('editor dates', () => {
    it('uses German input rules only for German browser languages', () => {
        expect(resolveEditorDateLocale('de-DE')).toBe('de');
        expect(resolveEditorDateLocale('de-CH')).toBe('de');
        expect(resolveEditorDateLocale('en-US')).toBe('iso');
        expect(resolveEditorDateLocale(undefined)).toBe('iso');
    });

    it.each([
        ['2020', 'year', '2020-01-01', '2020-12-31'],
        ['2020-02', 'month', '2020-02-01', '2020-02-29'],
        ['2020-02-29', 'day', '2020-02-29', '2020-02-29'],
    ] as const)('preserves %s precision and bounds', (value, precision, earliest, latest) => {
        expect(parseEditorDate(value, 'iso')).toEqual({ iso: value, precision, earliest, latest });
    });

    it('accepts German full dates and always accepts ISO', () => {
        expect(parseEditorDate('24.09.2020', 'de')?.iso).toBe('2020-09-24');
        expect(parseEditorDate('2020-09-24', 'de')?.iso).toBe('2020-09-24');
        expect(parseEditorDate('24.09.2020', 'iso')).toBeNull();
        expect(formatEditorDate('2020-09-24', 'de')).toBe('24.09.2020');
        expect(formatEditorDate('2020-09', 'de')).toBe('2020-09');
        expect(formatEditorDate('2020-09-24', 'iso')).toBe('2020-09-24');
    });

    it.each(['2020-02-30', '2019-02-29', '2020-00', '2020-13', '2020-1-1', '20.09.20', '09/24/2020'])('rejects invalid or ambiguous %s', (value) => {
        expect(parseEditorDate(value, 'de')).toBeNull();
    });

    it('enforces 1900 and today without expanding partial dates', () => {
        expect(validateEditorDate('1900-01-01', 'iso', today).error).toBeNull();
        expect(validateEditorDate('1900', 'iso', today).error).toBeNull();
        expect(validateEditorDate('1899-12-31', 'iso', today).error).toContain('1900');
        expect(validateEditorDate('1899', 'iso', today).error).toContain('1900');
        expect(validateEditorDate('2026', 'iso', today).error).toBeNull();
        expect(validateEditorDate('2026-10', 'iso', today).error).toContain('future');
        expect(validateEditorDate('2026-09-25', 'iso', today).error).toContain('future');
    });

    it('compares uncertain range endpoints by their possible calendar bounds', () => {
        expect(isEditorDateRangeReversed('2024-03', '2024-03-01', 'iso')).toBe(false);
        expect(isEditorDateRangeReversed('2024-04', '2024-03-31', 'iso')).toBe(true);
        expect(isEditorDateRangeReversed('2024', '2024-01-01', 'iso')).toBe(false);
        expect(isEditorDateRangeReversed('2025', '2024', 'iso')).toBe(true);
    });

    it('converts calendar dates without UTC timezone shifts', () => {
        const date = editorDateToCalendarDate('2020-09-24', 'iso');
        expect(date?.getFullYear()).toBe(2020);
        expect(date?.getMonth()).toBe(8);
        expect(date?.getDate()).toBe(24);
        expect(editorDateToCalendarDate('2020-09', 'iso')?.getDate()).toBe(1);
    });

    it('normalizes the API value and rejects a localized value in the wrong locale', () => {
        expect(requireEditorDateIso('24.09.2020', 'de')).toBe('2020-09-24');
        expect(requireEditorDateIso('2020-09', 'de')).toBe('2020-09');
        expect(requireEditorDateIso('', 'iso')).toBe('');
        expect(() => requireEditorDateIso('24.09.2020', 'iso')).toThrow();
    });
});
