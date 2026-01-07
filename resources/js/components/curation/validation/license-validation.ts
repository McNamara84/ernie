import type { ValidationRule } from '@/hooks/use-form-validation';
import { validateRequired } from '@/utils/validation-rules';

export const getPrimaryLicenseValidationRules = (): ValidationRule[] => [
    {
        validate: (value) => {
            const result = validateRequired(String(value || ''), 'Primary license');
            if (!result.isValid) {
                return { severity: 'error', message: result.error! };
            }

            return null;
        },
    },
];
