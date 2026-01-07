import type { ValidationRule } from '@/hooks/use-form-validation';
import { validateSemanticVersion } from '@/utils/validation-rules';

export const getVersionValidationRules = (): ValidationRule[] => [
    {
        validate: (value) => {
            if (!value || String(value).trim() === '') {
                return null; // Version is optional
            }

            const result = validateSemanticVersion(String(value));
            if (!result.isValid) {
                return { severity: 'error', message: result.error! };
            }

            return null;
        },
    },
];
