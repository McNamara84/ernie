import { useCallback, useState } from 'react';

/**
 * Status of a validation process
 */
export type ValidationStatus = 'idle' | 'validating' | 'valid' | 'invalid';

/**
 * Severity level of a validation message
 */
export type ValidationSeverity = 'error' | 'warning' | 'success' | 'info';

/**
 * Source of a validation message
 */
export type ValidationSource = 'client' | 'backend';

/**
 * A single validation message
 */
export interface ValidationMessage {
    severity: ValidationSeverity;
    message: string;
    fieldId?: string;
    source?: ValidationSource;
}

/**
 * Validation state of a single field
 */
export interface FieldValidationState {
    status: ValidationStatus;
    messages: ValidationMessage[];
    touched: boolean;
    value: unknown;
}

/**
 * Overall validation state of the form
 */
export interface FormValidationState {
    fields: Record<string, FieldValidationState>;
    isValid: boolean;
    touchedCount: number;
    invalidCount: number;
}

/**
 * A validation rule for a field
 */
export type ValidationRule<T = unknown> = {
    validate: (value: T, formData?: unknown) => ValidationMessage | null;
    debounce?: number;
};

/**
 * Options for field validation
 */
export interface ValidateFieldOptions<T = unknown> {
    fieldId: string;
    value: T;
    rules: ValidationRule<T>[];
    formData?: unknown;
    immediate?: boolean;
}

/**
 * Return value of the useFormValidation Hook
 */
export interface UseFormValidationReturn {
    validationState: FormValidationState;
    validateField: <T = unknown>(options: ValidateFieldOptions<T>) => void;
    markFieldTouched: (fieldId: string) => void;
    getFieldState: (fieldId: string) => FieldValidationState;
    resetFieldValidation: (fieldId: string) => void;
    resetAllValidation: () => void;
    hasFieldError: (fieldId: string) => boolean;
    getFieldMessages: (fieldId: string) => ValidationMessage[];
    setFieldErrors: (errors: Array<{ fieldId: string; message: string }>) => void;
    clearFieldErrors: (fieldId: string) => void;
    clearBackendErrors: () => void;
}

/**
 * Hook for form validation with real-time feedback
 *
 * This hook manages the validation state of all form fields
 * and provides functions for validating individual fields.
 *
 * @example
 * ```tsx
 * const { validateField, getFieldState, markFieldTouched } = useFormValidation();
 *
 * // Validate field
 * validateField({
 *   fieldId: 'email',
 *   value: emailValue,
 *   rules: [emailValidationRule],
 * });
 *
 * // Get field state
 * const fieldState = getFieldState('email');
 * ```
 */
