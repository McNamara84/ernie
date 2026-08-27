export type TemporalCoverageMode = 'instant' | 'interval';

const daysInMonth = (year: number, month: number): number => {
    const isLeapYear = year % 400 === 0 || (year % 4 === 0 && year % 100 !== 0);
    return month === 2 ? (isLeapYear ? 29 : 28) : [4, 6, 9, 11].includes(month) ? 30 : 31;
};

export const isValidCoverageDate = (value: string | null | undefined): boolean => {
    if (!value) return true;

    const match = /^(\d{4})(?:-(\d{2})(?:-(\d{2}))?)?$/.exec(value);
    if (!match) return false;

    const month = match[2] ? Number(match[2]) : null;
    const day = match[3] ? Number(match[3]) : null;
    if (month !== null && (month < 1 || month > 12)) return false;
    if (day === null) return true;

    return day >= 1 && day <= daysInMonth(Number(match[1]), month!);
};

const lowerBound = (value: string): string => {
    if (value.length === 4) return `${value}-01-01`;
    if (value.length === 7) return `${value}-01`;
    return value;
};

const upperBound = (value: string): string => {
    if (value.length === 4) return `${value}-12-31`;
    if (value.length === 7) {
        const [year, month] = value.split('-').map(Number);
        const lastDay = daysInMonth(year, month);
        return `${value}-${String(lastDay).padStart(2, '0')}`;
    }
    return value;
};

export const isCoverageRangeReversed = (startDate: string, endDate: string): boolean => {
    if (!startDate || !endDate || !isValidCoverageDate(startDate) || !isValidCoverageDate(endDate)) return false;
    return lowerBound(startDate) > upperBound(endDate);
};

export const isCompleteCoverageDate = (value: string | null | undefined): boolean =>
    Boolean(value && value.length === 10 && isValidCoverageDate(value));

export const isValidCoverageTime = (value: string | null | undefined): boolean => !value || /^([01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/.test(value);

export const isCoverageTimeRangeReversed = (startTime: string, endTime: string): boolean => {
    const canonical = (value: string): string => (value.length === 5 ? `${value}:00` : value);
    return canonical(startTime) > canonical(endTime);
};
