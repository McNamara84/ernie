export interface LanguageOption {
    code?: string | null;
    name?: string | null;
}

const normalize = (value?: string | null) => value?.trim().toLowerCase() ?? '';

const expandCandidate = (value?: string | null): string[] => {
    const normalized = normalize(value);

    if (!normalized) {
        return [];
    }

    const base = normalized.split('-')[0];
    const candidates = new Set<string>([normalized]);

    if (base && base !== normalized) {
        candidates.add(base);
    }

    return [...candidates];
};

const findLanguageCode = (languages: LanguageOption[], ...rawCandidates: (string | null | undefined)[]): string => {
    const candidates = new Set(rawCandidates.flatMap((candidate) => expandCandidate(candidate)));

    if (!candidates.size) {
        return '';
    }

    return (
        languages.find((lang) => {
            const code = normalize(lang.code);
            const name = normalize(lang.name);

            return (!!code && candidates.has(code)) || (!!name && candidates.has(name));
        })?.code ?? ''
    );
};

export function resolveInitialLanguageCode(languages: LanguageOption[], initialLanguage?: string | null): string {
    const initialMatch = findLanguageCode(languages, initialLanguage);
    if (initialMatch) {
        return initialMatch;
    }

    const englishMatch = findLanguageCode(languages, 'english', 'en');
    if (englishMatch) {
        return englishMatch;
    }

    const firstWithCode = languages.find((lang) => lang.code?.trim())?.code ?? '';
    if (firstWithCode) {
        return firstWithCode;
    }

    return languages.find((lang) => lang.name?.trim())?.code ?? '';
}
