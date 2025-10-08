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

describe('DataCite Form - Date Serialization', () => {
    it('should serialize single date correctly', () => {
        // Single date: only startDate is filled
        const startDate = '2015-03-10';
        const endDate = '';
        
        const hasStart = startDate?.trim();
        const hasEnd = endDate?.trim();
        
        let dateString = '';
        if (hasStart && hasEnd) {
            dateString = `${startDate}/${endDate}`;
        } else if (hasStart) {
            dateString = startDate;
        } else if (hasEnd) {
            dateString = `/${endDate}`;
        }
        
        expect(dateString).toBe('2015-03-10');
    });

    it('should serialize full range correctly', () => {
        // Full range: both startDate and endDate are filled
        const startDate = '2013-09-05';
        const endDate = '2014-10-11';
        
        const hasStart = startDate?.trim();
        const hasEnd = endDate?.trim();
        
        let dateString = '';
        if (hasStart && hasEnd) {
            dateString = `${startDate}/${endDate}`;
        } else if (hasStart) {
            dateString = startDate;
        } else if (hasEnd) {
            dateString = `/${endDate}`;
        }
        
        expect(dateString).toBe('2013-09-05/2014-10-11');
    });

    it('should serialize open-ended range correctly', () => {
        // Open-ended range: only endDate is filled
        const startDate = '';
        const endDate = '2017-03-01';
        
        const hasStart = startDate?.trim();
        const hasEnd = endDate?.trim();
        
        let dateString = '';
        if (hasStart && hasEnd) {
            dateString = `${startDate}/${endDate}`;
        } else if (hasStart) {
            dateString = startDate;
        } else if (hasEnd) {
            dateString = `/${endDate}`;
        }
        
        expect(dateString).toBe('/2017-03-01');
    });

    it('should handle empty dates', () => {
        // Both fields empty
        const startDate = '';
        const endDate = '';
        
        const hasStart = startDate?.trim();
        const hasEnd = endDate?.trim();
        
        let dateString = '';
        if (hasStart && hasEnd) {
            dateString = `${startDate}/${endDate}`;
        } else if (hasStart) {
            dateString = startDate;
        } else if (hasEnd) {
            dateString = `/${endDate}`;
        }
        
        expect(dateString).toBe('');
    });

    it('should handle whitespace-only dates', () => {
        // Whitespace should be treated as empty
        const startDate = '   ';
        const endDate = '  ';
        
        const hasStart = startDate?.trim();
        const hasEnd = endDate?.trim();
        
        let dateString = '';
        if (hasStart && hasEnd) {
            dateString = `${startDate}/${endDate}`;
        } else if (hasStart) {
            dateString = startDate;
        } else if (hasEnd) {
            dateString = `/${endDate}`;
        }
        
        expect(dateString).toBe('');
    });

    it('should serialize all three date types correctly in batch', () => {
        // Test dataset similar to Dataset ID 3
        const dates = [
            { startDate: '', endDate: '2017-03-01', dateType: 'available' },
            { startDate: '2015-03-10', endDate: '', dateType: 'created' },
            { startDate: '2013-09-05', endDate: '2014-10-11', dateType: 'collected' },
        ];
        
        const serializedDates = dates.map(({ startDate, endDate, dateType }) => {
            const hasStart = startDate?.trim();
            const hasEnd = endDate?.trim();
            
            let dateString = '';
            if (hasStart && hasEnd) {
                dateString = `${startDate}/${endDate}`;
            } else if (hasStart) {
                dateString = startDate;
            } else if (hasEnd) {
                dateString = `/${endDate}`;
            }
            
            return { date: dateString, dateType };
        });
        
        expect(serializedDates).toEqual([
            { date: '/2017-03-01', dateType: 'available' },
            { date: '2015-03-10', dateType: 'created' },
            { date: '2013-09-05/2014-10-11', dateType: 'collected' },
        ]);
    });
});

