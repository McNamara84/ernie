export interface LanguageOption {
    code?: string | null;
    name?: string | null;
}

const normalize = (value?: string | null) => value?.trim().toLowerCase() ?? '';

const findLanguageCode = (languages: LanguageOption[], ...rawCandidates: (string | null | undefined)[]): string => {
    const candidates = rawCandidates.map((candidate) => normalize(candidate)).filter(Boolean);

    for (const candidate of candidates) {
        const exactMatch = languages.find((lang) => normalize(lang.code) === candidate || normalize(lang.name) === candidate);

        if (exactMatch?.code) {
            return exactMatch.code;
        }
    }

    for (const candidate of candidates) {
        const base = candidate.split('-')[0];
        const baseMatch = languages.find((lang) => normalize(lang.code).split('-')[0] === base);

        if (baseMatch?.code) {
            return baseMatch.code;
        }
    }

    return '';
};

export function resolveInitialLanguageCode(languages: LanguageOption[], initialLanguage?: string | null): string {
    return findLanguageCode(languages, initialLanguage);
}
