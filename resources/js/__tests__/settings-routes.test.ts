import { afterEach, describe, expect, it } from 'vitest';
import { update } from '@/routes/settings';

describe('settings routes', () => {
    afterEach(() => {
        window.history.replaceState({}, '', '/');
    });

    it('generates update routes and forms', () => {
        expect(update()).toEqual({ url: '/settings', method: 'post' });
        expect(update.url({ query: { theme: 'dark' } })).toBe('/settings?theme=dark');
        expect(update.post({ query: { theme: 'dark' } })).toEqual({ url: '/settings?theme=dark', method: 'post' });
        expect(update.form.post({ query: { theme: 'dark' } })).toEqual({ action: '/settings?theme=dark', method: 'post' });

        window.history.replaceState({}, '', '/settings?foo=bar');
        expect(update.form.post({ mergeQuery: { theme: 'light' } })).toEqual({
            action: '/settings?foo=bar&theme=light',
            method: 'post',
        });
    });
});
