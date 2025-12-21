/**
 * Central validation rule utilities for form fields
 * Provides reusable validation functions for common patterns
 */

/**
 * DOI Format Validation
 * Accepts both formats:
 * - 10.xxxx/xxxxx
 * - https://doi.org/10.xxxx/xxxxx
 * Returns normalized DOI (10.xxxx/xxxxx format)
 */
export interface DOIValidationResult {
    isValid: boolean;
    normalizedDOI?: string;
    error?: string;
}

export function validateDOIFormat(doi: string): DOIValidationResult {
    if (!doi || doi.trim() === '') {
        return { isValid: false, error: 'DOI is required' };
    }

    const trimmedDOI = doi.trim();

    // Check for URL format: https://doi.org/10.xxxx/xxxxx
    const urlPattern = /^https?:\/\/doi\.org\/(10\.\d{4,}(?:\.\d+)*\/\S+)$/i;
    const urlMatch = trimmedDOI.match(urlPattern);

    if (urlMatch) {
        return {
            isValid: true,
            normalizedDOI: urlMatch[1],
        };
    }

    // Check for direct format: 10.xxxx/xxxxx
    const directPattern = /^10\.\d{4,}(?:\.\d+)*\/\S+$/;
    if (directPattern.test(trimmedDOI)) {
        return {
            isValid: true,
            normalizedDOI: trimmedDOI,
        };
    }

    return {
        isValid: false,
        error: 'Invalid DOI format. Use format: 10.xxxx/xxxxx or https://doi.org/10.xxxx/xxxxx',
    };
}

/**
 * Check DOI registration status via DataCite API
 */
export async function checkDOIRegistration(doi: string): Promise<{
    isRegistered: boolean;
    error?: string;
}> {
    try {
        const response = await fetch(`https://api.datacite.org/dois/${encodeURIComponent(doi)}`, {
            method: 'GET',
            headers: {
                Accept: 'application/vnd.api+json',
            },
        });

        if (response.status === 200) {
            return { isRegistered: true };
        }

        if (response.status === 404) {
            return { isRegistered: false };
        }

        return {
            isRegistered: false,
            error: 'Could not verify DOI registration status',
        };
    } catch {
        return {
            isRegistered: false,
            error: 'Network error while checking DOI registration',
        };
    }
}

/**
 * Year Validation
 * Valid range: 1900 to current year + 1
 */
export function validateYear(year: string | number): {
    isValid: boolean;
    error?: string;
} {
    const yearNum = typeof year === 'string' ? parseInt(year, 10) : year;

    if (isNaN(yearNum)) {
        return { isValid: false, error: 'Year must be a valid number' };
    }

    const currentYear = new Date().getFullYear();
    const minYear = 1900;
    const maxYear = currentYear + 1;

    if (yearNum < minYear || yearNum > maxYear) {
        return {
            isValid: false,
            error: `Year must be between ${minYear} and ${maxYear}`,
        };
    }

    return { isValid: true };
}

/**
 * Semantic Versioning Validation
 * Format: MAJOR.MINOR.PATCH (e.g., 1.2.3)
 * Optional pre-release and build metadata allowed
 */
export function validateSemanticVersion(version: string): {
    isValid: boolean;
    error?: string;
} {
    if (!version || version.trim() === '') {
        return { isValid: true }; // Version is optional
    }

    // Semantic versioning pattern (strict)
    const semverPattern =
        /^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-((?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*)(?:\.(?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*))*))?(?:\+([0-9a-zA-Z-]+(?:\.[0-9a-zA-Z-]+)*))?$/;

    if (!semverPattern.test(version.trim())) {
        return {
            isValid: false,
            error: 'Invalid semantic version. Use format: MAJOR.MINOR.PATCH (e.g., 1.2.3)',
        };
    }

    return { isValid: true };
}

/**
 * ORCID Format and Checksum Validation
 * Format: 0000-0001-2345-6789 (with or without https://orcid.org/ prefix)
 * Includes checksum digit validation
 */
export function validateORCID(orcid: string): {
    isValid: boolean;
    normalizedORCID?: string;
    error?: string;
} {
    if (!orcid || orcid.trim() === '') {
        return { isValid: true }; // ORCID is optional
    }

    const trimmedORCID = orcid.trim();

    // Remove URL prefix if present
    let orcidDigits = trimmedORCID;
    if (trimmedORCID.startsWith('https://orcid.org/')) {
        orcidDigits = trimmedORCID.replace('https://orcid.org/', '');
    } else if (trimmedORCID.startsWith('http://orcid.org/')) {
        orcidDigits = trimmedORCID.replace('http://orcid.org/', '');
    }

    // Check format: xxxx-xxxx-xxxx-xxx[X]
    const orcidPattern = /^(\d{4})-(\d{4})-(\d{4})-(\d{3}[0-9X])$/;
    const match = orcidDigits.match(orcidPattern);

    if (!match) {
        return {
            isValid: false,
            error: 'Invalid ORCID format. Use format: 0000-0001-2345-6789',
        };
    }

    // Validate checksum using ISO/IEC 7064:2003, MOD 11-2
    const digits = orcidDigits.replace(/-/g, '');
    const baseDigits = digits.slice(0, -1);
    const checkDigit = digits.slice(-1);

    let total = 0;
    for (const digit of baseDigits) {
        total = (total + parseInt(digit, 10)) * 2;
    }

    const remainder = total % 11;
    const result = (12 - remainder) % 11;
    const expectedCheckDigit = result === 10 ? 'X' : result.toString();

    if (checkDigit !== expectedCheckDigit) {
        return {
            isValid: false,
            error: 'Invalid ORCID checksum. Please verify the ORCID.',
        };
    }

    return {
        isValid: true,
        normalizedORCID: orcidDigits,
    };
}

