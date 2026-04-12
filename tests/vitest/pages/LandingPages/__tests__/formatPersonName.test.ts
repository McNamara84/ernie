import { describe, expect, it } from 'vitest';

import { formatPersonName } from '@/pages/LandingPages/lib/formatPersonName';

describe('formatPersonName', () => {
    it('formats full name as "family, given"', () => {
        expect(formatPersonName('Doe', 'John')).toBe('Doe, John');
    });

    it('returns family name when given name is null', () => {
        expect(formatPersonName('Doe', null)).toBe('Doe');
    });

    it('returns given name when family name is null', () => {
        expect(formatPersonName(null, 'John')).toBe('John');
    });

    it('returns "Unknown" when both are null', () => {
        expect(formatPersonName(null, null)).toBe('Unknown');
    });

    it('returns family name when given name is empty-like', () => {
        expect(formatPersonName('Doe', '')).toBe('Doe');
    });

    it('returns given name when family name is empty-like', () => {
        expect(formatPersonName('', 'John')).toBe('John');
    });
});
