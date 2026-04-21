import { useQueryClient } from '@tanstack/react-query';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';

import { ApiError, apiRequest } from '@/lib/api-client';
import { apiEndpoints, queryKeys } from '@/lib/query-keys';

/**
 * Default error messages for DOI validation.
 *
 * Kept in English to match the project's language policy (all user-facing
 * strings in code are English). Call sites that need a localised copy can
 * override these via the `errorMessages` option of {@link useDoiValidation}.
 */
const DEFAULT_ERROR_MESSAGES = {
    INVALID_FORMAT: 'Invalid DOI format',
    VALIDATION_FAILED: 'Validation failed',
} as const;

/**
 * Combine an upstream {@link AbortSignal} (provided by TanStack Query's
 * `fetchQuery` queryFn) with the local debounce/lifecycle controller so that
 * aborting either source cancels the in-flight request.
 *
 * Prefers the native `AbortSignal.any` when available and falls back to a
 * manual listener chain for environments that do not support it yet.
 */
function combineSignals(upstream: AbortSignal | undefined, local: AbortSignal): AbortSignal {
    if (!upstream) {
        return local;
    }

    if (typeof AbortSignal !== 'undefined' && typeof (AbortSignal as unknown as { any?: unknown }).any === 'function') {
        return (AbortSignal as unknown as { any: (signals: AbortSignal[]) => AbortSignal }).any([upstream, local]);
    }

    const controller = new AbortController();
    const abort = (reason?: unknown) => controller.abort(reason);
    if (upstream.aborted) {
        abort((upstream as AbortSignal & { reason?: unknown }).reason);
    } else {
        upstream.addEventListener('abort', () => abort((upstream as AbortSignal & { reason?: unknown }).reason), { once: true });
    }
    if (local.aborted) {
        abort((local as AbortSignal & { reason?: unknown }).reason);
    } else {
        local.addEventListener('abort', () => abort((local as AbortSignal & { reason?: unknown }).reason), { once: true });
    }
    return controller.signal;
}

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
    /** Custom error messages (optional; defaults to English messages) */
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
    /** Synchronous DOI duplicate check for use before form submission (no debounce) */
    checkDoiBeforeSave: (doi: string) => Promise<DoiConflictData | null>;
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
    const { excludeResourceId, debounceMs = 300, onSuccess, onConflict, onError, errorMessages } = options;
    const queryClient = useQueryClient();

    // Memoize error messages to prevent unnecessary callback recreations
    const messages = useMemo(
        () => ({
            invalidFormat: errorMessages?.invalidFormat ?? DEFAULT_ERROR_MESSAGES.INVALID_FORMAT,
            validationFailed: errorMessages?.validationFailed ?? DEFAULT_ERROR_MESSAGES.VALIDATION_FAILED,
        }),
        [errorMessages?.invalidFormat, errorMessages?.validationFailed],
    );

    const [isValidating, setIsValidating] = useState(false);
    const [isValid, setIsValid] = useState<boolean | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [conflictData, setConflictData] = useState<DoiConflictData | null>(null);
    const [showConflictModal, setShowConflictModal] = useState(false);

    // Ref for debounce timeout
    const debounceTimeoutRef = useRef<ReturnType<typeof setTimeout> | null>(null);
    // Ref for abort controller to cancel pending requests
    const abortControllerRef = useRef<AbortController | null>(null);
    // Track the query key of the in-flight validation so we can cancel the
    // underlying TanStack Query fetch (which owns its own AbortSignal) when a
    // newer validation request arrives.
    const activeQueryKeyRef = useRef<readonly unknown[] | null>(null);

    // Cleanup on unmount: clear timeout and abort any pending requests
    useEffect(() => {
        return () => {
            if (debounceTimeoutRef.current) {
                clearTimeout(debounceTimeoutRef.current);
            }
            if (abortControllerRef.current) {
                abortControllerRef.current.abort();
            }
            if (activeQueryKeyRef.current) {
                void queryClient.cancelQueries({ queryKey: activeQueryKeyRef.current, exact: true });
                activeQueryKeyRef.current = null;
            }
        };
    }, [queryClient]);

    const resetValidation = useCallback(() => {
        setIsValid(null);
        setError(null);
        setConflictData(null);
        setShowConflictModal(false);
        setIsValidating(false);
    }, []);

    const validateDoi = useCallback(
        (doi: string) => {
            // Clear any existing debounce timeout
            if (debounceTimeoutRef.current) {
                clearTimeout(debounceTimeoutRef.current);
            }

            // Abort any pending request:
            // - The local controller cancels the debounced fetch (and is mirrored
            //   into the TanStack signal below for full coverage).
            // - `cancelQueries` aborts the underlying TanStack Query fetch which
            //   owns its own AbortSignal that is *not* linked to our local one.
            if (abortControllerRef.current) {
                abortControllerRef.current.abort();
            }
            if (activeQueryKeyRef.current) {
                void queryClient.cancelQueries({ queryKey: activeQueryKeyRef.current, exact: true });
                activeQueryKeyRef.current = null;
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

                const queryKey = queryKeys.doi.validate(trimmedDoi, excludeResourceId);
                activeQueryKeyRef.current = queryKey;

                try {
                    const data = await queryClient.fetchQuery<DoiValidationResponse>({
                        queryKey,
                        queryFn: ({ signal }) =>
                            apiRequest<DoiValidationResponse>(apiEndpoints.doiValidate, {
                                method: 'POST',
                                body: {
                                    doi: trimmedDoi,
                                    exclude_resource_id: excludeResourceId,
                                },
                                // Combine TanStack's signal (aborted by cancelQueries)
                                // with the local controller so either source can
                                // cancel the in-flight request.
                                signal: combineSignals(signal, abortController.signal),
                            }),
                        staleTime: 5 * 60_000,
                    });

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
                    if (err instanceof DOMException && err.name === 'AbortError') {
                        return;
                    }

                    // Handle validation errors (422) with structured body
                    if (err instanceof ApiError && err.status === 422) {
                        const responseData = err.body as DoiValidationResponse | null;
                        if (responseData && !responseData.is_valid_format) {
                            setIsValid(false);
                            setError(responseData.error || messages.invalidFormat);
                            onError?.(responseData.error || messages.invalidFormat);
                            return;
                        }
                    }

                    // Handle other errors
                    const errorMessage = err instanceof ApiError && err.message ? err.message : messages.validationFailed;

                    setError(errorMessage);
                    setIsValid(false);
                    onError?.(errorMessage);
                } finally {
                    // Only clear `isValidating` if this invocation is still the
                    // active one. A newer call (e.g. `checkDoiBeforeSave` or a
                    // subsequent `validateDoi`) has already taken over the ref
                    // and may have its own request in flight — flipping the
                    // flag here would briefly show the UI as idle while the
                    // newer request is still pending.
                    if (activeQueryKeyRef.current === queryKey) {
                        setIsValidating(false);
                        activeQueryKeyRef.current = null;
                    }
                }
            }, debounceMs);
        },
        [excludeResourceId, debounceMs, onSuccess, onConflict, onError, messages, resetValidation, queryClient],
    );

    /**
     * Synchronous (non-debounced) DOI duplicate check for use before form submission.
     * Returns conflict data if the DOI already exists, or null if available.
     * Also updates internal state (conflictData, showConflictModal, isValid, error)
     * to keep hook state consistent for consumers.
     */
    const checkDoiBeforeSave = useCallback(
        async (doi: string): Promise<DoiConflictData | null> => {
            // Cancel any pending debounced request
            if (debounceTimeoutRef.current) {
                clearTimeout(debounceTimeoutRef.current);
                debounceTimeoutRef.current = null;
            }
            if (abortControllerRef.current) {
                abortControllerRef.current.abort();
                abortControllerRef.current = null;
            }
            // Take ownership of the active-query ref so the now-stale
            // `validateDoi` finally block cannot mistake itself for the active
            // run and clear `isValidating` mid-save-check.
            activeQueryKeyRef.current = null;

            // Reset isValidating that may have been set by a cancelled validateDoi call
            setIsValidating(false);

            const trimmedDoi = doi.trim();
            if (!trimmedDoi) {
                resetValidation();
                return null;
            }

            setIsValidating(true);
            // Clear previous validation state before the check so consumers
            // never observe stale `isValid` / `error` / `conflictData` values
            // if this request ends up being cancelled or failing.
            setIsValid(null);
            setError(null);
            setConflictData(null);

            const queryKey = queryKeys.doi.validate(trimmedDoi, excludeResourceId);
            activeQueryKeyRef.current = queryKey;

            // Create a local abort controller so a subsequent call (another
            // `checkDoiBeforeSave` or a new `validateDoi`) can abort this
            // request via `abortControllerRef.current.abort()`.
            const abortController = new AbortController();
            abortControllerRef.current = abortController;

            // Pre-save uniqueness checks must always hit the backend: a cached
            // "available" response from a previous `validateDoi` call could
            // otherwise mask a freshly-created conflicting resource. Drop any
            // cached entry for this key and force a network roundtrip.
            queryClient.removeQueries({ queryKey, exact: true });

            try {
                const data = await queryClient.fetchQuery<DoiValidationResponse>({
                    queryKey,
                    queryFn: ({ signal }) =>
                        apiRequest<DoiValidationResponse>(apiEndpoints.doiValidate, {
                            method: 'POST',
                            body: {
                                doi: trimmedDoi,
                                exclude_resource_id: excludeResourceId,
                            },
                            // Combine TanStack's signal (aborted by
                            // `cancelQueries`, e.g. on unmount) with the local
                            // controller so either source can cancel the
                            // in-flight request and prevent post-unmount state
                            // updates from the `finally` block.
                            signal: combineSignals(signal, abortController.signal),
                        }),
                    // `staleTime: 0` ensures the data is considered stale the
                    // moment it is written to the cache, so any subsequent
                    // pre-save check will refetch rather than reuse it.
                    staleTime: 0,
                });

                if (!data.is_valid_format) {
                    // Mirror validateDoi: set error state for invalid format and
                    // fire the onError callback so call sites can react (e.g.
                    // show a toast). Fall back to the default message when the
                    // backend did not provide one.
                    const errorMessage = data.error || messages.invalidFormat;
                    setIsValid(false);
                    setError(errorMessage);
                    onError?.(errorMessage);
                    return null;
                }

                if (data.exists) {
                    const suggestedDoi = data.suggested_doi ?? null;
                    const conflict: DoiConflictData = {
                        existingDoi: trimmedDoi,
                        existingResourceId: data.existing_resource?.id,
                        existingResourceTitle: data.existing_resource?.title ?? undefined,
                        lastAssignedDoi: data.last_assigned_doi ?? trimmedDoi,
                        suggestedDoi: suggestedDoi ?? '',
                        hasSuggestion: suggestedDoi !== null,
                    };

                    setConflictData(conflict);
                    setShowConflictModal(true);
                    setIsValid(false);
                    onConflict?.(conflict);
                    return conflict;
                }

                // DOI is valid and available — update state accordingly
                setIsValid(true);
                setConflictData(null);
                setShowConflictModal(false);
                onSuccess?.();
                return null;
            } catch (err) {
                // Swallow aborts silently — the caller that triggered the
                // cancellation (unmount, newer save-check) is responsible for
                // any follow-up state management.
                if (err instanceof DOMException && err.name === 'AbortError') {
                    return null;
                }
                // If validation request fails (network error etc.), don't block save
                // Let the backend unique constraint handle it
                return null;
            } finally {
                // Only apply state updates when this invocation is still the
                // active one. If the request was aborted (e.g. by unmount or a
                // newer call that took ownership of `activeQueryKeyRef`), the
                // ref no longer matches and we skip the updates entirely to
                // avoid post-unmount or cross-request state writes.
                if (activeQueryKeyRef.current === queryKey) {
                    setIsValidating(false);
                    activeQueryKeyRef.current = null;
                }
                if (abortControllerRef.current === abortController) {
                    abortControllerRef.current = null;
                }
            }
        },
        [excludeResourceId, onConflict, onError, onSuccess, messages, resetValidation, queryClient],
    );

    return {
        isValidating,
        isValid,
        error,
        conflictData,
        showConflictModal,
        setShowConflictModal,
        validateDoi,
        resetValidation,
        checkDoiBeforeSave,
    };
}

export default useDoiValidation;
