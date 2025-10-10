import { useCallback, useEffect, useState } from 'react';

import {
    resolveDOIMetadata,
    supportsMetadataResolution,
    validateIdentifierFormat,
    type DataCiteMetadata,
} from '@/lib/doi-validation';

export interface ValidationState {
    status: 'idle' | 'validating' | 'valid' | 'invalid' | 'warning';
    message?: string;
    metadata?: DataCiteMetadata;
}

interface UseIdentifierValidationProps {
    identifier: string;
    identifierType: string;
    enabled?: boolean;
    debounceMs?: number;
}

/**
 * Hook for validating identifiers with format check and optional API resolution
 * 
 * Features:
 * - Format validation (immediate)
 * - API metadata resolution for DOIs (debounced, non-blocking)
 * - Timeout handling
 * - Warning instead of error for API failures
 */
export function useIdentifierValidation({
    identifier,
    identifierType,
    enabled = true,
    debounceMs = 1000,
}: UseIdentifierValidationProps) {
    const [validationState, setValidationState] = useState<ValidationState>({
        status: 'idle',
    });

    const validate = useCallback(async () => {
        if (!enabled || !identifier.trim()) {
            setValidationState({ status: 'idle' });
            return;
        }

        // Step 1: Format validation (instant)
        const formatResult = validateIdentifierFormat(identifier, identifierType);
        
        if (!formatResult.isValid) {
            setValidationState({
                status: 'invalid',
                message: formatResult.message,
            });
            return;
        }

        // Step 2: API resolution (only for DOI, non-blocking)
        if (supportsMetadataResolution(identifierType)) {
            setValidationState({ status: 'validating' });

            try {
                const result = await resolveDOIMetadata(identifier);
                
                if (result.success && result.metadata) {
                    setValidationState({
                        status: 'valid',
                        message: result.metadata.title 
                            ? `Verified: ${result.metadata.title}`
                            : 'DOI verified',
                        metadata: result.metadata,
                    });
                } else {
                    // API error = warning, not blocking
                    setValidationState({
                        status: 'warning',
                        message: result.error || 'Could not verify DOI, but format is valid',
                    });
                }
            } catch {
                // Network/API errors should not block submission
                setValidationState({
                    status: 'warning',
                    message: 'Could not verify DOI (network error), but format is valid',
                });
            }
        } else {
            // Format is valid, no API check available
            setValidationState({
                status: 'valid',
                message: 'Format validated',
            });
        }
    }, [identifier, identifierType, enabled]);

    // Debounced validation
    useEffect(() => {
        const timer = setTimeout(() => {
            void validate();
        }, debounceMs);

        return () => clearTimeout(timer);
    }, [validate, debounceMs]);

    return validationState;
}