/**
 * Email Format Validation
 * Uses comprehensive RFC 5322 compliant pattern
 */
export function validateEmail(email: string): {
    isValid: boolean;
    error?: string;
} {
    if (!email || email.trim() === '') {
        return { isValid: false, error: 'Email is required' };
    }

    // RFC 5322 compliant email pattern (simplified but robust)
    const emailPattern =
        /^[a-zA-Z0-9.!#$%&'*+/=?^_`{|}~-]+@[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*$/;

    if (!emailPattern.test(email.trim())) {
        return {
            isValid: false,
            error: 'Invalid email format',
        };
    }

    return { isValid: true };
}

/**
 * Date Validation
 * Minimum date: 01.01.1900
 * Maximum date: today (no future dates allowed for 'Created' field)
 */
export function validateDate(
    dateString: string,
    options: {
        allowFuture?: boolean;
        minDate?: Date;
        maxDate?: Date;
    } = {},
): {
    isValid: boolean;
    error?: string;
} {
    if (!dateString || dateString.trim() === '') {
        return { isValid: false, error: 'Date is required' };
    }

    const date = new Date(dateString);

    if (isNaN(date.getTime())) {
        return { isValid: false, error: 'Invalid date format' };
    }

    const minDate = options.minDate || new Date('1900-01-01');
    const maxDate = options.maxDate || (options.allowFuture ? null : new Date());

    if (date < minDate) {
        return {
            isValid: false,
            error: `Date must be on or after ${minDate.toLocaleDateString()}`,
        };
    }

    if (maxDate && date > maxDate) {
        return {
            isValid: false,
            error: options.allowFuture ? `Date must be on or before ${maxDate.toLocaleDateString()}` : 'Date cannot be in the future',
        };
    }

    return { isValid: true };
}

/**
 * Text Length Validation
 * Generic length validator with min/max constraints
 */
export function validateTextLength(
    text: string,
    options: {
        min?: number;
        max?: number;
        fieldName?: string;
    },
): {
    isValid: boolean;
    error?: string;
    warning?: string;
} {
    const trimmedText = text?.trim() || '';
    const length = trimmedText.length;
    const fieldName = options.fieldName || 'Field';

    if (options.min && length < options.min) {
        return {
            isValid: false,
            error: `${fieldName} must be at least ${options.min} characters (current: ${length})`,
        };
    }

    if (options.max && length > options.max) {
        return {
            isValid: false,
            error: `${fieldName} must not exceed ${options.max} characters (current: ${length})`,
        };
    }

    // Warning if approaching max
    if (options.max && length > options.max * 0.9) {
        return {
            isValid: true,
            warning: `${fieldName} is approaching maximum length (${length}/${options.max})`,
        };
    }

    return { isValid: true };
}

/**
 * Required Field Validation
 * Simple check if field is not empty
 */
export function validateRequired(
    value: string | null | undefined,
    fieldName: string = 'Field',
): {
    isValid: boolean;
    error?: string;
} {
    if (!value || value.trim() === '') {
        return {
            isValid: false,
            error: `${fieldName} is required`,
        };
    }

    return { isValid: true };
}

/**
 * Title Uniqueness Check
 * Validates that titles are unique (case-sensitive)
 * Only marks newer duplicates as errors, keeping the first occurrence valid
 */
export function validateTitleUniqueness(titles: Array<{ title: string; type: string }>): {
    isValid: boolean;
    errors: Record<number, string>;
} {
    const errors: Record<number, string> = {};
    const seen = new Map<string, number>(); // Maps title to first index

    titles.forEach((t, idx) => {
        const trimmedTitle = t.title.trim();

        // Skip empty titles
        if (trimmedTitle === '') {
            return;
        }

        // Case-sensitive comparison
        if (seen.has(trimmedTitle)) {
            // This is a duplicate - mark it with error
            errors[idx] = 'This title already exists';
        } else {
            // First occurrence - record it
            seen.set(trimmedTitle, idx);
        }
    });

    return {
        isValid: Object.keys(errors).length === 0,
        errors,
    };
}
