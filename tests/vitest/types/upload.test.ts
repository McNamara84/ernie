import { describe, expect, it } from 'vitest';

import {
    getUploadErrors,
    hasMultipleErrors,
    isCsvUploadSuccess,
    isUploadError,
    isXmlUploadSuccess,
    type CsvUploadSuccessResponse,
    type UploadError,
    type UploadErrorResponse,
    type XmlUploadSuccessResponse,
} from '@/types/upload';

describe('Upload Types', () => {
    describe('isUploadError', () => {
        it('returns true for error response', () => {
            const response: UploadErrorResponse = {
                success: false,
                message: 'Upload failed',
            };

            expect(isUploadError(response)).toBe(true);
        });

        it('returns false for success response', () => {
            const response: XmlUploadSuccessResponse = {
                success: true,
                sessionKey: 'abc123',
            };

            expect(isUploadError(response)).toBe(false);
        });
    });

    describe('isXmlUploadSuccess', () => {
        it('returns true for XML success response', () => {
            const response: XmlUploadSuccessResponse = {
                success: true,
                sessionKey: 'abc123',
            };

            expect(isXmlUploadSuccess(response)).toBe(true);
        });

        it('returns false for CSV success response', () => {
            const response: CsvUploadSuccessResponse = {
                success: true,
                created: 5,
            };

            expect(isXmlUploadSuccess(response)).toBe(false);
        });
    });

    describe('isCsvUploadSuccess', () => {
        it('returns true for CSV success response', () => {
            const response: CsvUploadSuccessResponse = {
                success: true,
                created: 5,
            };

            expect(isCsvUploadSuccess(response)).toBe(true);
        });

        it('returns false for XML success response', () => {
            const response: XmlUploadSuccessResponse = {
                success: true,
                sessionKey: 'abc123',
            };

            expect(isCsvUploadSuccess(response)).toBe(false);
        });
    });

    describe('getUploadErrors', () => {
        it('returns errors array when present', () => {
            const errors: UploadError[] = [
                { category: 'data', code: 'test', message: 'Error 1' },
                { category: 'data', code: 'test', message: 'Error 2' },
            ];

            const response: UploadErrorResponse = {
                success: false,
                message: 'Multiple errors',
                errors,
            };

            expect(getUploadErrors(response)).toEqual(errors);
        });

        it('returns single error as array when no errors array', () => {
            const error: UploadError = {
                category: 'validation',
                code: 'file_too_large',
                message: 'File too large',
            };

            const response: UploadErrorResponse = {
                success: false,
                message: 'Upload failed',
                error,
            };

            expect(getUploadErrors(response)).toEqual([error]);
        });

        it('returns empty array when no errors', () => {
            const response: UploadErrorResponse = {
                success: false,
                message: 'Upload failed',
            };

            expect(getUploadErrors(response)).toEqual([]);
        });

        it('prefers errors array over single error', () => {
            const errors: UploadError[] = [
                { category: 'data', code: 'test1', message: 'Error 1' },
                { category: 'data', code: 'test2', message: 'Error 2' },
            ];

            const singleError: UploadError = {
                category: 'validation',
                code: 'single',
                message: 'Single error',
            };

            const response: UploadErrorResponse = {
                success: false,
                message: 'Multiple errors',
                error: singleError,
                errors,
            };

            expect(getUploadErrors(response)).toEqual(errors);
        });
    });

    describe('hasMultipleErrors', () => {
        it('returns true when errors exceed threshold', () => {
            const errors: UploadError[] = Array.from({ length: 5 }, (_, i) => ({
                category: 'data' as const,
                code: `error_${i}`,
                message: `Error ${i}`,
            }));

            const response: UploadErrorResponse = {
                success: false,
                message: 'Multiple errors',
                errors,
            };

            expect(hasMultipleErrors(response, 3)).toBe(true);
        });

        it('returns false when errors at or below threshold', () => {
            const errors: UploadError[] = [
                { category: 'data', code: 'test1', message: 'Error 1' },
                { category: 'data', code: 'test2', message: 'Error 2' },
                { category: 'data', code: 'test3', message: 'Error 3' },
            ];

            const response: UploadErrorResponse = {
                success: false,
                message: 'Multiple errors',
                errors,
            };

            expect(hasMultipleErrors(response, 3)).toBe(false);
        });

        it('uses default threshold of 3', () => {
            const errors: UploadError[] = Array.from({ length: 4 }, (_, i) => ({
                category: 'data' as const,
                code: `error_${i}`,
                message: `Error ${i}`,
            }));

            const response: UploadErrorResponse = {
                success: false,
                message: 'Multiple errors',
                errors,
            };

            expect(hasMultipleErrors(response)).toBe(true);
        });
    });
});
