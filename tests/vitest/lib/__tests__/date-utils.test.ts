import { describe, expect, it } from 'vitest';

import { buildDateTime, hasValidDateValue, normalizeTimeForInput, parseDateTime, serializeDateEntry } from '@/lib/date-utils';

describe('date-utils', () => {
    describe('hasValidDateValue', () => {
        it('returns true when startDate has a value', () => {
            expect(hasValidDateValue({ startDate: '2024-01-01', endDate: '' })).toBe(true);
            expect(hasValidDateValue({ startDate: '2024-01-01', endDate: null })).toBe(true);
        });

        it('returns true when endDate has a value', () => {
            expect(hasValidDateValue({ startDate: '', endDate: '2024-12-31' })).toBe(true);
            expect(hasValidDateValue({ startDate: null, endDate: '2024-12-31' })).toBe(true);
        });

        it('returns true when both dates have values', () => {
            expect(hasValidDateValue({ startDate: '2024-01-01', endDate: '2024-12-31' })).toBe(true);
        });

        it('returns false when both dates are empty strings', () => {
            expect(hasValidDateValue({ startDate: '', endDate: '' })).toBe(false);
        });

        it('returns false when both dates are null', () => {
            expect(hasValidDateValue({ startDate: null, endDate: null })).toBe(false);
        });

        it('returns false when dates are whitespace only', () => {
            expect(hasValidDateValue({ startDate: '   ', endDate: '   ' })).toBe(false);
            expect(hasValidDateValue({ startDate: '\t', endDate: '\n' })).toBe(false);
        });

        it('handles mixed null and empty string values', () => {
            expect(hasValidDateValue({ startDate: null, endDate: '' })).toBe(false);
            expect(hasValidDateValue({ startDate: '', endDate: null })).toBe(false);
        });
    });

    describe('serializeDateEntry', () => {
        it('returns start date when only startDate has value', () => {
            expect(serializeDateEntry({ startDate: '2024-01-01', endDate: '' })).toBe('2024-01-01');
            expect(serializeDateEntry({ startDate: '2024-01-01', endDate: null })).toBe('2024-01-01');
        });

        it('returns "/endDate" when only endDate has value (open range)', () => {
            expect(serializeDateEntry({ startDate: '', endDate: '2024-12-31' })).toBe('/2024-12-31');
            expect(serializeDateEntry({ startDate: null, endDate: '2024-12-31' })).toBe('/2024-12-31');
        });

        it('returns "start/end" when both dates have values', () => {
            expect(serializeDateEntry({ startDate: '2023-01-01', endDate: '2023-12-31' })).toBe('2023-01-01/2023-12-31');
        });

        it('returns empty string when both dates are empty', () => {
            expect(serializeDateEntry({ startDate: '', endDate: '' })).toBe('');
            expect(serializeDateEntry({ startDate: null, endDate: null })).toBe('');
        });

        it('trims whitespace from dates', () => {
            expect(serializeDateEntry({ startDate: '  2024-01-01  ', endDate: '' })).toBe('2024-01-01');
            expect(serializeDateEntry({ startDate: '', endDate: '  2024-12-31  ' })).toBe('/2024-12-31');
            expect(serializeDateEntry({ startDate: '  2023-01-01  ', endDate: '  2023-12-31  ' })).toBe('2023-01-01/2023-12-31');
        });

        it('handles whitespace-only dates as empty', () => {
            expect(serializeDateEntry({ startDate: '   ', endDate: '   ' })).toBe('');
            expect(serializeDateEntry({ startDate: '   ', endDate: '2024-12-31' })).toBe('/2024-12-31');
            expect(serializeDateEntry({ startDate: '2024-01-01', endDate: '   ' })).toBe('2024-01-01');
        });

        it('handles various date formats', () => {
            // Year only
            expect(serializeDateEntry({ startDate: '2024', endDate: '' })).toBe('2024');
            // Year-month
            expect(serializeDateEntry({ startDate: '2024-06', endDate: '' })).toBe('2024-06');
            // Full ISO date
            expect(serializeDateEntry({ startDate: '2024-06-15', endDate: '' })).toBe('2024-06-15');
        });
    });

    describe('parseDateTime', () => {
        it('parses date-only string', () => {
            expect(parseDateTime('2022-10-06')).toEqual({
                date: '2022-10-06',
                time: null,
                timezone: null,
            });
        });

        it('parses datetime with positive timezone offset', () => {
            expect(parseDateTime('2022-10-06T09:35+01:00')).toEqual({
                date: '2022-10-06',
                time: '09:35',
                timezone: '+01:00',
            });
        });

        it('parses datetime with negative timezone offset', () => {
            expect(parseDateTime('2022-10-06T14:30-05:00')).toEqual({
                date: '2022-10-06',
                time: '14:30',
                timezone: '-05:00',
            });
        });

        it('parses datetime with Z timezone', () => {
            expect(parseDateTime('2022-10-06T09:35Z')).toEqual({
                date: '2022-10-06',
                time: '09:35',
                timezone: 'Z',
            });
        });

        it('parses datetime with seconds', () => {
            expect(parseDateTime('2022-10-06T09:35:00+01:00')).toEqual({
                date: '2022-10-06',
                time: '09:35:00',
                timezone: '+01:00',
            });
        });

        it('parses datetime with fractional seconds', () => {
            expect(parseDateTime('2022-10-06T09:35:00.000+01:00')).toEqual({
                date: '2022-10-06',
                time: '09:35:00.000',
                timezone: '+01:00',
            });
        });

        it('parses datetime without timezone', () => {
            expect(parseDateTime('2022-10-06T09:35')).toEqual({
                date: '2022-10-06',
                time: '09:35',
                timezone: null,
            });
        });

        it('returns empty result for null input', () => {
            expect(parseDateTime(null)).toEqual({
                date: '',
                time: null,
                timezone: null,
            });
        });

        it('returns empty result for undefined input', () => {
            expect(parseDateTime(undefined)).toEqual({
                date: '',
                time: null,
                timezone: null,
            });
        });

        it('returns empty result for empty string', () => {
            expect(parseDateTime('')).toEqual({
                date: '',
                time: null,
                timezone: null,
            });
        });

        it('normalizes compact timezone offset to colon format', () => {
            expect(parseDateTime('2022-10-06T09:35+0100')).toEqual({
                date: '2022-10-06',
                time: '09:35',
                timezone: '+01:00',
            });
        });

        it('handles year-only string (no T separator)', () => {
            expect(parseDateTime('2022')).toEqual({
                date: '2022',
                time: null,
                timezone: null,
            });
        });

        it('handles year-month string', () => {
            expect(parseDateTime('2022-10')).toEqual({
                date: '2022-10',
                time: null,
                timezone: null,
            });
        });
    });

    describe('buildDateTime', () => {
        it('returns date only when no time or timezone', () => {
            expect(buildDateTime('2022-10-06')).toBe('2022-10-06');
        });

        it('appends time when provided', () => {
            expect(buildDateTime('2022-10-06', '09:35')).toBe('2022-10-06T09:35');
        });

        it('appends time and timezone', () => {
            expect(buildDateTime('2022-10-06', '09:35', '+01:00')).toBe('2022-10-06T09:35+01:00');
        });

        it('appends Z timezone', () => {
            expect(buildDateTime('2022-10-06', '09:35', 'Z')).toBe('2022-10-06T09:35Z');
        });

        it('ignores timezone when no time provided', () => {
            expect(buildDateTime('2022-10-06', null, '+01:00')).toBe('2022-10-06');
        });

        it('ignores empty timezone', () => {
            expect(buildDateTime('2022-10-06', '09:35', '')).toBe('2022-10-06T09:35');
        });

        it('ignores null timezone', () => {
            expect(buildDateTime('2022-10-06', '09:35', null)).toBe('2022-10-06T09:35');
        });

        it('returns empty string for empty date', () => {
            expect(buildDateTime('')).toBe('');
        });

        it('returns empty string for whitespace-only date', () => {
            expect(buildDateTime('   ')).toBe('');
        });

        it('trims whitespace from all components', () => {
            expect(buildDateTime('  2022-10-06  ', '  09:35  ', '  +01:00  ')).toBe('2022-10-06T09:35+01:00');
        });

        it('preserves seconds in time component', () => {
            expect(buildDateTime('2022-10-06', '09:35:00', '+01:00')).toBe('2022-10-06T09:35:00+01:00');
        });

        it('preserves fractional seconds in time component', () => {
            expect(buildDateTime('2022-10-06', '09:35:00.000', '+01:00')).toBe('2022-10-06T09:35:00.000+01:00');
        });

        it('round-trips datetime with full precision', () => {
            const original = '2022-10-06T09:35:00.000+01:00';
            const parsed = parseDateTime(original);
            const rebuilt = buildDateTime(parsed.date, parsed.time, parsed.timezone);
            expect(rebuilt).toBe(original);
        });

        it('round-trips datetime with seconds', () => {
            const original = '2022-10-06T09:35:00+01:00';
            const parsed = parseDateTime(original);
            const rebuilt = buildDateTime(parsed.date, parsed.time, parsed.timezone);
            expect(rebuilt).toBe(original);
        });

        it('round-trips datetime without seconds', () => {
            const original = '2022-10-06T09:35+01:00';
            const parsed = parseDateTime(original);
            const rebuilt = buildDateTime(parsed.date, parsed.time, parsed.timezone);
            expect(rebuilt).toBe(original);
        });
    });

    describe('normalizeTimeForInput', () => {
        it('returns empty string for null', () => {
            expect(normalizeTimeForInput(null)).toBe('');
        });

        it('returns empty string for empty string', () => {
            expect(normalizeTimeForInput('')).toBe('');
        });

        it('passes through HH:mm unchanged', () => {
            expect(normalizeTimeForInput('09:35')).toBe('09:35');
        });

        it('passes through HH:mm:ss unchanged', () => {
            expect(normalizeTimeForInput('09:35:00')).toBe('09:35:00');
        });

        it('strips fractional seconds from HH:mm:ss.fff', () => {
            expect(normalizeTimeForInput('09:35:00.000')).toBe('09:35:00');
        });

        it('strips fractional seconds with variable precision', () => {
            expect(normalizeTimeForInput('14:22:33.123456')).toBe('14:22:33');
        });
    });
});
