import { describe, expect, it } from 'vitest';

import {
    supportsMetadataResolution,
    validateDOIFormat,
    validateHandleFormat,
    validateIdentifierFormat,
    validateURLFormat,
} from '@/lib/doi-validation';

describe('validateDOIFormat', () => {
    it('validates correct DOI', () => {
        const result = validateDOIFormat('10.5880/test.2024.001');
        expect(result.isValid).toBe(true);
        expect(result.format).toBe('valid');
    });

    it('validates DOI URL (doi.org)', () => {
        const result = validateDOIFormat('https://doi.org/10.5880/test.2024.001');
        expect(result.isValid).toBe(true);
    });

    it('validates DOI URL (dx.doi.org)', () => {
        const result = validateDOIFormat('http://dx.doi.org/10.5880/test.2024.001');
        expect(result.isValid).toBe(true);
    });

    it('validates doi: prefix', () => {
        const result = validateDOIFormat('doi:10.5880/test.2024.001');
        expect(result.isValid).toBe(true);
    });

    it('rejects invalid DOI', () => {
        const result = validateDOIFormat('not-a-doi');
        expect(result.isValid).toBe(false);
        expect(result.format).toBe('invalid');
        expect(result.message).toBeTruthy();
    });

    it('rejects DOI without suffix', () => {
        const result = validateDOIFormat('10.5880');
        expect(result.isValid).toBe(false);
    });

    it('handles whitespace', () => {
        const result = validateDOIFormat('  10.5880/test.2024.001  ');
        expect(result.isValid).toBe(true);
    });

    it('validates complex DOI suffix', () => {
        const result = validateDOIFormat('10.5194/nhess-15-1463-2015');
        expect(result.isValid).toBe(true);
    });
});

describe('validateURLFormat', () => {
    it('validates correct URL', () => {
        const result = validateURLFormat('https://example.com');
        expect(result.isValid).toBe(true);
        expect(result.format).toBe('valid');
    });

    it('rejects invalid URL', () => {
        const result = validateURLFormat('not a url');
        expect(result.isValid).toBe(false);
        expect(result.format).toBe('invalid');
    });
});

describe('validateHandleFormat', () => {
    it('validates standard handle', () => {
        const result = validateHandleFormat('10273/ICDP5054EHW1001');
        expect(result.isValid).toBe(true);
    });

    it('validates handle URL', () => {
        const result = validateHandleFormat('https://hdl.handle.net/10273/ICDP5054EHW1001');
        expect(result.isValid).toBe(true);
    });

    it('validates hdl:// protocol', () => {
        const result = validateHandleFormat('hdl://10273/ICDP5054EHW1001');
        expect(result.isValid).toBe(true);
    });

    it('validates urn:handle: format', () => {
        const result = validateHandleFormat('urn:handle:10273/ICDP5054EHW1001');
        expect(result.isValid).toBe(true);
    });

    it('validates handle with dots in prefix', () => {
        const result = validateHandleFormat('21.T11998/0000-001A-3905-1');
        expect(result.isValid).toBe(true);
    });

    it('validates handle API URL', () => {
        const result = validateHandleFormat('https://hdl.handle.net/api/handles/10273/ICDP5054');
        expect(result.isValid).toBe(true);
    });

    it('rejects invalid handle', () => {
        const result = validateHandleFormat('not a handle');
        expect(result.isValid).toBe(false);
    });
});

describe('validateIdentifierFormat', () => {
    it('delegates to DOI validator', () => {
        const result = validateIdentifierFormat('10.5880/test.2024.001', 'DOI');
        expect(result.isValid).toBe(true);
    });

    it('delegates to URL validator', () => {
        const result = validateIdentifierFormat('https://example.com', 'URL');
        expect(result.isValid).toBe(true);
    });

    it('delegates to Handle validator', () => {
        const result = validateIdentifierFormat('10273/ICDP5054', 'Handle');
        expect(result.isValid).toBe(true);
    });

    it('returns valid for non-empty unknown type', () => {
        const result = validateIdentifierFormat('something', 'ISBN');
        expect(result.isValid).toBe(true);
    });

    it('returns invalid for empty unknown type', () => {
        const result = validateIdentifierFormat('', 'ISBN');
        expect(result.isValid).toBe(false);
    });
});

describe('supportsMetadataResolution', () => {
    it('returns true for DOI', () => {
        expect(supportsMetadataResolution('DOI')).toBe(true);
    });

    it('returns false for other types', () => {
        expect(supportsMetadataResolution('URL')).toBe(false);
        expect(supportsMetadataResolution('Handle')).toBe(false);
        expect(supportsMetadataResolution('ISBN')).toBe(false);
    });
});
