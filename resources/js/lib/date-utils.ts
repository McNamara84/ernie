/**
 * Utility functions for handling DataCite date formats.
 * 
 * DataCite supports three date format types:
 * - Single date: "2015-03-10" (only startDate)
 * - Full range: "2013-09-05/2014-10-11" (both startDate and endDate)
 * - Open range: "/2017-03-01" (only endDate)
 */

/**
 * Minimal date entry interface for utility functions.
 * Components may extend this with additional fields (e.g., id).
 */
export interface DateEntry {
    dateType: string;
    startDate: string;
    endDate: string;
}

/**
 * Check if a date entry has at least one valid (non-empty) date value.
 * 
 * @param date - The date entry to validate
 * @returns true if startDate or endDate contains a non-empty value
 * 
 * @example
 * hasValidDateValue({ dateType: 'Created', startDate: '2024-01-01', endDate: '' }) // true
 * hasValidDateValue({ dateType: 'Created', startDate: '', endDate: '2024-12-31' }) // true
 * hasValidDateValue({ dateType: 'Created', startDate: '', endDate: '' }) // false
 */
export function hasValidDateValue(date: Pick<DateEntry, 'startDate' | 'endDate'>): boolean {
    return date.startDate.trim() !== '' || date.endDate.trim() !== '';
}

/**
 * Serialize a date entry into DataCite format.
 * 
 * Converts separate startDate/endDate fields into a single date string
 * following DataCite conventions:
 * - If both dates exist: "start/end"
 * - If only start exists: "start"
 * - If only end exists: "/end"
 * 
 * @param date - The date entry to serialize
 * @returns Serialized date string in DataCite format
 * 
 * @example
 * serializeDateEntry({ dateType: 'Created', startDate: '2024-01-01', endDate: '' })
 * // Returns: "2024-01-01"
 * 
 * serializeDateEntry({ dateType: 'Collected', startDate: '2023-01-01', endDate: '2023-12-31' })
 * // Returns: "2023-01-01/2023-12-31"
 * 
 * serializeDateEntry({ dateType: 'Available', startDate: '', endDate: '2024-12-31' })
 * // Returns: "/2024-12-31"
 */
export function serializeDateEntry(date: Pick<DateEntry, 'startDate' | 'endDate'>): string {
    const startDate = date.startDate ?? '';
    const endDate = date.endDate ?? '';
    
    const hasStart = startDate.trim() !== '';
    const hasEnd = endDate.trim() !== '';

    if (hasStart && hasEnd) {
        // Range: "start/end"
        return `${startDate.trim()}/${endDate.trim()}`;
    } else if (hasStart) {
        // Only start: "start"
        return startDate.trim();
    } else if (hasEnd) {
        // Only end: "/end"
        return `/${endDate.trim()}`;
    }

    // Should never happen if hasValidDateValue was checked first
    return '';
}
