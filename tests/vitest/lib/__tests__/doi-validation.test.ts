import { describe, expect, it } from 'vitest';

import { validateDOIFormat, validateHandleFormat, validateIdentifierFormat, validateURLFormat } from '@/lib/doi-validation';

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
        });

        it('should validate DOI URL with doi.org', () => {
            const result = validateDOIFormat('https://doi.org/10.5194/nhess-15-1463-2015');
            expect(result.isValid).toBe(true);
        });

        it('should validate DOI URL with dx.doi.org', () => {
            const result = validateDOIFormat('http://dx.doi.org/10.5194/nhess-15-1463-2015');
            expect(result.isValid).toBe(true);
        });

        it('should reject invalid DOI', () => {
            const result = validateDOIFormat('not-a-doi');
            expect(result.isValid).toBe(false);
        });
    });

    describe('validateURLFormat', () => {
        it('should validate http URL', () => {
            const result = validateURLFormat('http://example.com');
            expect(result.isValid).toBe(true);
        });

        it('should validate https URL', () => {
            const result = validateURLFormat('https://example.com/path?query=1');
            expect(result.isValid).toBe(true);
        });

        it('should reject invalid URL', () => {
            const result = validateURLFormat('not-a-url');
            expect(result.isValid).toBe(false);
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
    });
});
