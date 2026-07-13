import type { LandingPageResourceDate } from '@/types/landing-page';

const normalizeDateType = (value: string | null | undefined): string => value?.trim().toLowerCase() ?? '';

/**
 * Find a date entry whose normalized type matches the given target.
 *
 * Compares case-insensitively against both the human-readable name and the
 * slug so the helper is robust against future seeder changes.
 */
export const findDateByType = (dates: LandingPageResourceDate[], target: string): LandingPageResourceDate | undefined => {
    const needle = normalizeDateType(target);
    return dates.find((d) => normalizeDateType(d.date_type) === needle || normalizeDateType(d.date_type_slug) === needle);
};

export const isCoverageDate = (date: LandingPageResourceDate): boolean =>
    normalizeDateType(date.date_type) === 'coverage' || normalizeDateType(date.date_type_slug) === 'coverage';

/**
 * Format a landing-page date as either a single date or a closed period.
 */
export const formatLandingPageDate = (date: LandingPageResourceDate | undefined): string | null => {
    if (!date || isCoverageDate(date)) return null;

    const start = date.start_date ?? date.date_value ?? null;
    const end = date.end_date ?? null;

    if (date.start_date && end && end !== date.start_date) {
        return `${date.start_date} - ${end}`;
    }

    return start;
};

/**
 * Pick the best date string from a {@link LandingPageResourceDate} for display.
 */
export const pickDateString = (date: LandingPageResourceDate | undefined): string | null => formatLandingPageDate(date);
