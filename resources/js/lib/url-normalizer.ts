const normalizeDoubleScheme = (value: string): string => {
    // Fix patterns like: https://https//example.com/path  -> https://example.com/path
    // Also tolerates https://https://example.com/path     -> https://example.com/path
    return value.replace(/^(https?:\/\/)(https?)(?::)?\/{2}/i, '$1');
};

const normalizeMissingSchemeColon = (value: string): string => {
    // Fix patterns like: https//example.com -> https://example.com
    return value.replace(/^(https?)(\/\/)/i, '$1:$2');
};

const collapseSchemeSlashes = (value: string): string => {
    // Fix patterns like: https:////example.com -> https://example.com
    return value.replace(/^(https?:)\/{3,}/i, '$1//');
};

export const normalizeUrlLike = (raw: string): string => {
    const trimmed = raw.trim();

    // Never touch relative URLs.
    if (trimmed.startsWith('/')) {
        return trimmed;
    }

    // Only normalize likely-http-like strings.
    if (!/^https?/i.test(trimmed)) {
        return trimmed;
    }

    return collapseSchemeSlashes(normalizeMissingSchemeColon(normalizeDoubleScheme(trimmed)));
};
