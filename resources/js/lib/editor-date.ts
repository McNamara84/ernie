import { buildDateTime } from '@/lib/date-utils';

/** Calendar dates in the editor use ISO values, even when an input is localized. */
export type EditorDateLocale = 'de' | 'iso';
export type EditorDatePrecision = 'year' | 'month' | 'day';

export interface ParsedEditorDate {
    iso: string;
    precision: EditorDatePrecision;
    earliest: string;
    latest: string;
}

export const EDITOR_MIN_DATE = '1900-01-01';

export function resolveEditorDateLocale(language: string | undefined): EditorDateLocale {
    return language?.toLowerCase().split('-')[0] === 'de' ? 'de' : 'iso';
}

function pad2(value: number): string {
    return String(value).padStart(2, '0');
}

export function todayLocalIso(today: Date = new Date()): string {
    return `${today.getFullYear()}-${pad2(today.getMonth() + 1)}-${pad2(today.getDate())}`;
}

function lastDayOfMonth(year: number, month: number): number {
    return new Date(year, month, 0).getDate();
}

/** Parse only supported forms; never let JavaScript correct an impossible date. */
export function parseEditorDate(value: string, locale: EditorDateLocale): ParsedEditorDate | null {
    const input = value.trim();
    const isoMatch = /^(\d{4})(?:-(\d{2})(?:-(\d{2}))?)?$/.exec(input);
    const germanMatch = locale === 'de' ? /^(\d{2})\.(\d{2})\.(\d{4})$/.exec(input) : null;
    if (!isoMatch && !germanMatch) return null;

    const year = Number(isoMatch?.[1] ?? germanMatch?.[3]);
    const monthText = isoMatch?.[2] ?? germanMatch?.[2];
    const dayText = isoMatch?.[3] ?? germanMatch?.[1];
    if (year < 1 || year > 9999) return null;

    if (!monthText) {
        return { iso: input, precision: 'year', earliest: `${input}-01-01`, latest: `${input}-12-31` };
    }

    const month = Number(monthText);
    if (month < 1 || month > 12) return null;
    const yearMonth = `${String(year).padStart(4, '0')}-${pad2(month)}`;
    if (!dayText) {
        return {
            iso: yearMonth,
            precision: 'month',
            earliest: `${yearMonth}-01`,
            latest: `${yearMonth}-${pad2(lastDayOfMonth(year, month))}`,
        };
    }

    const day = Number(dayText);
    if (day < 1 || day > lastDayOfMonth(year, month)) return null;
    const iso = `${yearMonth}-${pad2(day)}`;
    return { iso, precision: 'day', earliest: iso, latest: iso };
}

export function validateEditorDate(
    value: string,
    locale: EditorDateLocale,
    today: Date = new Date(),
): { parsed: ParsedEditorDate | null; error: string | null } {
    const parsed = parseEditorDate(value, locale);
    if (!parsed) {
        return {
            parsed: null,
            error:
                locale === 'de'
                    ? 'Enter a valid date (YYYY, YYYY-MM, YYYY-MM-DD, or DD.MM.YYYY).'
                    : 'Enter a valid date (YYYY, YYYY-MM, or YYYY-MM-DD).',
        };
    }
    if (parsed.earliest < EDITOR_MIN_DATE) return { parsed, error: 'Date must be on or after 1900-01-01.' };
    if (parsed.earliest > todayLocalIso(today)) return { parsed, error: 'Date cannot be in the future.' };
    return { parsed, error: null };
}

export function formatEditorDate(value: string | null, locale: EditorDateLocale): string {
    if (!value) return '';
    const parsed = parseEditorDate(value, locale);
    if (locale !== 'de' || parsed?.precision !== 'day') return value;
    const [year, month, day] = parsed.iso.split('-');
    return `${day}.${month}.${year}`;
}

export function editorDateToCalendarDate(value: string | null, locale: EditorDateLocale): Date | undefined {
    if (!value) return undefined;
    const parsed = parseEditorDate(value, locale);
    if (!parsed) return undefined;
    const [year, month, day] = parsed.earliest.split('-').map(Number);
    return new Date(year, month - 1, day);
}

export function isEditorDateRangeReversed(
    start: string,
    end: string,
    locale: EditorDateLocale,
    times?: { startTime?: string | null; endTime?: string | null; startTimezone?: string | null; endTimezone?: string | null },
): boolean {
    const parsedStart = parseEditorDate(start, locale);
    const parsedEnd = parseEditorDate(end, locale);
    if (!parsedStart || !parsedEnd) return false;

    if (parsedStart.precision === 'day' && parsedEnd.precision === 'day' && times?.startTime?.trim() && times.endTime?.trim()) {
        // PHP uses the application timezone (UTC) when an endpoint has no explicit offset.
        const startInstant = Date.parse(buildDateTime(parsedStart.iso, times.startTime, times.startTimezone?.trim() || 'Z'));
        const endInstant = Date.parse(buildDateTime(parsedEnd.iso, times.endTime, times.endTimezone?.trim() || 'Z'));
        if (Number.isFinite(startInstant) && Number.isFinite(endInstant)) return startInstant > endInstant;
    }

    // If either endpoint has no time, match the server's conservative calendar bounds.
    return parsedStart.earliest > parsedEnd.latest;
}

/** Keep localized or invalid raw text out of every API payload. */
export function requireEditorDateIso(value: string, locale: EditorDateLocale): string {
    if (!value.trim()) return '';
    const result = validateEditorDate(value, locale);
    if (result.error || !result.parsed) throw new Error(result.error ?? 'Invalid editor date.');
    return result.parsed.iso;
}
