/**
 * Types for file upload error handling.
 *
 * These types mirror the backend UploadError structure for consistent
 * error handling across the full stack.
 */

/**
 * Error categories for upload failures.
 * - validation: User/client errors (wrong file type, too large, etc.)
 * - data: Data-related errors (parsing, duplicates, missing fields)
 * - server: Server-side errors (database, storage, unexpected)
 */
export type UploadErrorCategory = 'validation' | 'data' | 'server';

/**
 * Structured upload error from the backend.
 */
export interface UploadError {
    /** Error category for grouping and display */
    category: UploadErrorCategory;
    /** Machine-readable error code */
    code: string;
    /** Human-readable error message */
    message: string;
    /** Field that caused the error (if applicable) */
    field?: string | null;
    /** Row number in CSV (if applicable) */
    row?: number | null;
    /** DOI or IGSN that caused the error (if applicable) */
    identifier?: string | null;
}

/**
 * Error response from upload endpoints.
 */
export interface UploadErrorResponse {
    success: false;
    /** Summary error message */
    message: string;
    /** Filename that was uploaded */
    filename?: string;
    /** Single error (for simple failures) */
    error?: UploadError;
    /** Multiple errors (for CSV row errors) */
    errors?: UploadError[];
}

/**
 * Success response from XML upload endpoint.
 * Note: Backend does not include 'success' field, only 'sessionKey'.
 */
export interface XmlUploadSuccessResponse {
    success?: true;
    /** Session key for retrieving parsed XML data */
    sessionKey: string;
}

/**
 * Success response from CSV upload endpoint.
 */
export interface CsvUploadSuccessResponse {
    success: true;
    /** Number of IGSNs created */
    created: number;
    /** Filename that was uploaded */
    filename?: string;
    /** Optional success message */
    message?: string;
    /** Partial errors (some rows failed but others succeeded) */
    errors?: UploadError[];
}

/**
 * Success response from JSON upload endpoint.
 * Same structure as XML upload: contains session key for editor navigation.
 */
export interface JsonUploadSuccessResponse {
    /** Session key for retrieving parsed JSON data */
    sessionKey: string;
}

/**
 * Union type for all upload responses.
 */
export type UploadResponse = UploadErrorResponse | XmlUploadSuccessResponse | JsonUploadSuccessResponse | CsvUploadSuccessResponse;

/**
 * Type guard to check if response is an error.
 */
export function isUploadError(response: UploadResponse): response is UploadErrorResponse {
    return 'success' in response && response.success === false;
}

/**
 * Type guard to check if a session-based upload (XML or JSON) succeeded.
 * Both XML and JSON uploads return a sessionKey for editor navigation.
 */
export function isSessionUploadSuccess(
    response: UploadResponse,
): response is XmlUploadSuccessResponse | JsonUploadSuccessResponse {
    return 'sessionKey' in response;
}

/**
 * @deprecated Use isSessionUploadSuccess instead. Kept for backward compatibility.
 */
export const isXmlUploadSuccess = isSessionUploadSuccess;

/**
 * @deprecated Use isSessionUploadSuccess instead. Kept for backward compatibility.
 */
export const isJsonUploadSuccess = isSessionUploadSuccess;

/**
 * Type guard to check if response is CSV success.
 */
export function isCsvUploadSuccess(response: UploadResponse): response is CsvUploadSuccessResponse {
    return 'success' in response && response.success === true && 'created' in response;
}

/**
 * Get all errors from a response (single error or array).
 */
export function getUploadErrors(response: UploadErrorResponse): UploadError[] {
    if (response.errors && response.errors.length > 0) {
        return response.errors;
    }
    if (response.error) {
        return [response.error];
    }
    return [];
}

/**
 * Check if the upload had multiple errors (should show modal).
 */
export function hasMultipleErrors(response: UploadErrorResponse, threshold: number = 3): boolean {
    return getUploadErrors(response).length > threshold;
}
