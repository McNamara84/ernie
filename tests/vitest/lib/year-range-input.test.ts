import { describe, expect, it } from 'vitest';

import { formatYearInput, parseYearInput } from '@/lib/year-range-input';

describe('parseYearInput', () => {
    it('returns undefined for empty input', () => {
        expect(parseYearInput('')).toBeUndefined();
        expect(parseYearInput('   ')).toBeUndefined();
    });

    it('accepts trimmed integer values', () => {
        expect(parseYearInput(' 2021 ')).toBe(2021);
    });

    it('rejects non-integer numeric input instead of truncating it', () => {
        expect(parseYearInput('2021.9')).toBeUndefined();
        expect(parseYearInput('1e3')).toBeUndefined();
    });

    it('rejects values outside the provided bounds', () => {
        const bounds = { min: 2000, max: 2025 };

        expect(parseYearInput('1999', bounds)).toBeUndefined();
        expect(parseYearInput('2026', bounds)).toBeUndefined();
        expect(parseYearInput('2021', bounds)).toBe(2021);
    });
});

describe('formatYearInput', () => {
    it('formats undefined as an empty string', () => {
        expect(formatYearInput(undefined)).toBe('');
    });

    it('formats numeric years as strings', () => {
        expect(formatYearInput(2021)).toBe('2021');
    });
});