import { describe, expect, it } from 'vitest';
import { resolveBasePath } from '../lib/vite-base';

describe('resolveBasePath', () => {
    it('returns /build/ for build command', () => {
        expect(resolveBasePath('build')).toBe('/build/');
    });

    it('returns root path for non-build commands', () => {
        expect(resolveBasePath('serve')).toBe('/');
        expect(resolveBasePath('test')).toBe('/');
    });
});
