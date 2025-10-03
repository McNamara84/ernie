import { describe, expect, it } from 'vitest';
import { resolveInitialLanguageCode, type LanguageOption } from '@/components/curation/utils/language-resolver';

const baseLanguages: LanguageOption[] = [
    { code: 'en', name: 'English' },
    { code: 'de', name: 'German' },
    { code: 'fr', name: 'French' },
];

describe('resolveInitialLanguageCode', () => {
    it('returns the initial language when provided', () => {
        expect(resolveInitialLanguageCode(baseLanguages, 'de')).toBe('de');
        expect(resolveInitialLanguageCode(baseLanguages, 'German')).toBe('de');
    });

    it('falls back to English when no initial language is provided', () => {
        expect(resolveInitialLanguageCode(baseLanguages, undefined)).toBe('en');
        expect(resolveInitialLanguageCode(baseLanguages, '')).toBe('en');
    });

    it('matches initial codes with hyphenated variants', () => {
        const languages: LanguageOption[] = [
            { code: 'en-US', name: 'English (US)' },
            { code: 'en-GB', name: 'English (UK)' },
        ];

        expect(resolveInitialLanguageCode(languages, 'en-gb')).toBe('en-GB');
        expect(resolveInitialLanguageCode(languages, 'en')).toBe('en-US');
    });

    it('prefers English even if it is not the first language', () => {
        const shuffled: LanguageOption[] = [
            { code: 'de', name: 'German' },
            { code: 'fr', name: 'French' },
            { code: 'en', name: 'English' },
        ];

        expect(resolveInitialLanguageCode(shuffled)).toBe('en');
    });

    it('returns the first language with a code when English is unavailable', () => {
        const languages: LanguageOption[] = [
            { code: '', name: '' },
            { code: 'de', name: 'German' },
        ];

        expect(resolveInitialLanguageCode(languages)).toBe('de');
    });

    it('falls back to an empty string when no codes are present', () => {
        const languages: LanguageOption[] = [
            { code: '', name: '' },
            { code: null, name: 'Français' },
        ];

        expect(resolveInitialLanguageCode(languages)).toBe('');
    });
});
