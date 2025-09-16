import { afterEach, describe, expect, it } from 'vitest';
import { users } from '@/routes/docs';

describe('docs routes', () => {
    afterEach(() => {
        window.history.replaceState({}, '', '/');
    });

    it('generates user documentation routes', () => {
        expect(users()).toEqual({ url: '/docs/users', method: 'get' });
        expect(users.url({ query: { q: 'guide' } })).toBe('/docs/users?q=guide');
        expect(users.get({ query: { q: 'guide' } })).toEqual({ url: '/docs/users?q=guide', method: 'get' });
        expect(users.head({ query: { q: 'guide' } })).toEqual({ url: '/docs/users?q=guide', method: 'head' });
    });

    it('creates forms with merged query strings', () => {
        expect(users.form.get({ query: { page: 2 } })).toEqual({ action: '/docs/users?page=2', method: 'get' });

        window.history.replaceState({}, '', '/docs/users?foo=bar');
        expect(users.form.head({ mergeQuery: { page: 2 } })).toEqual({
            action: '/docs/users?foo=bar&_method=HEAD&page=2',
            method: 'get',
        });
    });
});
