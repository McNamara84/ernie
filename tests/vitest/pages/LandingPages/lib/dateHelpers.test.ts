import { describe, expect, it } from 'vitest';

import { findDateByType, pickDateString } from '@/pages/LandingPages/lib/dateHelpers';
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

describe('pickDateString', () => {
    it('returns null when undefined is passed', () => {
        expect(pickDateString(undefined)).toBeNull();
    });

    it('prefers start_date over date_value', () => {
        expect(pickDateString(makeDate({ start_date: '2024-01-01', date_value: '2023-01-01' }))).toBe('2024-01-01');
    });

    it('falls back to date_value when start_date is missing', () => {
        expect(pickDateString(makeDate({ date_value: '2023-01-01' }))).toBe('2023-01-01');
    });

    it('returns null when both fields are empty', () => {
        expect(pickDateString(makeDate())).toBeNull();
    });
});
