import { describe, expect, it } from 'vitest';

import { normalizeUrlLike } from '@/lib/url-normalizer';

describe('normalizeUrlLike', () => {
    it('leaves relative URLs unchanged', () => {
        expect(normalizeUrlLike('/login')).toBe('/login');
    });

    it('fixes missing colon in scheme', () => {
        expect(normalizeUrlLike('https//ernie.rz-vm182.gfz.de/login')).toBe('https://ernie.rz-vm182.gfz.de/login');
        expect(normalizeUrlLike('http//example.com')).toBe('http://example.com');
    });

    it('fixes duplicate scheme prefixes', () => {
        expect(normalizeUrlLike('https://https//ernie.rz-vm182.gfz.de/login')).toBe('https://ernie.rz-vm182.gfz.de/login');
        expect(normalizeUrlLike('https://https://ernie.rz-vm182.gfz.de/login')).toBe('https://ernie.rz-vm182.gfz.de/login');
    });

    it('collapses excessive slashes after scheme', () => {
        expect(normalizeUrlLike('https:////ernie.rz-vm182.gfz.de/login')).toBe('https://ernie.rz-vm182.gfz.de/login');
    });
});
