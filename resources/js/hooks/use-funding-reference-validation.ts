import { useMemo } from 'react';

import type { FundingReferenceEntry } from '@/components/curation/fields/funding-reference/types';

export interface FundingReferenceValidationResult {
    isValid: boolean;
    errors: {
        funderName?: string;
        awardUri?: string;
    };
}

/**
 * Validate URL format using the URL constructor
 */
function isValidUrl(url: string): boolean {
    if (!url.trim()) return true; // Empty is valid (field is optional)
    try {
        new URL(url);
        return true;
    } catch {
        return false;
    }
}

/**
 * Hook for validating a single funding reference entry
 * 
 * Validation Rules:
 * - funderName: Required, must not be empty
 * - awardUri: Optional, but if provided must be valid URL
 */
export function useFundingReferenceValidation(funding: FundingReferenceEntry): FundingReferenceValidationResult {
    return useMemo(() => {
        const errors: FundingReferenceValidationResult['errors'] = {};

        // Validate funderName (required)
        if (!funding.funderName?.trim()) {
            errors.funderName = 'Funder name is required';
        }

        // Validate awardUri (optional, but must be valid URL if provided)
        if (funding.awardUri && !isValidUrl(funding.awardUri)) {
            errors.awardUri = 'Invalid URL format';
        }

        return {
            isValid: Object.keys(errors).length === 0,
            errors,
        };
    }, [funding.funderName, funding.awardUri]);
}

/**
 * Validate all funding references in a list
 * Returns true only if all entries are valid
 */
export function validateAllFundingReferences(fundingReferences: FundingReferenceEntry[]): boolean {
    return fundingReferences.every((funding) => {
        // Validate funderName
        if (!funding.funderName?.trim()) {
            return false;
        }

        // Validate awardUri if provided
        if (funding.awardUri && !isValidUrl(funding.awardUri)) {
            return false;
        }

        return true;
    });
}
