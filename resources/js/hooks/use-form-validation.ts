import { useCallback, useState } from 'react';

/**
 * Status eines Validierungsprozesses
 */
export type ValidationStatus = 'idle' | 'validating' | 'valid' | 'invalid';

/**
 * Schweregrad einer Validierungsnachricht
 */
export type ValidationSeverity = 'error' | 'warning' | 'success' | 'info';

/**
 * Eine einzelne Validierungsnachricht
 */
export interface ValidationMessage {
    severity: ValidationSeverity;
    message: string;
    fieldId?: string;
}

/**
 * Validierungszustand eines einzelnen Feldes
 */
export interface FieldValidationState {
    status: ValidationStatus;
    messages: ValidationMessage[];
    touched: boolean;
    value: unknown;
}

/**
 * Gesamter Validierungszustand des Formulars
 */
export interface FormValidationState {
    fields: Record<string, FieldValidationState>;
    isValid: boolean;
    touchedCount: number;
    invalidCount: number;
}

/**
 * Eine Validierungsregel für ein Feld
 */
export type ValidationRule<T = unknown> = {
    validate: (value: T, formData?: unknown) => ValidationMessage | null;
    debounce?: number;
};

/**
 * Optionen für die Feldvalidierung
 */
export interface ValidateFieldOptions<T = unknown> {
    fieldId: string;
    value: T;
    rules: ValidationRule<T>[];
    formData?: unknown;
    immediate?: boolean;
}

/**
 * Rückgabewert des useFormValidation Hooks
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
 * Hook für Formular-Validierung mit Echtzeit-Feedback
 * 
 * Dieser Hook verwaltet den Validierungszustand aller Formularfelder
 * und bietet Funktionen zur Validierung einzelner Felder.
 * 
 * @example
 * ```tsx
 * const { validateField, getFieldState, markFieldTouched } = useFormValidation();
 * 
 * // Feld validieren
 * validateField({
 *   fieldId: 'email',
 *   value: emailValue,
 *   rules: [emailValidationRule],
 * });
 * 
 * // Feld-Status abrufen
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

    // Debounce-Timer-Referenzen
    const debounceTimers = useState<Record<string, NodeJS.Timeout>>(() => ({}))[0];

    /**
     * Validiert ein einzelnes Feld mit den gegebenen Regeln
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
     * Markiert ein Feld als "touched" (berührt/fokussiert)
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
     * Gibt den Validierungszustand eines Feldes zurück
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
     * Setzt die Validierung eines Feldes zurück
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

            // Entferne Feld aus State
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
     * Setzt die gesamte Validierung zurück
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
     * Prüft ob ein Feld einen Fehler hat
     */
    const hasFieldError = useCallback(
        (fieldId: string): boolean => {
            const fieldState = validationState.fields[fieldId];
            return fieldState?.messages.some((msg) => msg.severity === 'error') ?? false;
        },
        [validationState],
    );

    /**
     * Gibt alle Validierungsnachrichten eines Feldes zurück
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
 * Helper-Funktion: Erstellt eine einfache Validierungsregel
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
 * Helper-Funktion: Kombiniert mehrere Validierungsregeln
 */
export function combineValidationRules<T = unknown>(
    ...rules: ValidationRule<T>[]
): ValidationRule<T>[] {
    return rules;
}
