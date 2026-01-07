import type { ValidationRule } from '@/hooks/use-form-validation';
import { validateRequired, validateTextLength } from '@/utils/validation-rules';

// Debounce prevents performance issues: validateRequired and validateTextLength are fast,
// but frequent re-renders during rapid typing can cause lag. 300ms balances responsiveness
// with preventing excessive validation calls during continuous typing.
export const getAbstractValidationRules = (): ValidationRule[] => [
    {
        debounce: 300,
        validate: (value) => {
            const text = String(value || '');

            // Required check
            const requiredResult = validateRequired(text, 'Abstract');
            if (!requiredResult.isValid) {
                return { severity: 'error', message: requiredResult.error! };
            }

            // Length check (50-17500 characters)
            const lengthResult = validateTextLength(text, {
                min: 50,
                max: 17500,
                fieldName: 'Abstract',
            });
            if (!lengthResult.isValid) {
                return { severity: 'error', message: lengthResult.error! };
            }

            // Warning at 90% of max length
            if (text.length > 15750) {
                // 90% of 17500
                return {
                    severity: 'warning',
                    message: `Abstract is very long (${text.length}/17500 characters). Consider condensing if possible.`,
                };
            }

            return null;
        },
    },
];
