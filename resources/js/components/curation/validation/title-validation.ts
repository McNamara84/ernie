import type { ValidationRule } from '@/hooks/use-form-validation';
import { validateRequired, validateTextLength, validateTitleUniqueness } from '@/utils/validation-rules';

type TitleForValidation = {
    title: string;
    titleType: string;
};

export const createTitleValidationRules = (index: number, titleType: string, allTitles: TitleForValidation[]): ValidationRule[] => [
    {
        validate: (value) => {
            const titleValue = String(value || '');

            // Main title is required
            if (titleType === 'main-title') {
                const requiredResult = validateRequired(titleValue, 'Main title');
                if (!requiredResult.isValid) {
                    return { severity: 'error', message: requiredResult.error! };
                }
            }

            // If title is provided (for any type), validate length
            if (titleValue.trim() !== '') {
                const lengthResult = validateTextLength(titleValue, {
                    min: 1,
                    max: 325,
                    fieldName: 'Title',
                });
                if (!lengthResult.isValid) {
                    return {
                        severity: lengthResult.warning ? 'warning' : 'error',
                        message: lengthResult.error || lengthResult.warning!,
                    };
                }
            }

            // Check uniqueness across all titles
            const uniquenessResult = validateTitleUniqueness(allTitles.map((t) => ({ title: t.title, type: t.titleType })));
            if (!uniquenessResult.isValid && uniquenessResult.errors[index]) {
                return {
                    severity: 'error',
                    message: uniquenessResult.errors[index],
                };
            }

            return null;
        },
    },
];
