import { renderHook, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { useIdentifierValidation } from '@/hooks/use-identifier-validation';

const mocks = vi.hoisted(() => ({
    validateIdentifierFormat: vi.fn(),
    supportsMetadataResolution: vi.fn(),
    resolveDOIMetadata: vi.fn(),
}));

vi.mock('@/lib/doi-validation', () => ({
    validateIdentifierFormat: mocks.validateIdentifierFormat,
    supportsMetadataResolution: mocks.supportsMetadataResolution,
    resolveDOIMetadata: mocks.resolveDOIMetadata,
}));

// Helper for tests without fake timers
const wait = (ms: number) => new Promise((resolve) => setTimeout(resolve, ms));

describe('useIdentifierValidation', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    describe('initial state', () => {
        it('returns idle status when identifier is empty', () => {
            mocks.validateIdentifierFormat.mockReturnValue({ isValid: true });
            mocks.supportsMetadataResolution.mockReturnValue(false);

            const { result } = renderHook(() =>
                useIdentifierValidation({
                    identifier: '',
                    identifierType: 'DOI',
                }),
            );

            expect(result.current.status).toBe('idle');
        });

        it('returns idle status when disabled', () => {
            mocks.validateIdentifierFormat.mockReturnValue({ isValid: true });
            mocks.supportsMetadataResolution.mockReturnValue(false);

            const { result } = renderHook(() =>
                useIdentifierValidation({
                    identifier: '10.1234/test',
                    identifierType: 'DOI',
                    enabled: false,
                }),
            );

            expect(result.current.status).toBe('idle');
        });
    });

    describe('format validation', () => {
        it('shows invalid status when format is invalid', () => {
            mocks.validateIdentifierFormat.mockReturnValue({
                isValid: false,
                message: 'Invalid DOI format',
            });
            mocks.supportsMetadataResolution.mockReturnValue(true);

            const { result } = renderHook(() =>
                useIdentifierValidation({
                    identifier: 'invalid-doi',
                    identifierType: 'DOI',
                }),
            );

            expect(result.current.status).toBe('invalid');
            expect(result.current.message).toBe('Invalid DOI format');
        });

        it('shows valid status for valid non-DOI types without API check', () => {
            mocks.validateIdentifierFormat.mockReturnValue({ isValid: true });
            mocks.supportsMetadataResolution.mockReturnValue(false);

            const { result } = renderHook(() =>
                useIdentifierValidation({
                    identifier: 'https://example.com',
                    identifierType: 'URL',
                }),
            );

            expect(result.current.status).toBe('valid');
            expect(result.current.message).toBe('Format validated');
        });

        it('shows validating status for valid DOI while API check is pending', () => {
            mocks.validateIdentifierFormat.mockReturnValue({ isValid: true });
            mocks.supportsMetadataResolution.mockReturnValue(true);

            const { result } = renderHook(() =>
                useIdentifierValidation({
                    identifier: '10.1234/test',
                    identifierType: 'DOI',
                }),
            );

            expect(result.current.status).toBe('validating');
        });
    });

    describe('API validation for DOIs', () => {
        it('shows valid status with metadata after successful API resolution', async () => {
            mocks.validateIdentifierFormat.mockReturnValue({ isValid: true });
            mocks.supportsMetadataResolution.mockReturnValue(true);
            mocks.resolveDOIMetadata.mockResolvedValue({
                success: true,
                metadata: {
                    title: 'Test Publication',
                    authors: ['Author 1'],
                },
            });

            const { result } = renderHook(() =>
                useIdentifierValidation({
                    identifier: '10.1234/test',
                    identifierType: 'DOI',
                    debounceMs: 10, // Short debounce for testing
                }),
            );

            // Initially validating
            expect(result.current.status).toBe('validating');

            // Wait for debounce and API call
            await waitFor(
                () => {
                    expect(result.current.status).toBe('valid');
                },
                { timeout: 500 },
            );

            expect(result.current.message).toContain('Test Publication');
            expect(result.current.metadata?.title).toBe('Test Publication');
        });

        it('shows warning status when API resolution fails', async () => {
            mocks.validateIdentifierFormat.mockReturnValue({ isValid: true });
            mocks.supportsMetadataResolution.mockReturnValue(true);
            mocks.resolveDOIMetadata.mockResolvedValue({
                success: false,
                error: 'DOI not found',
            });

            const { result } = renderHook(() =>
                useIdentifierValidation({
                    identifier: '10.1234/notfound',
                    identifierType: 'DOI',
                    debounceMs: 10,
                }),
            );

            await waitFor(
                () => {
                    expect(result.current.status).toBe('warning');
                },
                { timeout: 500 },
            );

            expect(result.current.message).toBe('DOI not found');
        });

        it('shows warning status on network error', async () => {
            mocks.validateIdentifierFormat.mockReturnValue({ isValid: true });
            mocks.supportsMetadataResolution.mockReturnValue(true);
            mocks.resolveDOIMetadata.mockRejectedValue(new Error('Network error'));

            const { result } = renderHook(() =>
                useIdentifierValidation({
                    identifier: '10.1234/test',
                    identifierType: 'DOI',
                    debounceMs: 10,
                }),
            );

            await waitFor(
                () => {
                    expect(result.current.status).toBe('warning');
                },
                { timeout: 500 },
            );

            expect(result.current.message).toContain('network error');
        });

        it('shows valid status with fallback message when title is empty', async () => {
            mocks.validateIdentifierFormat.mockReturnValue({ isValid: true });
            mocks.supportsMetadataResolution.mockReturnValue(true);
            mocks.resolveDOIMetadata.mockResolvedValue({
                success: true,
                metadata: {
                    title: '',
                    authors: [],
                },
            });

            const { result } = renderHook(() =>
                useIdentifierValidation({
                    identifier: '10.1234/test',
                    identifierType: 'DOI',
                    debounceMs: 10,
                }),
            );

            await waitFor(
                () => {
                    expect(result.current.status).toBe('valid');
                },
                { timeout: 500 },
            );

            expect(result.current.message).toBe('DOI verified');
        });
    });

    describe('debouncing', () => {
        it('waits for debounce period before making API call', async () => {
            mocks.validateIdentifierFormat.mockReturnValue({ isValid: true });
            mocks.supportsMetadataResolution.mockReturnValue(true);
            mocks.resolveDOIMetadata.mockResolvedValue({
                success: true,
                metadata: { title: 'Test' },
            });

            renderHook(() =>
                useIdentifierValidation({
                    identifier: '10.1234/test',
                    identifierType: 'DOI',
                    debounceMs: 100,
                }),
            );

            // Before debounce
            expect(mocks.resolveDOIMetadata).not.toHaveBeenCalled();

            // Wait for debounce
            await waitFor(
                () => {
                    expect(mocks.resolveDOIMetadata).toHaveBeenCalledTimes(1);
                },
                { timeout: 500 },
            );
        });

        it('makes API call with correct identifier', async () => {
            mocks.validateIdentifierFormat.mockReturnValue({ isValid: true });
            mocks.supportsMetadataResolution.mockReturnValue(true);
            mocks.resolveDOIMetadata.mockResolvedValue({
                success: true,
                metadata: { title: 'Test' },
            });

            renderHook(() =>
                useIdentifierValidation({
                    identifier: '10.1234/specific-doi',
                    identifierType: 'DOI',
                    debounceMs: 10,
                }),
            );

            await waitFor(
                () => {
                    expect(mocks.resolveDOIMetadata).toHaveBeenCalledWith('10.1234/specific-doi');
                },
                { timeout: 500 },
            );
        });
    });

    describe('identifier type changes', () => {
        it('revalidates immediately when identifier type changes', () => {
            mocks.validateIdentifierFormat.mockReturnValue({ isValid: true });
            mocks.supportsMetadataResolution.mockReturnValue(false);

            const { result, rerender } = renderHook(
                ({ identifier, identifierType }) =>
                    useIdentifierValidation({
                        identifier,
                        identifierType,
                    }),
                {
                    initialProps: {
                        identifier: 'https://example.com',
                        identifierType: 'URL',
                    },
                },
            );

            expect(result.current.status).toBe('valid');

            // Change to DOI type
            mocks.supportsMetadataResolution.mockReturnValue(true);
            rerender({
                identifier: 'https://example.com',
                identifierType: 'DOI',
            });

            // Should show validating for DOI
            expect(result.current.status).toBe('validating');
        });

        it('does not call API for non-DOI types', async () => {
            mocks.validateIdentifierFormat.mockReturnValue({ isValid: true });
            mocks.supportsMetadataResolution.mockReturnValue(false);

            renderHook(() =>
                useIdentifierValidation({
                    identifier: 'https://example.com',
                    identifierType: 'URL',
                }),
            );

            // Wait a bit to ensure no API call is made
            await wait(50);

            expect(mocks.resolveDOIMetadata).not.toHaveBeenCalled();
        });
    });
});
