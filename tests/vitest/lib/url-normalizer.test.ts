import { describe, expect, it } from 'vitest';

import { normalizeUrlLike } from '@/lib/url-normalizer';

describe('normalizeUrlLike', () => {
    it('leaves relative URLs unchanged', () => {
        expect(normalizeUrlLike('/login')).toBe('/login');
        expect(normalizeUrlLike('/path/to/resource')).toBe('/path/to/resource');
    });

    it('leaves non-HTTP URLs unchanged', () => {
        expect(normalizeUrlLike('ftp://files.example.com')).toBe('ftp://files.example.com');
        expect(normalizeUrlLike('mailto:test@example.com')).toBe('mailto:test@example.com');
        expect(normalizeUrlLike('example.com/path')).toBe('example.com/path');
    });

    it('trims whitespace from URLs', () => {
        expect(normalizeUrlLike('  https://example.com  ')).toBe('https://example.com');
    });

    it('fixes missing colon in scheme', () => {
        expect(normalizeUrlLike('https//ernie.rz-vm182.gfz.de/login')).toBe('https://ernie.rz-vm182.gfz.de/login');
        expect(normalizeUrlLike('http//example.com')).toBe('http://example.com');
        expect(normalizeUrlLike('HTTP//EXAMPLE.COM')).toBe('HTTP://EXAMPLE.COM');
    });

    it('fixes duplicate scheme prefixes', () => {
        expect(normalizeUrlLike('https://https//ernie.rz-vm182.gfz.de/login')).toBe('https://ernie.rz-vm182.gfz.de/login');
        expect(normalizeUrlLike('https://https://ernie.rz-vm182.gfz.de/login')).toBe('https://ernie.rz-vm182.gfz.de/login');
        expect(normalizeUrlLike('http://http://example.com')).toBe('http://example.com');
    });

    it('collapses excessive slashes after scheme', () => {
        expect(normalizeUrlLike('https:////ernie.rz-vm182.gfz.de/login')).toBe('https://ernie.rz-vm182.gfz.de/login');
        expect(normalizeUrlLike('http:///example.com')).toBe('http://example.com');
    });

    it('handles valid URLs without modification', () => {
        expect(normalizeUrlLike('https://example.com')).toBe('https://example.com');
        expect(normalizeUrlLike('http://example.com/path?query=value')).toBe('http://example.com/path?query=value');
    });
});
