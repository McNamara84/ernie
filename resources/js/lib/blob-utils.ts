/**
 * Utility functions for working with Blob responses.
 */

/**
 * Parses JSON data from a Blob response.
 *
 * @param blob - The Blob to parse
 * @returns The parsed JSON data, or null if parsing fails
 */
export async function parseJsonFromBlob<T = unknown>(blob: Blob): Promise<T | null> {
    try {
        const text = await blob.text();
        return JSON.parse(text) as T;
    } catch {
        return null;
    }
}

/**
 * Extracts an error message from an Axios error response blob.
 *
 * @param errorData - The Axios error response data (Blob)
 * @param defaultMessage - Fallback message if extraction fails
 * @returns The extracted error message or the default
 */
export async function extractErrorMessageFromBlob(
    errorData: unknown,
    defaultMessage: string,
): Promise<string> {
    if (!(errorData instanceof Blob)) {
        return defaultMessage;
    }

    const parsed = await parseJsonFromBlob<{ message?: string }>(errorData);
    return parsed?.message ?? defaultMessage;
}

/**
 * Response structure for validation errors from DataCite JSON export.
 */
export interface ValidationErrorResponse {
    errors: Array<{
        path: string;
        message: string;
        keyword?: string;
        context?: Record<string, unknown>;
    }>;
    schema_version?: string;
    message?: string;
}

/**
 * Parses validation error details from an Axios 422 response blob.
 *
 * @param errorData - The Axios error response data (Blob)
 * @returns The parsed validation error response, or null if parsing fails
 */
export async function parseValidationErrorFromBlob(
    errorData: unknown,
): Promise<ValidationErrorResponse | null> {
    if (!(errorData instanceof Blob)) {
        return null;
    }

    const parsed = await parseJsonFromBlob<ValidationErrorResponse>(errorData);

    // Validate that the response has the expected structure
    if (parsed?.errors && Array.isArray(parsed.errors)) {
        return parsed;
    }

    return null;
}
