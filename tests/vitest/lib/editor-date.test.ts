import { describe, expect, it } from 'vitest';

import {
    createEditorCalendarDate,
    editorDateToCalendarDate,
    formatEditorDate,
    isEditorDateRangeReversed,
    parseEditorDate,
    requireEditorDateIso,
    resolveEditorDateLocale,
    todayLocalIso,
    validateEditorDate,
} from '@/lib/editor-date';

const today = new Date(2026, 8, 24);

describe('editor dates', () => {
    it.each(['0001', '0001-01', '0001-01-01', '0099-12-31', '1661', '1861', '1899-12-31'])(
        'accepts historical editor date %s without changing its precision',
        (value) => {
            expect(validateEditorDate(value, 'iso', today)).toMatchObject({ parsed: { iso: value }, error: null });
            expect(requireEditorDateIso(value, 'iso')).toBe(value);
        },
    );

    it('retains the actual calendar year for dates before 0100', () => {
        const date = editorDateToCalendarDate('0004-02-29', 'iso');
        expect(date?.getFullYear()).toBe(4);
        expect(date?.getMonth()).toBe(1);
        expect(date?.getDate()).toBe(29);
    });

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

    it('enforces today only when future dates are disallowed, without expanding partial dates', () => {
        expect(validateEditorDate('1900-01-01', 'iso', today).error).toBeNull();
        expect(validateEditorDate('1900', 'iso', today).error).toBeNull();
        expect(validateEditorDate('1899-12-31', 'iso', today).error).toBeNull();
        expect(validateEditorDate('1899', 'iso', today).error).toBeNull();
        expect(validateEditorDate('2026', 'iso', today).error).toBeNull();
        expect(validateEditorDate('2026-10', 'iso', today).error).toContain('future');
        expect(validateEditorDate('2026-09-25', 'iso', today).error).toContain('future');
        expect(validateEditorDate('2027-01-01', 'iso', today, true).error).toBeNull();
    });

    it.each(['9999', '9999-12', '9999-12-31'])('accepts the upper year boundary at %s when future dates are allowed', (value) => {
        expect(validateEditorDate(value, 'iso', today, true)).toMatchObject({ parsed: { iso: value }, error: null });
        expect(requireEditorDateIso(value, 'iso', true)).toBe(value);
        expect(validateEditorDate(value, 'iso', today).error).toContain('future');
    });

    it.each([
        '0000',
        '0000-01',
        '0000-01-01',
        '-0054',
        '10000',
        '10000-01-01',
        '0001-00',
        '0001-13',
        '0001-02-29',
        '0100-02-29',
        '1700-02-29',
        '9999-02-30',
    ])('rejects out-of-range years and impossible historical calendar date %s', (value) => {
        expect(parseEditorDate(value, 'iso')).toBeNull();
        expect(validateEditorDate(value, 'iso', today, true).error).toContain('valid date');
        expect(() => requireEditorDateIso(value, 'iso', true)).toThrow();
    });

    it.each(['0004-02-29', '0400-02-29', '1600-02-29', '2000-02-29'])('uses the actual Gregorian leap year for %s', (value) => {
        expect(parseEditorDate(value, 'iso')).toMatchObject({ iso: value, precision: 'day' });
        const date = editorDateToCalendarDate(value, 'iso');
        expect(todayLocalIso(date)).toBe(value);
    });

    it('preserves four-digit early years in German input and local calendar serialization', () => {
        expect(parseEditorDate('01.01.0001', 'de')?.iso).toBe('0001-01-01');
        expect(parseEditorDate('29.02.0004', 'de')?.iso).toBe('0004-02-29');
        expect(formatEditorDate('0099-12-31', 'de')).toBe('31.12.0099');
        expect(requireEditorDateIso('29.02.0004', 'de')).toBe('0004-02-29');
        expect(todayLocalIso(createEditorCalendarDate(1, 0))).toBe('0001-01-01');
        expect(todayLocalIso(createEditorCalendarDate(9999, 11, 31))).toBe('9999-12-31');
    });

    it('compares historical and far-future periods at their supplied precision', () => {
        expect(isEditorDateRangeReversed('0001', '0001-01-01', 'iso')).toBe(false);
        expect(isEditorDateRangeReversed('0099-12-31', '0001', 'iso')).toBe(true);
        expect(isEditorDateRangeReversed('2100', '2299', 'iso')).toBe(false);
        expect(isEditorDateRangeReversed('9999-12', '9999-12-01', 'iso')).toBe(false);
        expect(isEditorDateRangeReversed('9999-12-31', '9999-11', 'iso')).toBe(true);
        expect(isEditorDateRangeReversed('0001-01-01', '0001-01-01', 'iso', { startTime: '12:00', endTime: '11:00' })).toBe(true);
    });

    it('compares uncertain range endpoints by their possible calendar bounds', () => {
        expect(isEditorDateRangeReversed('2024-03', '2024-03-01', 'iso')).toBe(false);
        expect(isEditorDateRangeReversed('2024-04', '2024-03-31', 'iso')).toBe(true);
        expect(isEditorDateRangeReversed('2024', '2024-01-01', 'iso')).toBe(false);
        expect(isEditorDateRangeReversed('2025', '2024', 'iso')).toBe(true);
    });

    it('compares two timed endpoints as UTC instants, including timezone offsets', () => {
        expect(isEditorDateRangeReversed('2020-09-24', '2020-09-24', 'iso', { startTime: '16:00', endTime: '09:00' })).toBe(true);
        expect(
            isEditorDateRangeReversed('2020-09-24', '2020-09-24', 'iso', {
                startTime: '09:30',
                endTime: '08:00',
                startTimezone: '+02:00',
                endTimezone: 'Z',
            }),
        ).toBe(false);
        expect(
            isEditorDateRangeReversed('2020-09-24', '2020-09-24', 'iso', {
                startTime: '09:30',
                endTime: '08:00',
                startTimezone: 'Z',
                endTimezone: '+02:00',
            }),
        ).toBe(true);
        expect(
            isEditorDateRangeReversed('2020-09-24', '2020-09-23', 'iso', {
                startTime: '00:15',
                endTime: '23:30',
                startTimezone: '+02:00',
                endTimezone: 'Z',
            }),
        ).toBe(false);
    });

    it('uses calendar bounds when only one endpoint has a time', () => {
        expect(isEditorDateRangeReversed('2020', '2020-01-01', 'iso', { endTime: '00:01', endTimezone: 'Z' })).toBe(false);
        expect(isEditorDateRangeReversed('2020-09-24', '2020-09-24', 'iso', { startTime: '16:00' })).toBe(false);
        expect(isEditorDateRangeReversed('2020-09-25', '2020-09-24', 'iso', { startTime: '00:01', endTime: null })).toBe(true);
    });

    it('converts calendar dates without UTC timezone shifts', () => {
        const date = editorDateToCalendarDate('2020-09-24', 'iso');
        expect(date?.getFullYear()).toBe(2020);
        expect(date?.getMonth()).toBe(8);
        expect(date?.getDate()).toBe(24);
        expect(editorDateToCalendarDate('2020-09', 'iso')?.getDate()).toBe(1);
        expect(editorDateToCalendarDate(null, 'iso')).toBeUndefined();
        expect(editorDateToCalendarDate('', 'iso')).toBeUndefined();
        expect(editorDateToCalendarDate('2020-02-30', 'iso')).toBeUndefined();
    });

    it('leaves invalid endpoints to field validation and uses calendar bounds for invalid times', () => {
        expect(isEditorDateRangeReversed('invalid', '2020', 'iso')).toBe(false);
        expect(isEditorDateRangeReversed('2020', 'invalid', 'iso')).toBe(false);
        expect(isEditorDateRangeReversed('2020-09-24', '2020-09-24', 'iso', { startTime: '99:00', endTime: '01:00' })).toBe(false);
    });

    it('normalizes the API value and rejects a localized value in the wrong locale', () => {
        expect(requireEditorDateIso('24.09.2020', 'de')).toBe('2020-09-24');
        expect(requireEditorDateIso('2020-09', 'de')).toBe('2020-09');
        expect(requireEditorDateIso('2027-01-01', 'iso', true)).toBe('2027-01-01');
        expect(requireEditorDateIso('', 'iso')).toBe('');
        expect(() => requireEditorDateIso('24.09.2020', 'iso')).toThrow();
    });
});
