import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import {
    resolveDOIMetadata,
    supportsMetadataResolution,
    validateDOIFormat,
    validateHandleFormat,
    validateIdentifierFormat,
    validateURLFormat,
} from '@/lib/doi-validation';

describe('doi-validation', () => {
    describe('validateHandleFormat', () => {
        it('should validate bare Handle format (prefix/suffix)', () => {
            const result = validateHandleFormat('11708/D386F88C-DC84-4544-9396-48ACE2F402DB');
            expect(result.isValid).toBe(true);
            expect(result.format).toBe('valid');
        });

        it('should validate Handle with numeric prefix', () => {
            const result = validateHandleFormat('10273/ICDP5054EHW1001');
            expect(result.isValid).toBe(true);
            expect(result.format).toBe('valid');
        });

        it('should validate bare Handle with short numeric prefix', () => {
            const result = validateHandleFormat('10419/163427');
            expect(result.isValid).toBe(true);
            expect(result.format).toBe('valid');
        });

        it('should validate Handle URL with http', () => {
            const result = validateHandleFormat('http://hdl.handle.net/11708/D386F88C-DC84-4544-9396-48ACE2F402DB');
            expect(result.isValid).toBe(true);
            expect(result.format).toBe('valid');
        });

        it('should validate Handle URL with https', () => {
            const result = validateHandleFormat('https://hdl.handle.net/11708/D386F88C-DC84-4544-9396-48ACE2F402DB');
            expect(result.isValid).toBe(true);
            expect(result.format).toBe('valid');
        });

        it('should validate Handle URL with short numeric prefix', () => {
            const result = validateHandleFormat('https://hdl.handle.net/10419/163427');
            expect(result.isValid).toBe(true);
            expect(result.format).toBe('valid');
        });

        it('should validate Handle URL with query string (query string excluded)', () => {
            const result = validateHandleFormat('http://hdl.handle.net/11708/test?query=value');
            expect(result.isValid).toBe(true);
            expect(result.format).toBe('valid');
        });

        it('should validate Handle URL with fragment (fragment excluded)', () => {
            const result = validateHandleFormat('http://hdl.handle.net/11708/test#section');
            expect(result.isValid).toBe(true);
            expect(result.format).toBe('valid');
        });

        it('should reject Handle with whitespace-only suffix', () => {
            const result = validateHandleFormat('11708/   ');
            expect(result.isValid).toBe(false);
            expect(result.format).toBe('invalid');
        });

        it('should reject Handle with slash and single space', () => {
            const result = validateHandleFormat('11708/ ');
            expect(result.isValid).toBe(false);
            expect(result.format).toBe('invalid');
        });

        it('should reject Handle URL with whitespace-only suffix', () => {
            const result = validateHandleFormat('http://hdl.handle.net/11708/   ');
            expect(result.isValid).toBe(false);
            expect(result.format).toBe('invalid');
        });

        it('should reject Handle without numeric prefix', () => {
            const result = validateHandleFormat('abc/suffix');
            expect(result.isValid).toBe(false);
            expect(result.format).toBe('invalid');
        });

        it('should reject Handle without slash', () => {
            const result = validateHandleFormat('11708');
            expect(result.isValid).toBe(false);
            expect(result.format).toBe('invalid');
        });

        it('should reject empty Handle', () => {
            const result = validateHandleFormat('');
            expect(result.isValid).toBe(false);
            expect(result.format).toBe('invalid');
        });
    });

    describe('validateDOIFormat', () => {
        it('should validate bare DOI format', () => {
            const result = validateDOIFormat('10.5194/nhess-15-1463-2015');
            expect(result.isValid).toBe(true);
            expect(result.format).toBe('valid');
        });

        it('should validate DOI URL with doi.org', () => {
            const result = validateDOIFormat('https://doi.org/10.5194/nhess-15-1463-2015');
            expect(result.isValid).toBe(true);
            expect(result.format).toBe('valid');
        });

        it('should validate DOI URL with dx.doi.org', () => {
            const result = validateDOIFormat('http://dx.doi.org/10.5194/nhess-15-1463-2015');
            expect(result.isValid).toBe(true);
            expect(result.format).toBe('valid');
        });

        it('should reject invalid DOI', () => {
            const result = validateDOIFormat('not-a-doi');
            expect(result.isValid).toBe(false);
            expect(result.format).toBe('invalid');
        });
    });

    describe('validateURLFormat', () => {
        it('should validate http URL', () => {
            const result = validateURLFormat('http://example.com');
            expect(result.isValid).toBe(true);
            expect(result.format).toBe('valid');
        });

        it('should validate https URL', () => {
            const result = validateURLFormat('https://example.com/path?query=1');
            expect(result.isValid).toBe(true);
            expect(result.format).toBe('valid');
        });

        it('should reject invalid URL', () => {
            const result = validateURLFormat('not-a-url');
            expect(result.isValid).toBe(false);
            expect(result.format).toBe('invalid');
        });
    });

    describe('validateIdentifierFormat', () => {
        it('should route DOI to DOI validator', () => {
            const result = validateIdentifierFormat('10.5194/test', 'DOI');
            expect(result.isValid).toBe(true);
        });

        it('should route URL to URL validator', () => {
            const result = validateIdentifierFormat('https://example.com', 'URL');
            expect(result.isValid).toBe(true);
        });

        it('should route Handle to Handle validator', () => {
            const result = validateIdentifierFormat('11708/test', 'Handle');
            expect(result.isValid).toBe(true);
        });

        it('should route Handle URL to Handle validator', () => {
            const result = validateIdentifierFormat('http://hdl.handle.net/11708/test', 'Handle');
            expect(result.isValid).toBe(true);
        });

        it('should validate non-empty unknown type identifiers', () => {
            const result = validateIdentifierFormat('some-identifier', 'ISBN');
            expect(result.isValid).toBe(true);
            expect(result.format).toBe('valid');
        });

        it('should reject empty unknown type identifiers', () => {
            const result = validateIdentifierFormat('', 'ISBN');
            expect(result.isValid).toBe(false);
            expect(result.format).toBe('invalid');
            expect(result.message).toBe('Identifier cannot be empty');
        });

        it('should reject whitespace-only unknown type identifiers', () => {
            const result = validateIdentifierFormat('   ', 'ARK');
            expect(result.isValid).toBe(false);
            expect(result.format).toBe('invalid');
        });
    });

    describe('supportsMetadataResolution', () => {
        it('should return true for DOI type', () => {
            expect(supportsMetadataResolution('DOI')).toBe(true);
        });

        it('should return false for URL type', () => {
            expect(supportsMetadataResolution('URL')).toBe(false);
        });

        it('should return false for Handle type', () => {
            expect(supportsMetadataResolution('Handle')).toBe(false);
        });

        it('should return false for unknown types', () => {
            expect(supportsMetadataResolution('ISBN')).toBe(false);
            expect(supportsMetadataResolution('ARK')).toBe(false);
        });
    });

    describe('resolveDOIMetadata', () => {
        let originalFetch: typeof fetch;

        beforeEach(() => {
            originalFetch = global.fetch;
            // Mock document.querySelector for CSRF token
            Object.defineProperty(document, 'querySelector', {
                value: vi.fn((selector: string) => {
                    if (selector === 'meta[name="csrf-token"]') {
                        return { getAttribute: () => 'test-csrf-token' };
                    }
                    return null;
                }),
                writable: true,
            });
        });

        afterEach(() => {
            global.fetch = originalFetch;
            vi.restoreAllMocks();
        });

        it('should return error for invalid DOI format', async () => {
            const result = await resolveDOIMetadata('not-a-valid-doi');
            expect(result.success).toBe(false);
            expect(result.error).toContain('Invalid DOI format');
        });

        it('should return error for DOI URL with invalid format after extraction', async () => {
            const result = await resolveDOIMetadata('https://doi.org/invalid');
            expect(result.success).toBe(false);
            expect(result.error).toContain('Invalid DOI format');
        });

        it('should extract DOI from URL and validate', async () => {
            global.fetch = vi.fn().mockResolvedValue({
                ok: true,
                json: () =>
                    Promise.resolve({
                        success: true,
                        metadata: { title: 'Test Paper', creators: ['Author One'] },
                    }),
            });

            const result = await resolveDOIMetadata('https://doi.org/10.5194/nhess-15-1463-2015');
            expect(result.success).toBe(true);
            expect(result.metadata).toEqual({ title: 'Test Paper', creators: ['Author One'] });
        });

        it('should handle successful API response with metadata', async () => {
            global.fetch = vi.fn().mockResolvedValue({
                ok: true,
                json: () =>
                    Promise.resolve({
                        success: true,
                        metadata: {
                            title: 'Research Dataset',
                            creators: ['John Doe', 'Jane Smith'],
                            publicationYear: 2024,
                            publisher: 'GFZ',
                            resourceType: 'Dataset',
                        },
                    }),
            });

            const result = await resolveDOIMetadata('10.5194/nhess-15-1463-2015');
            expect(result.success).toBe(true);
            expect(result.metadata?.title).toBe('Research Dataset');
            expect(result.metadata?.creators).toHaveLength(2);
            expect(result.metadata?.publicationYear).toBe(2024);
        });

        it('should handle API response with success but no metadata', async () => {
            global.fetch = vi.fn().mockResolvedValue({
                ok: true,
                json: () =>
                    Promise.resolve({
                        success: false,
                        error: 'DOI not found in DataCite registry',
                    }),
            });

            const result = await resolveDOIMetadata('10.5194/nhess-15-1463-2015');
            expect(result.success).toBe(false);
            expect(result.error).toBe('DOI not found in DataCite registry');
        });

        it('should handle API response with success true but metadata missing', async () => {
            global.fetch = vi.fn().mockResolvedValue({
                ok: true,
                json: () =>
                    Promise.resolve({
                        success: true,
                        // metadata is missing
                    }),
            });

            const result = await resolveDOIMetadata('10.5194/nhess-15-1463-2015');
            expect(result.success).toBe(false);
            expect(result.error).toBe('Could not verify DOI');
        });

        it('should handle non-ok HTTP response with error data', async () => {
            global.fetch = vi.fn().mockResolvedValue({
                ok: false,
                status: 404,
                json: () =>
                    Promise.resolve({
                        error: 'DOI not found',
                    }),
            });

            const result = await resolveDOIMetadata('10.5194/nhess-15-1463-2015');
            expect(result.success).toBe(false);
            expect(result.error).toBe('DOI not found');
        });

        it('should handle non-ok HTTP response when json parsing fails', async () => {
            global.fetch = vi.fn().mockResolvedValue({
                ok: false,
                status: 500,
                json: () => Promise.reject(new Error('Invalid JSON')),
            });

            const result = await resolveDOIMetadata('10.5194/nhess-15-1463-2015');
            expect(result.success).toBe(false);
            expect(result.error).toBe('Validation error: 500');
        });

        it('should handle network error', async () => {
            global.fetch = vi.fn().mockRejectedValue(new Error('Network failure'));

            const result = await resolveDOIMetadata('10.5194/nhess-15-1463-2015');
            expect(result.success).toBe(false);
            expect(result.error).toBe('Network failure');
        });

        it('should handle timeout (AbortError)', async () => {
            const abortError = new Error('Aborted');
            abortError.name = 'AbortError';
            global.fetch = vi.fn().mockRejectedValue(abortError);

            const result = await resolveDOIMetadata('10.5194/nhess-15-1463-2015');
            expect(result.success).toBe(false);
            expect(result.error).toBe('Request timeout - DOI validation took too long');
        });

        it('should handle unknown non-Error exceptions', async () => {
            global.fetch = vi.fn().mockRejectedValue('string error');

            const result = await resolveDOIMetadata('10.5194/nhess-15-1463-2015');
            expect(result.success).toBe(false);
            expect(result.error).toBe('Unknown error occurred');
        });

        it('should trim whitespace from DOI input', async () => {
            global.fetch = vi.fn().mockResolvedValue({
                ok: true,
                json: () =>
                    Promise.resolve({
                        success: true,
                        metadata: { title: 'Test' },
                    }),
            });

            await resolveDOIMetadata('  10.5194/nhess-15-1463-2015  ');
            expect(global.fetch).toHaveBeenCalled();
            const fetchCall = (global.fetch as ReturnType<typeof vi.fn>).mock.calls[0];
            const body = JSON.parse(fetchCall[1].body);
            expect(body.doi).toBe('10.5194/nhess-15-1463-2015');
        });

        it('should send correct headers and CSRF token', async () => {
            global.fetch = vi.fn().mockResolvedValue({
                ok: true,
                json: () =>
                    Promise.resolve({
                        success: true,
                        metadata: { title: 'Test' },
                    }),
            });

            await resolveDOIMetadata('10.5194/test');
            const fetchCall = (global.fetch as ReturnType<typeof vi.fn>).mock.calls[0];
            expect(fetchCall[0]).toBe('/api/validate-doi');
            expect(fetchCall[1].method).toBe('POST');
            expect(fetchCall[1].headers['Content-Type']).toBe('application/json');
            expect(fetchCall[1].headers['Accept']).toBe('application/json');
            expect(fetchCall[1].headers['X-CSRF-TOKEN']).toBe('test-csrf-token');
        });

        it('should extract DOI from dx.doi.org URL', async () => {
            global.fetch = vi.fn().mockResolvedValue({
                ok: true,
                json: () =>
                    Promise.resolve({
                        success: true,
                        metadata: { title: 'Test' },
                    }),
            });

            await resolveDOIMetadata('http://dx.doi.org/10.5194/test');
            const fetchCall = (global.fetch as ReturnType<typeof vi.fn>).mock.calls[0];
            const body = JSON.parse(fetchCall[1].body);
            expect(body.doi).toBe('10.5194/test');
        });
    });
});
