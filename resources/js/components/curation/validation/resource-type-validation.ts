import type { ValidationRule } from '@/hooks/use-form-validation';
import { validateRequired } from '@/utils/validation-rules';

export const getResourceTypeValidationRules = (): ValidationRule[] => [
    {
        validate: (value) => {
            const result = validateRequired(String(value || ''), 'Resource Type');
            if (!result.isValid) {
                return { severity: 'error', message: result.error! };
            }

            return null;
        },
    },
];
