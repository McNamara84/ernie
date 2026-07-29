import { describe, expect, it } from 'vitest';

import { type LanguageOption, resolveInitialLanguageCode } from '@/components/curation/utils/language-resolver';

const baseLanguages: LanguageOption[] = [
    { code: 'en', name: 'English' },
    { code: 'de', name: 'German' },
    { code: 'fr', name: 'French' },
];

describe('resolveInitialLanguageCode', () => {
    it('returns a recognized initial language by code or name', () => {
        expect(resolveInitialLanguageCode(baseLanguages, 'de')).toBe('de');
        expect(resolveInitialLanguageCode(baseLanguages, 'German')).toBe('de');
    });

    it('leaves the language empty when no initial value is provided', () => {
        expect(resolveInitialLanguageCode(baseLanguages, undefined)).toBe('');
        expect(resolveInitialLanguageCode(baseLanguages, null)).toBe('');
        expect(resolveInitialLanguageCode(baseLanguages, '')).toBe('');
        expect(resolveInitialLanguageCode(baseLanguages, '   ')).toBe('');
    });

    it('matches exact and base codes with hyphenated variants', () => {
        const languages: LanguageOption[] = [
            { code: 'en-US', name: 'English (US)' },
            { code: 'en-GB', name: 'English (UK)' },
        ];

        expect(resolveInitialLanguageCode(languages, 'en-gb')).toBe('en-GB');
        expect(resolveInitialLanguageCode(languages, 'en')).toBe('en-US');
    });

    it('does not default to English or the first configured language', () => {
        const shuffled: LanguageOption[] = [
            { code: 'de', name: 'German' },
            { code: 'fr', name: 'French' },
            { code: 'en', name: 'English' },
        ];

        expect(resolveInitialLanguageCode(shuffled)).toBe('');
    });

    it('leaves unsupported initial values empty instead of substituting another language', () => {
        expect(resolveInitialLanguageCode(baseLanguages, 'es')).toBe('');
        expect(resolveInitialLanguageCode(baseLanguages, 'Spanish')).toBe('');
    });

    it('ignores incomplete language options', () => {
        const languages: LanguageOption[] = [
            { code: '', name: '' },
            { code: null, name: 'Français' },
        ];

        expect(resolveInitialLanguageCode(languages, 'Français')).toBe('');
    });
});
