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
 */
export function validateHandleFormat(handle: string): ValidationResult {
    const trimmed = handle.trim();
    const handlePattern = /^\d+\/.+$/;
    
    if (!handlePattern.test(trimmed)) {
        return {
            isValid: false,
            format: 'invalid',
            message: 'Invalid Handle format. Should be in format: prefix/suffix',
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
 * Resolve DOI metadata from DataCite API
 * Returns metadata if successful, or error information
 * 
 * Note: This is a non-blocking validation - failures should only show warnings
 */
export async function resolveDOIMetadata(doi: string, timeout = 5000): Promise<DOIResolutionResult> {
    const trimmed = doi.trim();
    
    // First validate format
    const formatValidation = validateDOIFormat(trimmed);
    if (!formatValidation.isValid) {
        return {
            success: false,
            error: formatValidation.message,
        };
    }
    
    try {
        // Create abort controller for timeout
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), timeout);
        
        // Query DataCite API
        const response = await fetch(`https://api.datacite.org/dois/${encodeURIComponent(trimmed)}`, {
            signal: controller.signal,
            headers: {
                'Accept': 'application/vnd.api+json',
            },
        });
        
        clearTimeout(timeoutId);
        
        if (!response.ok) {
            if (response.status === 404) {
                return {
                    success: false,
                    error: 'DOI not found in DataCite registry',
                };
            }
            return {
                success: false,
                error: `DataCite API error: ${response.status}`,
            };
        }
        
        const data = await response.json();
        const attributes = data.data?.attributes;
        
        if (!attributes) {
            return {
                success: false,
                error: 'No metadata found',
            };
        }
        
        // Extract metadata
        const metadata: DataCiteMetadata = {
            title: attributes.titles?.[0]?.title,
            creators: attributes.creators?.map((c: { name?: string; givenName?: string; familyName?: string }) => {
                if (c.name) return c.name;
                if (c.familyName && c.givenName) return `${c.givenName} ${c.familyName}`;
                return c.familyName || c.givenName || 'Unknown';
            }),
            publicationYear: attributes.publicationYear,
            publisher: attributes.publisher,
            resourceType: attributes.types?.resourceType,
        };
        
        return {
            success: true,
            metadata,
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
