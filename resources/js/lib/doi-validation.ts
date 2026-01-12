/**
 * DOI Validation and DataCite API utilities
 */

export interface DataCiteMetadata {
    title?: string;
    creators?: string[];
    publicationYear?: number;
    publisher?: string;
    resourceType?: string;
}

export interface ValidationResult {
    isValid: boolean;
    format: 'valid' | 'invalid';
    message?: string;
}

export interface DOIResolutionResult {
    success: boolean;
    metadata?: DataCiteMetadata;
    error?: string;
}

/**
 * Validate DOI format according to DOI syntax
 * DOIs start with "10." followed by a registrant code and a suffix
 * Also accepts DOI URLs (https://doi.org/... or http://dx.doi.org/...)
 *
 * Note on regex pattern: The pattern uses \S+ (non-whitespace) for the suffix,
 * which is intentionally permissive. According to the DOI specification (ISO 26324),
 * DOI suffixes can contain virtually any printable character including parentheses,
 * angle brackets, and other special characters. The current pattern validates
 * the basic structure while allowing legitimate complex suffixes.
 * See: https://www.doi.org/doi_handbook/2_Numbering.html
 */
export function validateDOIFormat(doi: string): ValidationResult {
    const trimmed = doi.trim();

    // Check if it's a DOI URL and extract the DOI part
    const doiUrlMatch = trimmed.match(/^https?:\/\/(?:doi\.org|dx\.doi\.org)\/(.+)/i);
    const doiToValidate = doiUrlMatch ? doiUrlMatch[1] : trimmed;

    // Basic DOI pattern: 10.xxxx/yyyy
    const doiPattern = /^10\.\d{4,}(?:\.\d+)*\/\S+$/;

    if (!doiPattern.test(doiToValidate)) {
        return {
            isValid: false,
            format: 'invalid',
            message: 'Invalid DOI format. DOI should start with "10." followed by a registrant code and suffix (e.g., 10.5194/nhess-15-1463-2015)',
        };
    }

    return {
        isValid: true,
        format: 'valid',
    };
}

/**
 * Validate URL format
 */
export function validateURLFormat(url: string): ValidationResult {
    try {
        new URL(url);
        return {
            isValid: true,
            format: 'valid',
        };
    } catch {
        return {
            isValid: false,
            format: 'invalid',
            message: 'Invalid URL format',
        };
    }
}

/**
 * Validate Handle format
 * Handles are typically in the format: prefix/suffix (e.g., 10273/ICDP5054EHW1001)
 * Also accepts Handle URLs (http://hdl.handle.net/prefix/suffix)
 *
 * Note: Handle suffixes with whitespace are not supported.
 * Query strings and fragments in Handle URLs are excluded from the identifier.
 */
export function validateHandleFormat(handle: string): ValidationResult {
    const trimmed = handle.trim();

    // Check if it's a Handle URL and extract the Handle part
    // Pattern excludes query strings (?...) and fragments (#...) from the Handle
    const handleUrlMatch = trimmed.match(/^https?:\/\/hdl\.handle\.net\/([^?#\s]+)/i);
    const handleToValidate = handleUrlMatch ? handleUrlMatch[1] : trimmed;

    // Handle pattern: prefix/suffix where prefix is numeric and suffix has non-whitespace
    // Note: Bare handles with whitespace in suffix are rejected for consistency
    const handlePattern = /^\d+\/\S+$/;

    if (!handlePattern.test(handleToValidate)) {
        return {
            isValid: false,
            format: 'invalid',
            message: 'Invalid Handle format. Should be in format: prefix/suffix (e.g., 11708/D386F88C) or URL (http://hdl.handle.net/prefix/suffix)',
        };
    }

    return {
        isValid: true,
        format: 'valid',
    };
}

/**
 * Validate identifier format based on type
 */
export function validateIdentifierFormat(identifier: string, type: string): ValidationResult {
    switch (type) {
        case 'DOI':
            return validateDOIFormat(identifier);
        case 'URL':
            return validateURLFormat(identifier);
        case 'Handle':
            return validateHandleFormat(identifier);
        default:
            // For other types, just check it's not empty
            return {
                isValid: identifier.trim().length > 0,
                format: identifier.trim().length > 0 ? 'valid' : 'invalid',
                message: identifier.trim().length > 0 ? undefined : 'Identifier cannot be empty',
            };
    }
}

/**
 * Resolve DOI metadata via backend proxy
 *
 * Backend handles both DataCite API and doi.org resolution
 * This avoids CORS issues with direct doi.org requests from browser
 *
 * Note: This is a non-blocking validation - failures should only show warnings
 */
export async function resolveDOIMetadata(doi: string, timeout = 5000): Promise<DOIResolutionResult> {
    const trimmed = doi.trim();

    // Extract bare DOI if URL format
    const doiUrlMatch = trimmed.match(/^https?:\/\/(?:doi\.org|dx\.doi\.org)\/(.+)/i);
    const bareDOI = doiUrlMatch ? doiUrlMatch[1] : trimmed;

    // First validate format locally
    const formatValidation = validateDOIFormat(bareDOI);
    if (!formatValidation.isValid) {
        return {
            success: false,
            error: formatValidation.message,
        };
    }

    try {
        // Use backend proxy to validate DOI
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), timeout);

        const response = await fetch('/api/validate-doi', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
            },
            body: JSON.stringify({ doi: bareDOI }),
            signal: controller.signal,
        });

        clearTimeout(timeoutId);

        if (!response.ok) {
            const errorData = await response.json().catch(() => ({}));
            return {
                success: false,
                error: errorData.error || `Validation error: ${response.status}`,
            };
        }

        const data = await response.json();

        if (data.success && data.metadata) {
            return {
                success: true,
                metadata: data.metadata,
            };
        }

        return {
            success: false,
            error: data.error || 'Could not verify DOI',
        };
    } catch (error) {
        if (error instanceof Error) {
            if (error.name === 'AbortError') {
                return {
                    success: false,
                    error: 'Request timeout - DOI validation took too long',
                };
            }
            return {
                success: false,
                error: error.message,
            };
        }
        return {
            success: false,
            error: 'Unknown error occurred',
        };
    }
}

/**
 * Check if an identifier type supports metadata resolution
 */
export function supportsMetadataResolution(identifierType: string): boolean {
    return identifierType === 'DOI';
}
