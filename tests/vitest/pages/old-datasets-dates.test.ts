/**
 * Tests for Date Field Serialization in datacite-form.tsx
 * These tests verify that separate startDate/endDate fields are correctly
 * serialized back to DataCite format when submitting the form.
 * 
 * DataCite date formats:
 * - Single date: "2015-03-10" (only startDate filled)
 * - Full range: "2013-09-05/2014-10-11" (both startDate and endDate filled)
 * - Open range: "/2017-03-01" (only endDate filled)
 */

import { describe, expect, it } from 'vitest';

import { serializeDateEntry, type DateEntry } from '@/lib/date-utils';

describe('DataCite Form - Date Serialization', () => {
    it('should serialize single date correctly', () => {
        // Single date: only startDate is filled
        const dateEntry: DateEntry = {
            dateType: 'Created',
            startDate: '2015-03-10',
            endDate: '',
        };
        
        const dateString = serializeDateEntry(dateEntry);
        
        expect(dateString).toBe('2015-03-10');
    });

    it('should serialize full range correctly', () => {
        // Full range: both startDate and endDate are filled
        const dateEntry: DateEntry = {
            dateType: 'Collected',
            startDate: '2013-09-05',
            endDate: '2014-10-11',
        };
        
        const dateString = serializeDateEntry(dateEntry);
        
        expect(dateString).toBe('2013-09-05/2014-10-11');
    });

    it('should serialize open-ended range correctly', () => {
        // Open-ended range: only endDate is filled
        const dateEntry: DateEntry = {
            dateType: 'Available',
            startDate: '',
            endDate: '2017-03-01',
        };
        
        const dateString = serializeDateEntry(dateEntry);
        
        expect(dateString).toBe('/2017-03-01');
    });

    it('should handle empty dates', () => {
        // Both fields empty
        const dateEntry: DateEntry = {
            dateType: 'Created',
            startDate: '',
            endDate: '',
        };
        
        const dateString = serializeDateEntry(dateEntry);
        
        expect(dateString).toBe('');
    });

    it('should handle whitespace-only dates', () => {
        // Whitespace should be treated as empty
        const dateEntry: DateEntry = {
            dateType: 'Created',
            startDate: '   ',
            endDate: '  ',
        };
        
        const dateString = serializeDateEntry(dateEntry);
        
        expect(dateString).toBe('');
    });

    it('should serialize all three date types correctly in batch', () => {
        // Test dataset similar to Dataset ID 3
        const dates: DateEntry[] = [
            { dateType: 'available', startDate: '', endDate: '2017-03-01' },
            { dateType: 'created', startDate: '2015-03-10', endDate: '' },
            { dateType: 'collected', startDate: '2013-09-05', endDate: '2014-10-11' },
        ];
        
        const serializedDates = dates.map((date) => ({
            date: serializeDateEntry(date),
            dateType: date.dateType,
        }));
        
        expect(serializedDates).toEqual([
            { date: '/2017-03-01', dateType: 'available' },
            { date: '2015-03-10', dateType: 'created' },
            { date: '2013-09-05/2014-10-11', dateType: 'collected' },
        ]);
    });
});
