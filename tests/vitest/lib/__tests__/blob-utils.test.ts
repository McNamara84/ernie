import { describe, expect, it, vi } from 'vitest';

import {
    extractErrorMessageFromBlob,
    parseJsonFromBlob,
    parseValidationErrorFromBlob,
    type ValidationErrorResponse,
} from '@/lib/blob-utils';

// Create a mock blob that works in JSDOM
function createMockBlob(content: string): Blob {
    const blob = new Blob([content], { type: 'application/json' });
    // JSDOM's Blob doesn't implement text() properly, so we mock it
    blob.text = vi.fn().mockResolvedValue(content);
    return blob;
}

describe('blob-utils', () => {
    describe('parseJsonFromBlob', () => {
        it('parses valid JSON from a blob', async () => {
            const data = { message: 'test', count: 42 };
            const blob = createMockBlob(JSON.stringify(data));

            const result = await parseJsonFromBlob(blob);

            expect(result).toEqual(data);
        });

        it('returns null for invalid JSON', async () => {
            const blob = createMockBlob('not valid json');

            const result = await parseJsonFromBlob(blob);

            expect(result).toBeNull();
        });

        it('returns null for empty blob', async () => {
            const blob = createMockBlob('');

            const result = await parseJsonFromBlob(blob);

            expect(result).toBeNull();
        });
    });

    describe('extractErrorMessageFromBlob', () => {
        it('extracts error message from blob', async () => {
            const blob = createMockBlob(JSON.stringify({ message: 'Something went wrong' }));

            const result = await extractErrorMessageFromBlob(blob, 'Default error');

            expect(result).toBe('Something went wrong');
        });

        it('returns default message when blob has no message property', async () => {
            const blob = createMockBlob(JSON.stringify({ error: 'Some error' }));

            const result = await extractErrorMessageFromBlob(blob, 'Default error');

            expect(result).toBe('Default error');
        });

        it('returns default message when not a blob', async () => {
            const result = await extractErrorMessageFromBlob('not a blob', 'Default error');

            expect(result).toBe('Default error');
        });

        it('returns default message when JSON parsing fails', async () => {
            const blob = createMockBlob('invalid json');

            const result = await extractErrorMessageFromBlob(blob, 'Default error');

            expect(result).toBe('Default error');
        });
    });

    describe('parseValidationErrorFromBlob', () => {
        it('parses validation error response from blob', async () => {
            const validationError: ValidationErrorResponse = {
                errors: [
                    { path: '/titles/0/title', message: 'Required field missing' },
                    { path: '/creators', message: 'Must have at least one creator' },
                ],
                schema_version: '4.6',
                message: 'Validation failed',
            };
            const blob = createMockBlob(JSON.stringify(validationError));

            const result = await parseValidationErrorFromBlob(blob);

            expect(result).toEqual(validationError);
        });

        it('returns null when errors array is missing', async () => {
            const blob = createMockBlob(JSON.stringify({ message: 'Some error' }));

            const result = await parseValidationErrorFromBlob(blob);

            expect(result).toBeNull();
        });

        it('returns null when errors is not an array', async () => {
            const blob = createMockBlob(JSON.stringify({ errors: 'not an array' }));

            const result = await parseValidationErrorFromBlob(blob);

            expect(result).toBeNull();
        });

        it('returns null when not a blob', async () => {
            const result = await parseValidationErrorFromBlob({ errors: [] });

            expect(result).toBeNull();
        });

        it('returns null when JSON parsing fails', async () => {
            const blob = createMockBlob('invalid json');

            const result = await parseValidationErrorFromBlob(blob);

            expect(result).toBeNull();
        });
    });
});
