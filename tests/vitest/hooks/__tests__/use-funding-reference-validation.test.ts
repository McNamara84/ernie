import { renderHook } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { useFundingReferenceValidation, validateAllFundingReferences } from '@/hooks/use-funding-reference-validation';
import type { FundingReferenceEntry } from '@/components/curation/fields/funding-reference/types';

describe('use-funding-reference-validation', () => {
    describe('useFundingReferenceValidation', () => {
        it('should return valid for complete funding reference', () => {
            const funding: FundingReferenceEntry = {
                funderName: 'National Science Foundation',
                funderIdentifier: '10.13039/100000001',
                funderIdentifierType: 'Crossref Funder ID',
                awardNumber: 'ABC-123',
                awardUri: 'https://nsf.gov/award/abc-123',
                awardTitle: 'Research Grant',
            };

            const { result } = renderHook(() => useFundingReferenceValidation(funding));

            expect(result.current.isValid).toBe(true);
            expect(result.current.errors).toEqual({});
        });

        it('should return valid when only required funderName is provided', () => {
            const funding: FundingReferenceEntry = {
                funderName: 'Deutsche Forschungsgemeinschaft',
            };

            const { result } = renderHook(() => useFundingReferenceValidation(funding));

            expect(result.current.isValid).toBe(true);
            expect(result.current.errors).toEqual({});
        });

        it('should return invalid when funderName is missing', () => {
            const funding: FundingReferenceEntry = {
                funderName: '',
                awardNumber: 'ABC-123',
            };

            const { result } = renderHook(() => useFundingReferenceValidation(funding));

            expect(result.current.isValid).toBe(false);
            expect(result.current.errors.funderName).toBe('Funder name is required');
        });

        it('should return invalid when funderName is whitespace only', () => {
            const funding: FundingReferenceEntry = {
                funderName: '   ',
            };

            const { result } = renderHook(() => useFundingReferenceValidation(funding));

            expect(result.current.isValid).toBe(false);
            expect(result.current.errors.funderName).toBe('Funder name is required');
        });

        it('should return invalid when funderName is undefined', () => {
            const funding: FundingReferenceEntry = {} as FundingReferenceEntry;

            const { result } = renderHook(() => useFundingReferenceValidation(funding));

            expect(result.current.isValid).toBe(false);
            expect(result.current.errors.funderName).toBe('Funder name is required');
        });

        it('should return valid when awardUri is empty (optional field)', () => {
            const funding: FundingReferenceEntry = {
                funderName: 'Test Funder',
                awardUri: '',
            };

            const { result } = renderHook(() => useFundingReferenceValidation(funding));

            expect(result.current.isValid).toBe(true);
            expect(result.current.errors.awardUri).toBeUndefined();
        });

        it('should return invalid when awardUri is not a valid URL', () => {
            const funding: FundingReferenceEntry = {
                funderName: 'Test Funder',
                awardUri: 'not-a-valid-url',
            };

            const { result } = renderHook(() => useFundingReferenceValidation(funding));

            expect(result.current.isValid).toBe(false);
            expect(result.current.errors.awardUri).toBe('Invalid URL format');
        });

        it('should return valid when awardUri is a valid https URL', () => {
            const funding: FundingReferenceEntry = {
                funderName: 'Test Funder',
                awardUri: 'https://example.com/award/123',
            };

            const { result } = renderHook(() => useFundingReferenceValidation(funding));

            expect(result.current.isValid).toBe(true);
            expect(result.current.errors.awardUri).toBeUndefined();
        });

        it('should return valid when awardUri is a valid http URL', () => {
            const funding: FundingReferenceEntry = {
                funderName: 'Test Funder',
                awardUri: 'http://example.com/award/123',
            };

            const { result } = renderHook(() => useFundingReferenceValidation(funding));

            expect(result.current.isValid).toBe(true);
        });

        it('should return multiple errors when both funderName and awardUri are invalid', () => {
            const funding: FundingReferenceEntry = {
                funderName: '',
                awardUri: 'invalid-url',
            };

            const { result } = renderHook(() => useFundingReferenceValidation(funding));

            expect(result.current.isValid).toBe(false);
            expect(result.current.errors.funderName).toBe('Funder name is required');
            expect(result.current.errors.awardUri).toBe('Invalid URL format');
        });
    });

    describe('validateAllFundingReferences', () => {
        it('should return true for empty array', () => {
            expect(validateAllFundingReferences([])).toBe(true);
        });

        it('should return true when all references are valid', () => {
            const references: FundingReferenceEntry[] = [
                { funderName: 'Funder A', awardNumber: '123' },
                { funderName: 'Funder B', awardUri: 'https://example.com' },
                { funderName: 'Funder C' },
            ];

            expect(validateAllFundingReferences(references)).toBe(true);
        });

        it('should return false when one reference has empty funderName', () => {
            const references: FundingReferenceEntry[] = [
                { funderName: 'Valid Funder' },
                { funderName: '' },
            ];

            expect(validateAllFundingReferences(references)).toBe(false);
        });

        it('should return false when one reference has invalid awardUri', () => {
            const references: FundingReferenceEntry[] = [
                { funderName: 'Valid Funder', awardUri: 'https://valid.com' },
                { funderName: 'Another Funder', awardUri: 'not-a-url' },
            ];

            expect(validateAllFundingReferences(references)).toBe(false);
        });

        it('should return true when awardUri is empty (optional)', () => {
            const references: FundingReferenceEntry[] = [
                { funderName: 'Funder', awardUri: '' },
            ];

            expect(validateAllFundingReferences(references)).toBe(true);
        });

        it('should return false when funderName is whitespace only', () => {
            const references: FundingReferenceEntry[] = [
                { funderName: '   ' },
            ];

            expect(validateAllFundingReferences(references)).toBe(false);
        });
    });
});
