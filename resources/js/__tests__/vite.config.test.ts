import { describe, expect, it } from 'vitest';

import { computeBasePath } from '../../../vite.config';

describe('computeBasePath', () => {
    it('returns default build path when asset url is not provided', () => {
        expect(computeBasePath()).toBe('/build/');
    });

    it('returns default build path when asset url is empty', () => {
        expect(computeBasePath('')).toBe('/build/');
    });

    it('appends build directory to relative paths', () => {
        expect(computeBasePath('/ernie')).toBe('/ernie/build/');
    });

    it('appends build directory to relative paths with trailing slashes', () => {
        expect(computeBasePath('/ernie/')).toBe('/ernie/build/');
    });

    it('preserves fully qualified urls', () => {
        expect(computeBasePath('https://example.com/ernie')).toBe('https://example.com/ernie/build/');
    });

    it('normalizes fully qualified urls with trailing slashes', () => {
        expect(computeBasePath('https://example.com/ernie/')).toBe('https://example.com/ernie/build/');
    });
});
