import { describe, expect, it } from 'vitest';

import { hasValidDateValue, serializeDateEntry } from '@/lib/date-utils';

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
});