export function useFormValidation(): UseFormValidationReturn {
    const [validationState, setValidationState] = useState<FormValidationState>({
        fields: {},
        isValid: true,
        touchedCount: 0,
        invalidCount: 0,
    });

    // Debounce timer references
    const debounceTimers = useState<Record<string, NodeJS.Timeout>>(() => ({}))[0];

    /**
     * Validates a single field with the given rules
     */
    const validateField = useCallback(
        <T = unknown>({ fieldId, value, rules, formData, immediate = false }: ValidateFieldOptions<T>) => {
            // Determine debounce time (longest time of all rules)
            const debounceTime = immediate ? 0 : Math.max(0, ...rules.map((rule) => rule.debounce ?? 0));

            // Clear existing timer
            if (debounceTimers[fieldId]) {
                clearTimeout(debounceTimers[fieldId]);
            }

            // Function to execute validation
            const executeValidation = () => {
                // Remember the old status BEFORE the validating update
                const oldStatusBeforeValidating = validationState.fields[fieldId]?.status;

                // Set status to "validating"
                setValidationState((prev) => ({
                    ...prev,
                    fields: {
                        ...prev.fields,
                        [fieldId]: {
                            ...prev.fields[fieldId],
                            status: 'validating',
                            value,
                        },
                    },
                }));

                // Execute all validation rules
                const messages: ValidationMessage[] = [];
                for (const rule of rules) {
                    const result = rule.validate(value, formData);
                    if (result) {
                        messages.push(result);
                    }
                }

                // Determine final status
                const hasError = messages.some((msg) => msg.severity === 'error');
                const finalStatus: ValidationStatus = hasError ? 'invalid' : 'valid';

                // Update validation state
                setValidationState((prev) => {
                    const oldFieldState = prev.fields[fieldId];
                    // Use the stored status before 'validating' to enable correct counter calculation
                    const wasInvalid = oldStatusBeforeValidating === 'invalid';
                    const isNowInvalid = finalStatus === 'invalid';

                    // Calculate new counters
                    let newInvalidCount = prev.invalidCount;
                    if (wasInvalid && !isNowInvalid) {
                        newInvalidCount = Math.max(0, newInvalidCount - 1);
                    } else if (!wasInvalid && isNowInvalid) {
                        newInvalidCount += 1;
                    }

                    const newFields = {
                        ...prev.fields,
                        [fieldId]: {
                            status: finalStatus,
                            messages,
                            touched: oldFieldState?.touched ?? false,
                            value,
                        },
                    };

                    return {
                        ...prev,
                        fields: newFields,
                        invalidCount: newInvalidCount,
                        isValid: newInvalidCount === 0,
                    };
                });
            };

            // Execute either immediately or with debounce
            if (debounceTime > 0) {
                debounceTimers[fieldId] = setTimeout(executeValidation, debounceTime);
            } else {
                executeValidation();
            }
        },
        [debounceTimers, validationState.fields],
    );

    /**
     * Marks a field as "touched" (visited/focused)
     */
    const markFieldTouched = useCallback((fieldId: string) => {
        setValidationState((prev) => {
            const oldFieldState = prev.fields[fieldId];
            const wasTouched = oldFieldState?.touched ?? false;

            // If already touched, don't change anything
            if (wasTouched) {
                return prev;
            }

            return {
                ...prev,
                fields: {
                    ...prev.fields,
                    [fieldId]: {
                        status: oldFieldState?.status ?? 'idle',
                        messages: oldFieldState?.messages ?? [],
                        touched: true,
                        value: oldFieldState?.value,
                    },
                },
                touchedCount: prev.touchedCount + 1,
            };
        });
    }, []);

    /**
     * Returns the validation state of a field
     */
    const getFieldState = useCallback(
        (fieldId: string): FieldValidationState => {
            return (
                validationState.fields[fieldId] ?? {
                    status: 'idle',
                    messages: [],
                    touched: false,
                    value: undefined,
                }
            );
        },
        [validationState],
    );

    /**
     * Resets the validation of a field
     */
    const resetFieldValidation = useCallback(
        (fieldId: string) => {
            // Clear debounce timer
            if (debounceTimers[fieldId]) {
                clearTimeout(debounceTimers[fieldId]);
                delete debounceTimers[fieldId];
            }

            setValidationState((prev) => {
                const oldFieldState = prev.fields[fieldId];
                if (!oldFieldState) {
                    return prev;
                }

                const wasInvalid = oldFieldState.status === 'invalid';
                const wasTouched = oldFieldState.touched;

                // Remove field from state
                const newFields = { ...prev.fields };
                delete newFields[fieldId];

                return {
                    ...prev,
                    fields: newFields,
                    invalidCount: wasInvalid ? Math.max(0, prev.invalidCount - 1) : prev.invalidCount,
                    touchedCount: wasTouched ? Math.max(0, prev.touchedCount - 1) : prev.touchedCount,
                    isValid: wasInvalid ? prev.invalidCount - 1 === 0 : prev.isValid,
                };
            });
        },
        [debounceTimers],
    );

    /**
     * Resets all validation
     */
    const resetAllValidation = useCallback(() => {
        // Clear all debounce timers
        Object.values(debounceTimers).forEach((timer) => clearTimeout(timer));
        Object.keys(debounceTimers).forEach((key) => delete debounceTimers[key]);

        setValidationState({
            fields: {},
            isValid: true,
            touchedCount: 0,
            invalidCount: 0,
        });
    }, [debounceTimers]);

    /**
     * Checks if a field has an error
     */
    const hasFieldError = useCallback(
        (fieldId: string): boolean => {
            const fieldState = validationState.fields[fieldId];
            return fieldState?.messages.some((msg) => msg.severity === 'error') ?? false;
        },
        [validationState],
    );

    /**
     * Returns all validation messages of a field
     */
    const getFieldMessages = useCallback(
        (fieldId: string): ValidationMessage[] => {
            return validationState.fields[fieldId]?.messages ?? [];
        },
        [validationState],
    );

    /**
     * Sets external validation errors on fields (e.g., from backend 422 responses).
     * Marks affected fields as touched so errors are immediately visible.
     * Replaces any previously injected backend errors for those fields to prevent duplicates on re-submit.
     */
    const setFieldErrors = useCallback((errors: Array<{ fieldId: string; message: string }>) => {
        setValidationState((prev) => {
            const newFields = { ...prev.fields };
            let newInvalidCount = prev.invalidCount;
            let newTouchedCount = prev.touchedCount;

            // Collect all fieldIds that are being set so we can clear their old backend messages first
            const fieldIdsToSet = new Set(errors.map((e) => e.fieldId));

            // Clear previous backend-injected messages for affected fields (preserve client-side messages)
            for (const fieldId of fieldIdsToSet) {
                const oldState = newFields[fieldId];
                if (oldState) {
                    const wasInvalid = oldState.status === 'invalid';
                    const remainingMessages = oldState.messages.filter((msg) => msg.source !== 'backend');
                    const stillInvalid = remainingMessages.some((msg) => msg.severity === 'error');
                    newFields[fieldId] = {
                        ...oldState,
                        messages: remainingMessages,
                        status: stillInvalid ? 'invalid' : (remainingMessages.length > 0 ? oldState.status : 'valid'),
                    };
                    if (wasInvalid && !stillInvalid) {
                        newInvalidCount--;
                    }
                }
            }

            for (const { fieldId, message } of errors) {
                const oldState = newFields[fieldId];
                const wasInvalid = oldState?.status === 'invalid';
                const wasTouched = oldState?.touched ?? false;

                const existingMessages = oldState?.messages ?? [];
                // Deduplicate: skip if exact same error message already present
                const isDuplicate = existingMessages.some(
                    (msg) => msg.severity === 'error' && msg.message === message,
                );

                newFields[fieldId] = {
                    status: 'invalid',
                    messages: isDuplicate
                        ? existingMessages
                        : [...existingMessages, { severity: 'error', message, fieldId, source: 'backend' }],
                    touched: true,
                    value: oldState?.value,
                };

                if (!wasInvalid) newInvalidCount++;
                if (!wasTouched) newTouchedCount++;
            }

            return {
                fields: newFields,
                invalidCount: newInvalidCount,
                touchedCount: newTouchedCount,
                isValid: newInvalidCount === 0,
            };
        });
    }, []);

    /**
     * Clears validation errors for a specific field (e.g., when user starts editing).
     */
    const clearFieldErrors = useCallback(
        (fieldId: string) => {
            resetFieldValidation(fieldId);
        },
        [resetFieldValidation],
    );

    /**
     * Clears all backend-injected errors across all fields.
     * Call this at the start of every submit/draft-save to remove stale inline errors
     * while preserving any client-side validation messages.
     */
    const clearBackendErrors = useCallback(() => {
        setValidationState((prev) => {
            let changed = false;
            const newFields: Record<string, FieldValidationState> = {};
            let newInvalidCount = prev.invalidCount;
            let newTouchedCount = prev.touchedCount;

            for (const [fieldId, fieldState] of Object.entries(prev.fields)) {
                const remainingMessages = fieldState.messages.filter((msg) => msg.source !== 'backend');
                if (remainingMessages.length === fieldState.messages.length) {
                    newFields[fieldId] = fieldState;
                    continue;
                }

                changed = true;
                const wasInvalid = fieldState.status === 'invalid';
                const stillInvalid = remainingMessages.some((msg) => msg.severity === 'error');

                if (remainingMessages.length === 0) {
                    // Field has no remaining messages — remove entirely
                    if (wasInvalid) newInvalidCount = Math.max(0, newInvalidCount - 1);
                    if (fieldState.touched) newTouchedCount = Math.max(0, newTouchedCount - 1);
                    continue; // skip adding to newFields
                }

                newFields[fieldId] = {
                    ...fieldState,
                    messages: remainingMessages,
                    status: stillInvalid ? 'invalid' : 'valid',
                };

                if (wasInvalid && !stillInvalid) {
                    newInvalidCount = Math.max(0, newInvalidCount - 1);
                }
            }

            if (!changed) return prev;

            return {
                fields: newFields,
                invalidCount: newInvalidCount,
                touchedCount: newTouchedCount,
                isValid: newInvalidCount === 0,
            };
        });
    }, []);

    return {
        validationState,
        validateField,
        markFieldTouched,
        getFieldState,
        resetFieldValidation,
        resetAllValidation,
        hasFieldError,
        getFieldMessages,
        setFieldErrors,
        clearFieldErrors,
        clearBackendErrors,
    };
}

/**
 * Helper function: Creates a simple validation rule
 */
export function createValidationRule<T = unknown>(
    validate: (value: T, formData?: unknown) => ValidationMessage | null,
    options?: { debounce?: number },
): ValidationRule<T> {
    return {
        validate,
        debounce: options?.debounce,
    };
}

/**
 * Helper function: Combines multiple validation rules
 */
export function combineValidationRules<T = unknown>(...rules: ValidationRule<T>[]): ValidationRule<T>[] {
    return rules;
}
