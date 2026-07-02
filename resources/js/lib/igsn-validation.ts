export interface IgsnValidationResult {
    isValid: boolean;
    doi?: string;
    handle?: string;
    message?: string;
}

export function normalizeIgsnInput(input: string, igsnPrefix = '10.60510'): IgsnValidationResult {
    const normalizedPrefix = igsnPrefix.trim().toLowerCase();
    const trimmed = input.trim();
    if (trimmed.length === 0) {
        return {
            isValid: false,
            message: 'Enter an IGSN.',
        };
    }

    const doiUrlMatch = trimmed.match(/^https?:\/\/(?:doi\.org|dx\.doi\.org)\/(.+)/i);
    const value = doiUrlMatch ? doiUrlMatch[1].trim() : trimmed;
    const lowerValue = value.toLowerCase();

    let handle = value;
    if (lowerValue.startsWith(`${normalizedPrefix}/`)) {
        handle = value.slice(normalizedPrefix.length + 1);
    } else if (lowerValue.startsWith('10.')) {
        return {
            isValid: false,
            message: `Use the IGSN prefix ${normalizedPrefix} or enter the IGSN handle only.`,
        };
    }

    const normalizedHandle = handle.trim().toUpperCase();
    if (!/^[A-Z0-9][A-Z0-9._-]{0,199}$/.test(normalizedHandle)) {
        return {
            isValid: false,
            message: 'Enter a valid IGSN handle, for example ICDP5052EUYY001.',
        };
    }

    return {
        isValid: true,
        doi: `${normalizedPrefix}/${normalizedHandle.toLowerCase()}`,
        handle: normalizedHandle,
    };
}
