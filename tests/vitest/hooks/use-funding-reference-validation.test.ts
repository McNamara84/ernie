import { renderHook } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import type { FundingReferenceEntry } from '@/components/curation/fields/funding-reference/types';
import { useFundingReferenceValidation, validateAllFundingReferences } from '@/hooks/use-funding-reference-validation';

function makeFunding(overrides: Partial<FundingReferenceEntry> = {}): FundingReferenceEntry {
    return {
        id: '1',
        funderName: 'DFG',
        funderIdentifier: '',
        funderIdentifierType: null,
        awardNumber: '',
        awardUri: '',
        awardTitle: '',
        isExpanded: false,
        ...overrides,
    };
}

describe('useFundingReferenceValidation', () => {
    it('returns valid for entry with funderName', () => {
        const { result } = renderHook(() => useFundingReferenceValidation(makeFunding()));
        expect(result.current.isValid).toBe(true);
        expect(result.current.errors).toEqual({});
    });

    it('returns error when funderName is empty', () => {
        const { result } = renderHook(() => useFundingReferenceValidation(makeFunding({ funderName: '' })));
        expect(result.current.isValid).toBe(false);
        expect(result.current.errors.funderName).toBeDefined();
    });

    it('returns error when funderName is only whitespace', () => {
        const { result } = renderHook(() => useFundingReferenceValidation(makeFunding({ funderName: '  ' })));
        expect(result.current.isValid).toBe(false);
    });

    it('accepts valid awardUri', () => {
        const { result } = renderHook(() =>
            useFundingReferenceValidation(makeFunding({ awardUri: 'https://example.com/grant/123' })),
        );
        expect(result.current.isValid).toBe(true);
    });

    it('returns error for invalid awardUri', () => {
        const { result } = renderHook(() => useFundingReferenceValidation(makeFunding({ awardUri: 'not-a-url' })));
        expect(result.current.isValid).toBe(false);
        expect(result.current.errors.awardUri).toBeDefined();
    });

    it('allows empty awardUri', () => {
        const { result } = renderHook(() => useFundingReferenceValidation(makeFunding({ funderName: 'EU' })));
        expect(result.current.isValid).toBe(true);
    });
});

describe('validateAllFundingReferences', () => {
    it('returns true for empty array', () => {
        expect(validateAllFundingReferences([])).toBe(true);
    });

    it('returns true for all valid entries', () => {
        expect(
            validateAllFundingReferences([
                makeFunding(),
                makeFunding({ id: '2', funderName: 'EU', awardNumber: '123', awardUri: 'https://example.com' }),
            ]),
        ).toBe(true);
    });

    it('returns false when one entry is invalid', () => {
        expect(validateAllFundingReferences([makeFunding(), makeFunding({ id: '2', funderName: '' })])).toBe(false);
    });

    it('returns false for invalid awardUri', () => {
        expect(validateAllFundingReferences([makeFunding({ awardUri: 'bad-url' })])).toBe(false);
    });
});
