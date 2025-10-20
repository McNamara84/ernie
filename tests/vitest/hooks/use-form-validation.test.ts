import { act, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { createValidationRule, useFormValidation } from '@/hooks/use-form-validation';

describe('useFormValidation', () => {
    beforeEach(() => {
        vi.useFakeTimers();
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    describe('Basic functionality', () => {
        it('should initialize with empty state', () => {
            const { result } = renderHook(() => useFormValidation());

            expect(result.current.validationState).toEqual({
                fields: {},
                isValid: true,
                touchedCount: 0,
                invalidCount: 0,
            });
        });

        it('should return idle state for non-existent field', () => {
            const { result } = renderHook(() => useFormValidation());

            const fieldState = result.current.getFieldState('nonExistent');

            expect(fieldState).toEqual({
                status: 'idle',
                messages: [],
                touched: false,
                value: undefined,
            });
        });
    });

    describe('Field validation', () => {
        it('should validate field with passing rule', () => {
            const { result } = renderHook(() => useFormValidation());

            const rule = createValidationRule<string>((value) => {
                if (!value) {
                    return { severity: 'error', message: 'Required' };
                }
                return { severity: 'success', message: 'Valid' };
            });

            act(() => {
                result.current.validateField({
                    fieldId: 'test',
                    value: 'test value',
                    rules: [rule],
                    immediate: true,
                });
            });

            const fieldState = result.current.getFieldState('test');
            expect(fieldState.status).toBe('valid');
            expect(fieldState.messages).toHaveLength(1);
            expect(fieldState.messages[0].severity).toBe('success');
        });

        it('should validate field with failing rule', () => {
            const { result } = renderHook(() => useFormValidation());

            const rule = createValidationRule<string>((value) => {
                if (!value) {
                    return { severity: 'error', message: 'Required' };
                }
                return null;
            });

            act(() => {
                result.current.validateField({
                    fieldId: 'test',
                    value: '',
                    rules: [rule],
                    immediate: true,
                });
            });

            const fieldState = result.current.getFieldState('test');
            expect(fieldState.status).toBe('invalid');
            expect(fieldState.messages).toHaveLength(1);
            expect(fieldState.messages[0].severity).toBe('error');
        });

        it('should handle multiple validation rules', () => {
            const { result } = renderHook(() => useFormValidation());

            const requiredRule = createValidationRule<string>((value) => {
                if (!value) {
                    return { severity: 'error', message: 'Required' };
                }
                return null;
            });

            const lengthRule = createValidationRule<string>((value) => {
                if (value && value.length < 5) {
                    return { severity: 'warning', message: 'Too short' };
                }
                return null;
            });

            act(() => {
                result.current.validateField({
                    fieldId: 'test',
                    value: 'abc',
                    rules: [requiredRule, lengthRule],
                    immediate: true,
                });
            });

            const fieldState = result.current.getFieldState('test');
            expect(fieldState.messages).toHaveLength(1);
            expect(fieldState.messages[0].message).toBe('Too short');
        });

        it('should debounce validation when specified', () => {
            const { result } = renderHook(() => useFormValidation());

            const rule = createValidationRule<string>(
                (value) => {
                    return value ? { severity: 'success', message: 'Valid' } : null;
                },
                { debounce: 500 },
            );

            act(() => {
                result.current.validateField({
                    fieldId: 'test',
                    value: 'test',
                    rules: [rule],
                });
            });

            // Immediately after call, status should still be "idle" or "validating"
            let fieldState = result.current.getFieldState('test');
            expect(fieldState.status).not.toBe('valid');

            // After debounce, validation should be completed
            act(() => {
                vi.advanceTimersByTime(500);
            });

            fieldState = result.current.getFieldState('test');
            expect(fieldState.status).toBe('valid');
        });
    });

    describe('Touch tracking', () => {
        it('should mark field as touched', () => {
            const { result } = renderHook(() => useFormValidation());

            act(() => {
                result.current.markFieldTouched('test');
            });

            const fieldState = result.current.getFieldState('test');
            expect(fieldState.touched).toBe(true);
            expect(result.current.validationState.touchedCount).toBe(1);
        });

        it('should not increment touchedCount if field already touched', () => {
            const { result } = renderHook(() => useFormValidation());

            act(() => {
                result.current.markFieldTouched('test');
                result.current.markFieldTouched('test');
            });

            expect(result.current.validationState.touchedCount).toBe(1);
        });
    });

    describe('Invalid count tracking', () => {
        it('should track invalid fields count', () => {
            const { result } = renderHook(() => useFormValidation());

            const errorRule = createValidationRule<string>(() => ({
                severity: 'error',
                message: 'Error',
            }));

            act(() => {
                result.current.validateField({
                    fieldId: 'field1',
                    value: '',
                    rules: [errorRule],
                    immediate: true,
                });

                result.current.validateField({
                    fieldId: 'field2',
                    value: '',
                    rules: [errorRule],
                    immediate: true,
                });
            });

            expect(result.current.validationState.invalidCount).toBe(2);
            expect(result.current.validationState.isValid).toBe(false);
        });

        it('should update isValid when all errors are fixed', () => {
            const { result } = renderHook(() => useFormValidation());

            const conditionalRule = createValidationRule<string>((value) => {
                if (!value) {
                    return { severity: 'error', message: 'Required' };
                }
                return { severity: 'success', message: 'Valid' };
            });

            // Zuerst invalid
            act(() => {
                result.current.validateField({
                    fieldId: 'test',
                    value: '',
                    rules: [conditionalRule],
                    immediate: true,
                });
            });

            expect(result.current.validationState.isValid).toBe(false);

            // Dann valid
            act(() => {
                result.current.validateField({
                    fieldId: 'test',
                    value: 'filled',
                    rules: [conditionalRule],
                    immediate: true,
                });
            });

            expect(result.current.validationState.isValid).toBe(true);
            expect(result.current.validationState.invalidCount).toBe(0);
        });
    });

    describe('Helper functions', () => {
        it('hasFieldError should return true for fields with errors', () => {
            const { result } = renderHook(() => useFormValidation());

            const rule = createValidationRule<string>(() => ({
                severity: 'error',
                message: 'Error',
            }));

            act(() => {
                result.current.validateField({
                    fieldId: 'test',
                    value: '',
                    rules: [rule],
                    immediate: true,
                });
            });

            expect(result.current.hasFieldError('test')).toBe(true);
            expect(result.current.hasFieldError('nonExistent')).toBe(false);
        });

        it('getFieldMessages should return all messages for a field', () => {
            const { result } = renderHook(() => useFormValidation());

            const rules = [
                createValidationRule<string>(() => ({
                    severity: 'error',
                    message: 'Error message',
                })),
                createValidationRule<string>(() => ({
                    severity: 'warning',
                    message: 'Warning message',
                })),
            ];

            act(() => {
                result.current.validateField({
                    fieldId: 'test',
                    value: '',
                    rules,
                    immediate: true,
                });
            });

            const messages = result.current.getFieldMessages('test');
            expect(messages).toHaveLength(2);
            expect(messages[0].severity).toBe('error');
            expect(messages[1].severity).toBe('warning');
        });
    });

    describe('Reset functionality', () => {
        it('should reset single field validation', () => {
            const { result } = renderHook(() => useFormValidation());

            const rule = createValidationRule<string>(() => ({
                severity: 'error',
                message: 'Error',
            }));

            act(() => {
                result.current.validateField({
                    fieldId: 'test',
                    value: '',
                    rules: [rule],
                    immediate: true,
                });
                result.current.markFieldTouched('test');
            });

            expect(result.current.validationState.invalidCount).toBe(1);

            act(() => {
                result.current.resetFieldValidation('test');
            });

            const fieldState = result.current.getFieldState('test');
            expect(fieldState.status).toBe('idle');
            expect(result.current.validationState.invalidCount).toBe(0);
        });

        it('should reset all validation', () => {
            const { result } = renderHook(() => useFormValidation());

            const rule = createValidationRule<string>(() => ({
                severity: 'error',
                message: 'Error',
            }));

            act(() => {
                result.current.validateField({
                    fieldId: 'field1',
                    value: '',
                    rules: [rule],
                    immediate: true,
                });
                result.current.validateField({
                    fieldId: 'field2',
                    value: '',
                    rules: [rule],
                    immediate: true,
                });
                result.current.markFieldTouched('field1');
            });

            expect(result.current.validationState.invalidCount).toBe(2);

            act(() => {
                result.current.resetAllValidation();
            });

            expect(result.current.validationState).toEqual({
                fields: {},
                isValid: true,
                touchedCount: 0,
                invalidCount: 0,
            });
        });
    });

    describe('FormData context', () => {
        it('should pass formData to validation rules', () => {
            const { result } = renderHook(() => useFormValidation());

            const contextualRule = createValidationRule<string>((value, formData) => {
                const context = formData as { minLength: number } | undefined;
                if (value && context && value.length < context.minLength) {
                    return {
                        severity: 'error',
                        message: `Must be at least ${context.minLength} characters`,
                    };
                }
                return null;
            });

            act(() => {
                result.current.validateField({
                    fieldId: 'test',
                    value: 'abc',
                    rules: [contextualRule],
                    formData: { minLength: 5 },
                    immediate: true,
                });
            });

            const messages = result.current.getFieldMessages('test');
            expect(messages).toHaveLength(1);
            expect(messages[0].message).toContain('5 characters');
        });
    });
});
