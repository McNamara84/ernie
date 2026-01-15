import axios, { isAxiosError } from 'axios';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';

/**
 * Default error messages for DOI validation.
 * These are in German to match the application's primary language.
 * Consider moving to a translation file for full i18n support.
 */
const DEFAULT_ERROR_MESSAGES = {
    INVALID_FORMAT: 'Ungültiges DOI-Format',
    VALIDATION_FAILED: 'Validierung fehlgeschlagen',
} as const;

/**
 * Response from the DOI validation API endpoint
 */
export interface DoiValidationResponse {
    is_valid_format: boolean;
    exists: boolean;
    error?: string;
    existing_resource?: {
        id: number;
        title: string | null;
    };
    last_assigned_doi?: string;
    suggested_doi?: string;
}

/**
 * Conflict data when a DOI already exists
 */
export interface DoiConflictData {
    existingDoi: string;
    existingResourceId?: number;
    existingResourceTitle?: string;
    lastAssignedDoi: string;
    suggestedDoi: string;
    /** Whether a valid suggestion is available (false when backend couldn't generate one) */
    hasSuggestion: boolean;
}

/**
 * Options for the useDoiValidation hook
 */
export interface UseDoiValidationOptions {
    /** Resource ID to exclude from duplicate check (for edit mode) */
    excludeResourceId?: number;
    /** Debounce delay in milliseconds (default: 300) */
    debounceMs?: number;
    /** Callback when validation is successful (DOI is available) */
    onSuccess?: () => void;
    /** Callback when a conflict is detected */
    onConflict?: (conflictData: DoiConflictData) => void;
    /** Callback when an error occurs */
    onError?: (error: string) => void;
    /** Custom error messages (optional, defaults to German messages) */
    errorMessages?: {
        invalidFormat?: string;
        validationFailed?: string;
    };
}

/**
 * Result returned by the useDoiValidation hook
 */
export interface UseDoiValidationResult {
    /** Whether a validation request is in progress */
    isValidating: boolean;
    /** Whether the last validation was successful (DOI is valid and available) */
    isValid: boolean | null;
    /** Error message from the last validation (format error) */
    error: string | null;
    /** Conflict data if the DOI already exists */
    conflictData: DoiConflictData | null;
    /** Whether the conflict modal should be shown */
    showConflictModal: boolean;
    /** Function to control the conflict modal visibility */
    setShowConflictModal: (show: boolean) => void;
    /** Function to validate a DOI (call on blur) */
    validateDoi: (doi: string) => void;
    /** Function to reset the validation state */
    resetValidation: () => void;
}

/**
 * Custom hook for validating DOIs against the database.
 * 
 * @example
 * ```tsx
 * const {
 *     isValidating,
 *     isValid,
 *     error,
 *     conflictData,
 *     showConflictModal,
 *     setShowConflictModal,
 *     validateDoi,
 * } = useDoiValidation({
 *     excludeResourceId: resourceId,
 *     onConflict: (data) => console.log('Conflict:', data),
 * });
 * 
 * // In the input field
 * <Input onBlur={(e) => validateDoi(e.target.value)} />
 * 
 * // Render the conflict modal
 * {conflictData && (
 *     <DoiConflictModal
 *         open={showConflictModal}
 *         onOpenChange={setShowConflictModal}
 *         {...conflictData}
 *     />
 * )}
 * ```
 */
