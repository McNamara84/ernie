import { describe, expect, it } from 'vitest';

import { normalizeIgsnInput } from '@/lib/igsn-validation';

describe('normalizeIgsnInput', () => {
    it('accepts handle, DOI and DOI URL input forms', () => {
        expect(normalizeIgsnInput(' ICDP5052EUYY001 ')).toEqual({
            isValid: true,
            doi: '10.60510/icdp5052euyy001',
            handle: 'ICDP5052EUYY001',
        });
        expect(normalizeIgsnInput('10.60510/ICDP5052EUYY001')).toEqual({
            isValid: true,
            doi: '10.60510/icdp5052euyy001',
            handle: 'ICDP5052EUYY001',
        });
        expect(normalizeIgsnInput('https://dx.doi.org/10.60510/ICDP5052EUYY001')).toEqual({
            isValid: true,
            doi: '10.60510/icdp5052euyy001',
            handle: 'ICDP5052EUYY001',
        });
    });

    it('returns specific messages for empty, foreign-prefix and malformed values', () => {
        expect(normalizeIgsnInput('')).toEqual({
            isValid: false,
            message: 'Enter an IGSN.',
        });
        expect(normalizeIgsnInput('10.99999/ICDP5052EUYY001')).toEqual({
            isValid: false,
            message: 'Use the IGSN prefix 10.60510 or enter the IGSN handle only.',
        });
        expect(normalizeIgsnInput('not an igsn')).toEqual({
            isValid: false,
            message: 'Enter a valid IGSN handle, for example ICDP5052EUYY001.',
        });
    });

    it('uses the configured IGSN prefix when one is provided', () => {
        expect(normalizeIgsnInput('10.12345/CUSTOM001', '10.12345')).toEqual({
            isValid: true,
            doi: '10.12345/custom001',
            handle: 'CUSTOM001',
        });
        expect(normalizeIgsnInput('10.60510/CUSTOM001', '10.12345')).toEqual({
            isValid: false,
            message: 'Use the IGSN prefix 10.12345 or enter the IGSN handle only.',
        });
    });
});
