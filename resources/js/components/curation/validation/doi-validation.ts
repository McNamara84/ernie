import type { ValidationRule } from '@/hooks/use-form-validation';
import { validateDOIFormat } from '@/utils/validation-rules';

export const getDoiValidationRules = (): ValidationRule[] => [
    {
        validate: (value) => {
            if (!value || String(value).trim() === '') {
                return null; // DOI is optional at this stage
            }

            const result = validateDOIFormat(String(value));
            if (!result.isValid) {
                return { severity: 'error', message: result.error! };
            }

            return null;
        },
    },
    // TODO: Add async DOI registration check in separate effect
];
