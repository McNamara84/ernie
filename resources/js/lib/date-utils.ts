/**
 * Utility functions for handling DataCite date formats.
 *
 * DataCite supports three date format types:
 * - Single date: "2015-03-10" (only startDate)
 * - Full range: "2013-09-05/2014-10-11" (both startDate and endDate)
 * - Open range: "/2017-03-01" (only endDate)
 *
 * Additionally supports ISO 8601 datetime with timezone:
 * - "2022-10-06T09:35+01:00"
 * - "2022-10-06T09:35:00Z"
 *
 * @see https://github.com/McNamara84/ernie/issues/508
 */

/**
 * Minimal date entry interface for utility functions.
 * Components may extend this with additional fields (e.g., id).
 */
export interface DateEntry {
    dateType: string;
    startDate: string | null;
    endDate: string | null;
}

/**
 * Parsed components of an ISO 8601 datetime string.
 */
export interface ParsedDateTime {
    date: string; // 'YYYY-MM-DD'
    time: string | null; // 'HH:mm', 'HH:mm:ss', or 'HH:mm:ss.fff' (full precision preserved)
    timezone: string | null; // '+01:00', '-05:00', 'Z', etc.
}

/**
 * Common UTC timezone offsets for dropdown selection.
 */
export const TIMEZONE_OPTIONS: { value: string; label: string }[] = [
    { value: '-12:00', label: 'UTC-12:00' },
    { value: '-11:00', label: 'UTC-11:00' },
    { value: '-10:00', label: 'UTC-10:00' },
    { value: '-09:30', label: 'UTC-09:30' },
    { value: '-09:00', label: 'UTC-09:00' },
    { value: '-08:00', label: 'UTC-08:00' },
    { value: '-07:00', label: 'UTC-07:00' },
    { value: '-06:00', label: 'UTC-06:00' },
    { value: '-05:00', label: 'UTC-05:00' },
    { value: '-04:00', label: 'UTC-04:00' },
    { value: '-03:30', label: 'UTC-03:30' },
    { value: '-03:00', label: 'UTC-03:00' },
    { value: '-02:00', label: 'UTC-02:00' },
    { value: '-01:00', label: 'UTC-01:00' },
    { value: 'Z', label: 'UTC' },
    { value: '+01:00', label: 'UTC+01:00' },
    { value: '+02:00', label: 'UTC+02:00' },
    { value: '+03:00', label: 'UTC+03:00' },
    { value: '+03:30', label: 'UTC+03:30' },
    { value: '+04:00', label: 'UTC+04:00' },
    { value: '+04:30', label: 'UTC+04:30' },
    { value: '+05:00', label: 'UTC+05:00' },
    { value: '+05:30', label: 'UTC+05:30' },
    { value: '+05:45', label: 'UTC+05:45' },
    { value: '+06:00', label: 'UTC+06:00' },
    { value: '+06:30', label: 'UTC+06:30' },
    { value: '+07:00', label: 'UTC+07:00' },
    { value: '+08:00', label: 'UTC+08:00' },
    { value: '+08:45', label: 'UTC+08:45' },
    { value: '+09:00', label: 'UTC+09:00' },
    { value: '+09:30', label: 'UTC+09:30' },
    { value: '+10:00', label: 'UTC+10:00' },
    { value: '+10:30', label: 'UTC+10:30' },
    { value: '+11:00', label: 'UTC+11:00' },
    { value: '+12:00', label: 'UTC+12:00' },
    { value: '+12:45', label: 'UTC+12:45' },
    { value: '+13:00', label: 'UTC+13:00' },
    { value: '+14:00', label: 'UTC+14:00' },
];

/**
 * Parse an ISO 8601 datetime string into its components.
 *
 * @param isoString - ISO 8601 string (e.g., "2022-10-06", "2022-10-06T09:35+01:00")
 * @returns Parsed date, time, and timezone components
 *
 * @example
 * parseDateTime('2022-10-06T09:35+01:00')
 * // { date: '2022-10-06', time: '09:35', timezone: '+01:00' }
 *
 * parseDateTime('2022-10-06')
 * // { date: '2022-10-06', time: null, timezone: null }
 */
export function parseDateTime(isoString: string | null | undefined): ParsedDateTime {
    if (!isoString || isoString.trim() === '') {
        return { date: '', time: null, timezone: null };
    }

    const str = isoString.trim();

    // No time component — date only
    if (!str.includes('T')) {
        return { date: str, time: null, timezone: null };
    }

    const [datePart, rest] = str.split('T', 2);

    if (!rest) {
        return { date: datePart, time: null, timezone: null };
    }

    // Extract timezone from the rest (after T)
    let timePart = rest;
    let timezone: string | null = null;

    // Check for Z at end
    if (timePart.endsWith('Z')) {
        timezone = 'Z';
        timePart = timePart.slice(0, -1);
    } else {
        // Check for +HH:MM or -HH:MM offset
        const tzMatch = timePart.match(/([+-]\d{2}:?\d{2})$/);
        if (tzMatch) {
            timezone = tzMatch[1];
            // Normalize to HH:MM format
            if (timezone.length === 5 && !timezone.includes(':')) {
                timezone = timezone.slice(0, 3) + ':' + timezone.slice(3);
            }
            timePart = timePart.slice(0, -tzMatch[1].length);
        }
    }

    // Preserve full time precision (including seconds and fractional seconds)
    // The <input type="time"> will handle display truncation to HH:mm natively
    const time = timePart || null;

    return { date: datePart, time, timezone };
}

/**
 * Build an ISO 8601 datetime string from date, time, and timezone components.
 *
 * @param date - Date string (YYYY-MM-DD)
 * @param time - Optional time string (HH:mm or HH:mm:ss)
 * @param timezone - Optional timezone offset (e.g., '+01:00', 'Z')
 * @returns Combined ISO 8601 string
 *
 * @example
 * buildDateTime('2022-10-06', '09:35', '+01:00')
 * // '2022-10-06T09:35+01:00'
 *
 * buildDateTime('2022-10-06')
 * // '2022-10-06'
 */
export function buildDateTime(date: string, time?: string | null, timezone?: string | null): string {
    if (!date || date.trim() === '') {
        return '';
    }

    let result = date.trim();

    if (time && time.trim() !== '') {
        result += `T${time.trim()}`;

        if (timezone && timezone.trim() !== '') {
            result += timezone.trim();
        }
    }

    return result;
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
    const startDate = date.startDate ?? '';
    const endDate = date.endDate ?? '';
    return startDate.trim() !== '' || endDate.trim() !== '';
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
