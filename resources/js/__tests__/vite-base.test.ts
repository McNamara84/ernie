import { describe, expect, it } from 'vitest';
import { normalizeAssetBase, resolveViteBase } from '../lib/vite-base';

describe('normalizeAssetBase', () => {
    it('returns an empty string when no asset url is provided', () => {
        expect(normalizeAssetBase(undefined)).toBe('');
        expect(normalizeAssetBase(null)).toBe('');
    });

    it('removes a trailing slash from the provided asset url', () => {
        expect(normalizeAssetBase('https://example.com/ernie/')).toBe('https://example.com/ernie');
    });
});

describe('resolveViteBase', () => {
    it('falls back to the build directory when no asset url is set', () => {
        expect(resolveViteBase(undefined)).toBe('/build/');
    });

    it('uses the normalized asset url with build suffix', () => {
        expect(resolveViteBase('https://example.com/ernie/')).toBe('https://example.com/ernie/build/');
    });
});
