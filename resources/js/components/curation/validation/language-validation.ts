import type { ValidationRule } from '@/hooks/use-form-validation';
import { validateRequired } from '@/utils/validation-rules';

export const getLanguageValidationRules = (): ValidationRule[] => [
    {
        validate: (value) => {
            const result = validateRequired(String(value || ''), 'Language');
            if (!result.isValid) {
                return { severity: 'error', message: result.error! };
            }

            return null;
        },
    },
];
