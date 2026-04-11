import { describe, expect, it } from 'vitest';

import { abbreviateGivenName } from '@/pages/LandingPages/lib/abbreviateGivenName';

describe('abbreviateGivenName', () => {
    it('abbreviates a simple first name', () => {
        expect(abbreviateGivenName('Alice')).toBe('A.');
    });

    it('abbreviates multiple space-separated names', () => {
        expect(abbreviateGivenName('Alice Marie')).toBe('A. M.');
    });

    it('preserves hyphens in hyphenated names', () => {
        expect(abbreviateGivenName('Jean-Pierre')).toBe('J.-P.');
    });

    it('handles combined hyphenated and space-separated names', () => {
        expect(abbreviateGivenName('Hans-Jürgen Peter')).toBe('H.-J. P.');
    });

    it('passes through already abbreviated name with dot', () => {
        expect(abbreviateGivenName('A.')).toBe('A.');
    });

    it('passes through already abbreviated multi-part name', () => {
        expect(abbreviateGivenName('A. M.')).toBe('A. M.');
    });

    it('adds dot to single letter without dot', () => {
        expect(abbreviateGivenName('A')).toBe('A.');
    });

    it('returns empty string for null input', () => {
        expect(abbreviateGivenName(null)).toBe('');
    });

    it('returns empty string for undefined input', () => {
        expect(abbreviateGivenName(undefined)).toBe('');
    });

    it('returns empty string for empty string input', () => {
        expect(abbreviateGivenName('')).toBe('');
    });

    it('returns empty string for whitespace-only input', () => {
        expect(abbreviateGivenName('   ')).toBe('');
    });

    it('handles name with extra whitespace', () => {
        expect(abbreviateGivenName('  Alice   Marie  ')).toBe('A. M.');
    });

    it('uppercases the first letter', () => {
        expect(abbreviateGivenName('alice')).toBe('A.');
    });

    it('handles already abbreviated hyphenated name', () => {
        expect(abbreviateGivenName('J.-P.')).toBe('J.-P.');
    });
});
