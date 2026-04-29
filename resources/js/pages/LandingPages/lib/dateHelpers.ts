import type { LandingPageResourceDate } from '@/types/landing-page';

/**
 * Find a date entry whose normalised type matches the given target.
 *
 * Compares case-insensitively against both the human-readable name and the
 * slug so the helper is robust against future seeder changes.
 */
export const findDateByType = (
    dates: LandingPageResourceDate[],
    target: string,
): LandingPageResourceDate | undefined => {
    const needle = target.toLowerCase();
    return dates.find(
        (d) =>
            d.date_type?.toLowerCase() === needle ||
            d.date_type_slug?.toLowerCase() === needle,
    );
};

/**
 * Pick the best date string from a {@link LandingPageResourceDate} for display.
 *
 * Prefers `start_date` (used for ranges and open-ended ranges), then falls
 * back to `date_value` for single-date entries.
 */
export const pickDateString = (date: LandingPageResourceDate | undefined): string | null => {
    if (!date) return null;
    return date.start_date ?? date.date_value ?? null;
};
