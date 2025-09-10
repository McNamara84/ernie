import { describe, expect, it } from 'vitest';
import RedirectController from '@/actions/Illuminate/Routing/RedirectController';

// Unit tests for route generation helpers

describe('RedirectController', () => {
    it('generates basic route definitions and urls', () => {
        expect(RedirectController()).toEqual({ url: '/settings', method: 'get' });
        expect(RedirectController.url()).toBe('/settings');
        expect(RedirectController.url({ query: { foo: 'bar' } })).toBe('/settings?foo=bar');
        expect(RedirectController.post()).toEqual({ url: '/settings', method: 'post' });
    });

    it('generates form definitions for different methods', () => {
        expect(RedirectController.form.get()).toEqual({ action: '/settings', method: 'get' });
        expect(RedirectController.form.head()).toEqual({ action: '/settings?_method=HEAD', method: 'get' });

        const del = RedirectController.form.delete({ query: { foo: 'bar' } });
        const url = new URL(del.action, 'http://localhost');
        expect(url.pathname).toBe('/settings');
        expect(url.searchParams.get('_method')).toBe('DELETE');
        expect(url.searchParams.get('foo')).toBe('bar');
        expect(del.method).toBe('post');
    });
});

