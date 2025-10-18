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
 * A single validation message
 */
export interface ValidationMessage {
    severity: ValidationSeverity;
    message: string;
    fieldId?: string;
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
            const debounceTime = immediate
                ? 0
                : Math.max(0, ...rules.map((rule) => rule.debounce ?? 0));

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
    const resetFieldValidation = useCallback((fieldId: string) => {
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
    }, [debounceTimers]);

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

    return {
        validationState,
        validateField,
        markFieldTouched,
        getFieldState,
        resetFieldValidation,
        resetAllValidation,
        hasFieldError,
        getFieldMessages,
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
export function combineValidationRules<T = unknown>(
    ...rules: ValidationRule<T>[]
): ValidationRule<T>[] {
    return rules;
}
