import { describe, expect, it } from 'vitest';

import { extractErrorMessageFromBlob, parseJsonFromBlob, parseValidationErrorFromBlob } from '@/lib/blob-utils';

describe('parseJsonFromBlob', () => {
    it('parses valid JSON from blob', async () => {
        const blob = new Blob([JSON.stringify({ key: 'value' })], { type: 'application/json' });
        const result = await parseJsonFromBlob(blob);
        expect(result).toEqual({ key: 'value' });
    });

    it('returns null for invalid JSON', async () => {
        const blob = new Blob(['not json'], { type: 'text/plain' });
        const result = await parseJsonFromBlob(blob);
        expect(result).toBeNull();
    });

    it('returns null for empty blob', async () => {
        const blob = new Blob([''], { type: 'application/json' });
        const result = await parseJsonFromBlob(blob);
        expect(result).toBeNull();
    });
});

describe('extractErrorMessageFromBlob', () => {
    it('extracts message from blob', async () => {
        const blob = new Blob([JSON.stringify({ message: 'Error occurred' })], { type: 'application/json' });
        const result = await extractErrorMessageFromBlob(blob, 'default');
        expect(result).toBe('Error occurred');
    });

    it('returns default for non-blob', async () => {
        const result = await extractErrorMessageFromBlob('not a blob', 'default message');
        expect(result).toBe('default message');
    });

    it('returns default when blob has no message', async () => {
        const blob = new Blob([JSON.stringify({ other: 'data' })], { type: 'application/json' });
        const result = await extractErrorMessageFromBlob(blob, 'fallback');
        expect(result).toBe('fallback');
    });

    it('returns default for invalid JSON blob', async () => {
        const blob = new Blob(['not json'], { type: 'text/plain' });
        const result = await extractErrorMessageFromBlob(blob, 'fallback');
        expect(result).toBe('fallback');
    });
});

describe('parseValidationErrorFromBlob', () => {
    it('parses validation errors', async () => {
        const data = {
            errors: [{ path: '/title', message: 'Required' }],
            schema_version: '4.7',
        };
        const blob = new Blob([JSON.stringify(data)], { type: 'application/json' });
        const result = await parseValidationErrorFromBlob(blob);
        expect(result).not.toBeNull();
        expect(result!.errors).toHaveLength(1);
        expect(result!.errors[0].path).toBe('/title');
    });

    it('returns null for non-blob', async () => {
        const result = await parseValidationErrorFromBlob('not a blob');
        expect(result).toBeNull();
    });

    it('returns null when no errors array', async () => {
        const blob = new Blob([JSON.stringify({ message: 'Error' })], { type: 'application/json' });
        const result = await parseValidationErrorFromBlob(blob);
        expect(result).toBeNull();
    });
});
