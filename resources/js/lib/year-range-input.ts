export interface YearRangeBounds {
    min?: number | null;
    max?: number | null;
}

const YEAR_INTEGER_PATTERN = /^\d+$/;

export const parseYearInput = (value: string, bounds?: YearRangeBounds): number | undefined => {
    const trimmed = value.trim();

    if (trimmed.length === 0 || !YEAR_INTEGER_PATTERN.test(trimmed)) {
        return undefined;
    }

    const year = Number(trimmed);

    if (!Number.isSafeInteger(year) || year <= 0) {
        return undefined;
    }

    if (bounds?.min != null && year < bounds.min) {
        return undefined;
    }

    if (bounds?.max != null && year > bounds.max) {
        return undefined;
    }

    return year;
};

export const formatYearInput = (value?: number): string => (value === undefined ? '' : String(value));