import { describe, expect, it } from 'vitest';

import { findDateByType, formatLandingPageDate, isCoverageDate, pickDateString } from '@/pages/LandingPages/lib/dateHelpers';
import type { LandingPageResourceDate } from '@/types/landing-page';

const makeDate = (overrides: Partial<LandingPageResourceDate> = {}): LandingPageResourceDate => ({
    id: 1,
    date_type: null,
    date_type_slug: null,
    date_value: null,
    start_date: null,
    end_date: null,
    date_information: null,
    ...overrides,
});

describe('findDateByType', () => {
    it('returns undefined for an empty list', () => {
        expect(findDateByType([], 'Available')).toBeUndefined();
    });

    it('matches by date_type case-insensitively', () => {
        const dates = [makeDate({ id: 1, date_type: 'Collected' }), makeDate({ id: 2, date_type: 'available' })];
        expect(findDateByType(dates, 'Available')?.id).toBe(2);
    });

    it('matches by slug when name does not match', () => {
        const dates = [makeDate({ id: 7, date_type: 'Other Label', date_type_slug: 'Available' })];
        expect(findDateByType(dates, 'Available')?.id).toBe(7);
    });

    it('returns undefined when no entry matches', () => {
        const dates = [makeDate({ id: 1, date_type: 'Collected', date_type_slug: 'Collected' })];
        expect(findDateByType(dates, 'Available')).toBeUndefined();
    });

    it('ignores entries without type fields', () => {
        const dates = [makeDate({ id: 1 })];
        expect(findDateByType(dates, 'Available')).toBeUndefined();
    });
});

describe('isCoverageDate', () => {
    it('detects coverage by name and slug', () => {
        expect(isCoverageDate(makeDate({ date_type: 'Coverage' }))).toBe(true);
        expect(isCoverageDate(makeDate({ date_type_slug: 'coverage' }))).toBe(true);
        expect(isCoverageDate(makeDate({ date_type: 'Collected' }))).toBe(false);
    });
});

describe('formatLandingPageDate', () => {
    it('returns null when undefined is passed', () => {
        expect(formatLandingPageDate(undefined)).toBeNull();
    });

    it('formats closed ranges from start_date and end_date', () => {
        expect(formatLandingPageDate(makeDate({ start_date: '2024-01-01', end_date: '2024-01-31' }))).toBe('2024-01-01 - 2024-01-31');
    });

    it('collapses equal start and end dates to a single date', () => {
        expect(formatLandingPageDate(makeDate({ start_date: '2024-01-01', end_date: '2024-01-01' }))).toBe('2024-01-01');
    });

    it('prefers start_date over date_value for single dates', () => {
        expect(formatLandingPageDate(makeDate({ start_date: '2024-01-01', date_value: '2023-01-01' }))).toBe('2024-01-01');
    });

    it('falls back to date_value when start_date is missing', () => {
        expect(formatLandingPageDate(makeDate({ date_value: '2023-01-01' }))).toBe('2023-01-01');
    });

    it('returns null for coverage dates', () => {
        expect(formatLandingPageDate(makeDate({ date_type_slug: 'Coverage', date_value: '2023-01-01' }))).toBeNull();
    });

    it('returns null when all date fields are empty', () => {
        expect(formatLandingPageDate(makeDate())).toBeNull();
    });
});

describe('pickDateString', () => {
    it('uses the shared landing-page date formatter', () => {
        expect(pickDateString(makeDate({ start_date: '2024-01-01', end_date: '2024-01-31' }))).toBe('2024-01-01 - 2024-01-31');
    });
});