export function useDoiValidation(options: UseDoiValidationOptions = {}): UseDoiValidationResult {
    const { 
        excludeResourceId, 
        debounceMs = 300, 
        onSuccess, 
        onConflict, 
        onError,
        errorMessages,
    } = options;

    // Memoize error messages to prevent unnecessary callback recreations
    const messages = useMemo(() => ({
        invalidFormat: errorMessages?.invalidFormat ?? DEFAULT_ERROR_MESSAGES.INVALID_FORMAT,
        validationFailed: errorMessages?.validationFailed ?? DEFAULT_ERROR_MESSAGES.VALIDATION_FAILED,
    }), [errorMessages?.invalidFormat, errorMessages?.validationFailed]);

    const [isValidating, setIsValidating] = useState(false);
    const [isValid, setIsValid] = useState<boolean | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [conflictData, setConflictData] = useState<DoiConflictData | null>(null);
    const [showConflictModal, setShowConflictModal] = useState(false);

    // Ref for debounce timeout
    const debounceTimeoutRef = useRef<ReturnType<typeof setTimeout> | null>(null);
    // Ref for abort controller to cancel pending requests
    const abortControllerRef = useRef<AbortController | null>(null);

    // Cleanup on unmount: clear timeout and abort any pending requests
    useEffect(() => {
        return () => {
            if (debounceTimeoutRef.current) {
                clearTimeout(debounceTimeoutRef.current);
            }
            if (abortControllerRef.current) {
                abortControllerRef.current.abort();
            }
        };
    }, []);

    const resetValidation = useCallback(() => {
        setIsValid(null);
        setError(null);
        setConflictData(null);
        setShowConflictModal(false);
        setIsValidating(false);
    }, []);

    const validateDoi = useCallback((doi: string) => {
        // Clear any existing debounce timeout
        if (debounceTimeoutRef.current) {
            clearTimeout(debounceTimeoutRef.current);
        }

        // Abort any pending request
        if (abortControllerRef.current) {
            abortControllerRef.current.abort();
        }

        // If DOI is empty, reset state
        const trimmedDoi = doi.trim();
        if (!trimmedDoi) {
            resetValidation();
            return;
        }

        // Set validating state immediately to provide user feedback that validation is queued
        setIsValidating(true);
        setError(null);
        setConflictData(null);

        // Debounce the validation request
        debounceTimeoutRef.current = setTimeout(async () => {

            // Create a new abort controller for this request
            const abortController = new AbortController();
            abortControllerRef.current = abortController;

            try {
                const response = await axios.post<DoiValidationResponse>(
                    '/api/v1/doi/validate',
                    {
                        doi: trimmedDoi,
                        exclude_resource_id: excludeResourceId,
                    },
                    {
                        signal: abortController.signal,
                    }
                );

                const data = response.data;

                // Check if format is valid
                if (!data.is_valid_format) {
                    setIsValid(false);
                    setError(data.error || messages.invalidFormat);
                    onError?.(data.error || messages.invalidFormat);
                    return;
                }

                // Check if DOI already exists
                if (data.exists) {
                    // Handle case where backend couldn't generate a suggestion
                    // (e.g., after max attempts reached). In this case, we don't
                    // show a suggestion to avoid misleading the user.
                    const suggestedDoi = data.suggested_doi ?? null;
                    
                    const conflict: DoiConflictData = {
                        existingDoi: trimmedDoi,
                        existingResourceId: data.existing_resource?.id,
                        existingResourceTitle: data.existing_resource?.title ?? undefined,
                        lastAssignedDoi: data.last_assigned_doi ?? trimmedDoi,
                        // Only provide suggestedDoi if backend actually returned one
                        suggestedDoi: suggestedDoi ?? '',
                        // Flag to indicate if suggestion is available
                        hasSuggestion: suggestedDoi !== null,
                    };
                    setConflictData(conflict);
                    setShowConflictModal(true);
                    setIsValid(false);
                    onConflict?.(conflict);
                    return;
                }

                // DOI is valid and available
                setIsValid(true);
                onSuccess?.();
            } catch (err) {
                // Don't report aborted requests as errors
                if (axios.isCancel(err)) {
                    return;
                }

                // Handle validation errors (422)
                if (isAxiosError(err) && err.response?.status === 422) {
                    const responseData = err.response.data as DoiValidationResponse;
                    if (!responseData.is_valid_format) {
                        setIsValid(false);
                        setError(responseData.error || messages.invalidFormat);
                        onError?.(responseData.error || messages.invalidFormat);
                        return;
                    }
                }

                // Handle other errors
                const errorMessage = isAxiosError(err) 
                    ? err.response?.data?.message || messages.validationFailed
                    : messages.validationFailed;
                
                setError(errorMessage);
                setIsValid(false);
                onError?.(errorMessage);
            } finally {
                setIsValidating(false);
            }
        }, debounceMs);
    }, [excludeResourceId, debounceMs, onSuccess, onConflict, onError, messages, resetValidation]);

    return {
        isValidating,
        isValid,
        error,
        conflictData,
        showConflictModal,
        setShowConflictModal,
        validateDoi,
        resetValidation,
    };
}

export default useDoiValidation;
