import { describe, expect, it, beforeEach } from 'vitest';
import { applyBasePathToRoutes, withBasePath, __testing as basePathTesting } from '@/lib/base-path';

describe('withBasePath', () => {
    beforeEach(() => {
        document.head.innerHTML = '';
        basePathTesting.resetBasePathCache();
    });

    it('returns the original path when no base path is configured', () => {
        expect(withBasePath('/docs')).toBe('/docs');
    });

    it('prefixes the provided path with the base path meta value', () => {
        basePathTesting.setMetaBasePath('/ernie');
        expect(withBasePath('/docs')).toBe('/ernie/docs');
    });

    it('does not double prefix paths that already include the base path', () => {
        basePathTesting.setMetaBasePath('/ernie');
        expect(withBasePath('/ernie/docs')).toBe('/ernie/docs');
    });
});

describe('applyBasePathToRoutes', () => {
    beforeEach(() => {
        document.head.innerHTML = '';
        basePathTesting.resetBasePathCache();
    });

    it('updates route definitions using the configured base path', () => {
        const route = Object.assign(
            () => ({ url: '/docs', method: 'get' }),
            {
                definition: { url: '/docs' },
            },
        );

        applyBasePathToRoutes({ route });
        expect(route.definition.url).toBe('/docs');

        basePathTesting.setMetaBasePath('/ernie');
        basePathTesting.resetBasePathCache();
        applyBasePathToRoutes({ route });
        expect(route.definition.url).toBe('/ernie/docs');

        applyBasePathToRoutes({ route });
        expect(route.definition.url).toBe('/ernie/docs');
    });
});
