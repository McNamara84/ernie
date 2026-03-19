import { describe, expect, it } from 'vitest';

import {
    buildDateTime,
    hasValidDateValue,
    normalizeTimeForInput,
    parseDateTime,
    serializeDateEntry,
    TIMEZONE_OPTIONS,
} from '@/lib/date-utils';

describe('TIMEZONE_OPTIONS', () => {
    it('is a non-empty array', () => {
        expect(TIMEZONE_OPTIONS.length).toBeGreaterThan(0);
    });

    it('contains UTC (Z)', () => {
        expect(TIMEZONE_OPTIONS.find((o) => o.value === 'Z')).toBeDefined();
    });

    it('each option has value and label', () => {
        for (const opt of TIMEZONE_OPTIONS) {
            expect(opt.value).toBeTruthy();
            expect(opt.label).toBeTruthy();
        }
    });
});

describe('parseDateTime', () => {
    it('returns empty for null / undefined / empty', () => {
        expect(parseDateTime(null)).toEqual({ date: '', time: null, timezone: null });
        expect(parseDateTime(undefined)).toEqual({ date: '', time: null, timezone: null });
        expect(parseDateTime('')).toEqual({ date: '', time: null, timezone: null });
        expect(parseDateTime('   ')).toEqual({ date: '', time: null, timezone: null });
    });

    it('parses date-only string', () => {
        expect(parseDateTime('2022-10-06')).toEqual({ date: '2022-10-06', time: null, timezone: null });
    });

    it('parses partial year', () => {
        expect(parseDateTime('2022')).toEqual({ date: '2022', time: null, timezone: null });
    });

    it('parses year-month', () => {
        expect(parseDateTime('2022-10')).toEqual({ date: '2022-10', time: null, timezone: null });
    });

    it('parses datetime with timezone offset', () => {
        const result = parseDateTime('2022-10-06T09:35+01:00');
        expect(result.date).toBe('2022-10-06');
        expect(result.time).toBe('09:35');
        expect(result.timezone).toBe('+01:00');
    });

    it('parses datetime with Z timezone', () => {
        const result = parseDateTime('2022-10-06T09:35:00Z');
        expect(result.date).toBe('2022-10-06');
        expect(result.time).toBe('09:35:00');
        expect(result.timezone).toBe('Z');
    });

    it('parses datetime without timezone', () => {
        const result = parseDateTime('2022-10-06T14:30');
        expect(result.date).toBe('2022-10-06');
        expect(result.time).toBe('14:30');
        expect(result.timezone).toBeNull();
    });

    it('handles T with empty rest', () => {
        const result = parseDateTime('2022-10-06T');
        expect(result.date).toBe('2022-10-06');
        expect(result.time).toBeNull();
    });

    it('parses datetime with seconds and fractional', () => {
        const result = parseDateTime('2022-10-06T09:35:00.123+02:00');
        expect(result.date).toBe('2022-10-06');
        expect(result.time).toBe('09:35:00.123');
        expect(result.timezone).toBe('+02:00');
    });

    it('normalizes compact timezone format', () => {
        const result = parseDateTime('2022-10-06T09:35+0100');
        expect(result.timezone).toBe('+01:00');
    });
});

describe('buildDateTime', () => {
    it('returns empty for empty date', () => {
        expect(buildDateTime('')).toBe('');
        expect(buildDateTime('  ')).toBe('');
    });

    it('returns date-only when no time', () => {
        expect(buildDateTime('2022-10-06')).toBe('2022-10-06');
    });

    it('appends time to full date', () => {
        expect(buildDateTime('2022-10-06', '09:35')).toBe('2022-10-06T09:35');
    });

    it('appends time and timezone', () => {
        expect(buildDateTime('2022-10-06', '09:35', '+01:00')).toBe('2022-10-06T09:35+01:00');
    });

    it('ignores time/timezone for partial dates', () => {
        expect(buildDateTime('2022', '09:35', '+01:00')).toBe('2022');
        expect(buildDateTime('2022-10', '09:35', '+01:00')).toBe('2022-10');
    });

    it('ignores empty time', () => {
        expect(buildDateTime('2022-10-06', '', '+01:00')).toBe('2022-10-06');
        expect(buildDateTime('2022-10-06', null, '+01:00')).toBe('2022-10-06');
    });

    it('ignores empty timezone', () => {
        expect(buildDateTime('2022-10-06', '09:35', '')).toBe('2022-10-06T09:35');
        expect(buildDateTime('2022-10-06', '09:35', null)).toBe('2022-10-06T09:35');
    });
});

describe('hasValidDateValue', () => {
    it('returns true with startDate', () => {
        expect(hasValidDateValue({ startDate: '2024-01-01', endDate: '' })).toBe(true);
    });

    it('returns true with endDate', () => {
        expect(hasValidDateValue({ startDate: '', endDate: '2024-12-31' })).toBe(true);
    });

    it('returns true with both dates', () => {
        expect(hasValidDateValue({ startDate: '2024-01-01', endDate: '2024-12-31' })).toBe(true);
    });

    it('returns false with empty dates', () => {
        expect(hasValidDateValue({ startDate: '', endDate: '' })).toBe(false);
    });

    it('returns false with null dates', () => {
        expect(hasValidDateValue({ startDate: null, endDate: null })).toBe(false);
    });

    it('returns false with whitespace-only dates', () => {
        expect(hasValidDateValue({ startDate: '  ', endDate: '  ' })).toBe(false);
    });
});

describe('serializeDateEntry', () => {
    it('returns startDate only', () => {
        expect(serializeDateEntry({ startDate: '2024-01-01', endDate: '' })).toBe('2024-01-01');
    });

    it('returns range format', () => {
        expect(serializeDateEntry({ startDate: '2023-01-01', endDate: '2023-12-31' })).toBe('2023-01-01/2023-12-31');
    });

    it('returns open range with endDate only', () => {
        expect(serializeDateEntry({ startDate: '', endDate: '2024-12-31' })).toBe('/2024-12-31');
    });

    it('returns empty when no dates', () => {
        expect(serializeDateEntry({ startDate: '', endDate: '' })).toBe('');
    });

    it('handles null values', () => {
        expect(serializeDateEntry({ startDate: null, endDate: null })).toBe('');
    });
});

describe('normalizeTimeForInput', () => {
    it('returns empty for null', () => {
        expect(normalizeTimeForInput(null)).toBe('');
    });

    it('strips fractional seconds', () => {
        expect(normalizeTimeForInput('09:35:00.123')).toBe('09:35:00');
    });

    it('preserves time without fractional', () => {
        expect(normalizeTimeForInput('09:35:00')).toBe('09:35:00');
        expect(normalizeTimeForInput('09:35')).toBe('09:35');
    });
});
