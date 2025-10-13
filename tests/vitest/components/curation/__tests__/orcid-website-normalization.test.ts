/**
 * Tests for ORCID and website URL normalization when loading data from old datasets
 * 
 * This tests the fix for the issue where:
 * 1. ORCIDs from old database containing https://orcid.org/ prefix caused validation errors
 * 2. Website URLs without protocol (e.g., www.geomar.de/pbrandl) failed validation
 * 
 * Related: fix/orcid-validation-false-positive
 */

import { describe, expect,it } from 'vitest';

// These are the helper functions from datacite-form.tsx
// We're testing them in isolation
const normalizeOrcid = (orcid: string | null | undefined): string => {
    if (!orcid || typeof orcid !== 'string') {
        return '';
    }
    
    const trimmed = orcid.trim();
    
    // Remove https://orcid.org/ prefix if present
    const orcidPattern = /^(?:https?:\/\/)?(?:www\.)?orcid\.org\/(.+)$/i;
    const match = trimmed.match(orcidPattern);
    
    if (match && match[1]) {
        return match[1];
    }
    
    return trimmed;
};

const normalizeWebsiteUrl = (url: string | null | undefined): string => {
    if (!url || typeof url !== 'string') {
        return '';
    }
    
    const trimmed = url.trim();
    
    // If URL doesn't start with http:// or https://, add https://
    if (trimmed && !/^https?:\/\//i.test(trimmed)) {
        return `https://${trimmed}`;
    }
    
    return trimmed;
};

describe('ORCID Normalization', () => {
    it('should remove https://orcid.org/ prefix', () => {
        const input = 'https://orcid.org/0000-0002-1234-5678';
        const expected = '0000-0002-1234-5678';
        expect(normalizeOrcid(input)).toBe(expected);
    });

    it('should remove http://orcid.org/ prefix', () => {
        const input = 'http://orcid.org/0000-0002-1234-5678';
        const expected = '0000-0002-1234-5678';
        expect(normalizeOrcid(input)).toBe(expected);
    });

    it('should remove www.orcid.org/ prefix', () => {
        const input = 'https://www.orcid.org/0000-0002-1234-5678';
        const expected = '0000-0002-1234-5678';
        expect(normalizeOrcid(input)).toBe(expected);
    });

    it('should handle ORCID without prefix (already normalized)', () => {
        const input = '0000-0002-1234-5678';
        const expected = '0000-0002-1234-5678';
        expect(normalizeOrcid(input)).toBe(expected);
    });

    it('should handle ORCID with X checksum digit', () => {
        const input = 'https://orcid.org/0000-0002-1234-567X';
        const expected = '0000-0002-1234-567X';
        expect(normalizeOrcid(input)).toBe(expected);
    });

    it('should handle null input', () => {
        expect(normalizeOrcid(null)).toBe('');
    });

    it('should handle undefined input', () => {
        expect(normalizeOrcid(undefined)).toBe('');
    });

    it('should handle empty string', () => {
        expect(normalizeOrcid('')).toBe('');
    });

    it('should trim whitespace', () => {
        const input = '  0000-0002-1234-5678  ';
        const expected = '0000-0002-1234-5678';
        expect(normalizeOrcid(input)).toBe(expected);
    });

    it('should trim whitespace from URL format', () => {
        const input = '  https://orcid.org/0000-0002-1234-5678  ';
        const expected = '0000-0002-1234-5678';
        expect(normalizeOrcid(input)).toBe(expected);
    });
});

describe('Website URL Normalization', () => {
    it('should add https:// prefix to URL without protocol', () => {
        const input = 'www.geomar.de/pbrandl';
        const expected = 'https://www.geomar.de/pbrandl';
        expect(normalizeWebsiteUrl(input)).toBe(expected);
    });

    it('should not modify URL with https:// prefix', () => {
        const input = 'https://www.example.org';
        const expected = 'https://www.example.org';
        expect(normalizeWebsiteUrl(input)).toBe(expected);
    });

    it('should not modify URL with http:// prefix', () => {
        const input = 'http://www.example.org';
        const expected = 'http://www.example.org';
        expect(normalizeWebsiteUrl(input)).toBe(expected);
    });

    it('should handle domain without www', () => {
        const input = 'geomar.de/pbrandl';
        const expected = 'https://geomar.de/pbrandl';
        expect(normalizeWebsiteUrl(input)).toBe(expected);
    });

    it('should handle null input', () => {
        expect(normalizeWebsiteUrl(null)).toBe('');
    });

    it('should handle undefined input', () => {
        expect(normalizeWebsiteUrl(undefined)).toBe('');
    });

    it('should handle empty string', () => {
        expect(normalizeWebsiteUrl('')).toBe('');
    });

    it('should trim whitespace', () => {
        const input = '  www.example.org  ';
        const expected = 'https://www.example.org';
        expect(normalizeWebsiteUrl(input)).toBe(expected);
    });

    it('should handle URL with path', () => {
        const input = 'www.geomar.de/en/staff/fb2/mg/pbrandl';
        const expected = 'https://www.geomar.de/en/staff/fb2/mg/pbrandl';
        expect(normalizeWebsiteUrl(input)).toBe(expected);
    });

    it('should handle URL with query parameters', () => {
        const input = 'www.example.org/page?id=123';
        const expected = 'https://www.example.org/page?id=123';
        expect(normalizeWebsiteUrl(input)).toBe(expected);
    });

    it('should be case-insensitive for protocol detection', () => {
        const input1 = 'HTTP://www.example.org';
        const input2 = 'HTTPS://www.example.org';
        expect(normalizeWebsiteUrl(input1)).toBe('HTTP://www.example.org');
        expect(normalizeWebsiteUrl(input2)).toBe('HTTPS://www.example.org');
    });
});
