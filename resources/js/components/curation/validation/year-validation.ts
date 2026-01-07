import type { ValidationRule } from '@/hooks/use-form-validation';
import { validateRequired, validateYear } from '@/utils/validation-rules';

export const getYearValidationRules = (): ValidationRule[] => [
    {
        validate: (value) => {
            const requiredResult = validateRequired(String(value || ''), 'Year');
            if (!requiredResult.isValid) {
                return { severity: 'error', message: requiredResult.error! };
            }

            const yearResult = validateYear(String(value));
            if (!yearResult.isValid) {
                return { severity: 'error', message: yearResult.error! };
            }

            return null;
        },
    },
];
