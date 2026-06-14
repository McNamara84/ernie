const IGSN_PREFIX = '10.60510';

export interface IgsnValidationResult {
    isValid: boolean;
    doi?: string;
    handle?: string;
    message?: string;
}

export function normalizeIgsnInput(input: string): IgsnValidationResult {
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
    if (lowerValue.startsWith(`${IGSN_PREFIX}/`)) {
        handle = value.slice(IGSN_PREFIX.length + 1);
    } else if (lowerValue.startsWith('10.')) {
        return {
            isValid: false,
            message: `Use the IGSN prefix ${IGSN_PREFIX} or enter the IGSN handle only.`,
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
        doi: `${IGSN_PREFIX}/${normalizedHandle.toLowerCase()}`,
        handle: normalizedHandle,
    };
}
