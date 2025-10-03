import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { buildCsrfHeaders, getXsrfTokenFromCookie } from '@/lib/csrf-token';

const clearCookies = () => {
    const cookies = document.cookie ? document.cookie.split(';') : [];

    for (const cookie of cookies) {
        const [rawName] = cookie.split('=');
        const name = rawName?.trim();

        if (name) {
            document.cookie = `${name}=;expires=${new Date(0).toUTCString()};path=/`;
        }
    }
};

beforeEach(() => {
    clearCookies();
    document.head.innerHTML = '';
});

afterEach(() => {
    clearCookies();
    document.head.innerHTML = '';
});

describe('getXsrfTokenFromCookie', () => {
    it('returns decoded values when the cookie is URI encoded', () => {
        document.cookie = `XSRF-TOKEN=${encodeURIComponent('token with spaces')}`;

        expect(getXsrfTokenFromCookie()).toBe('token with spaces');
    });

    it('falls back to the raw value when decoding fails', () => {
        const warnSpy = vi.spyOn(console, 'warn').mockImplementation(() => undefined);

        document.cookie = 'XSRF-TOKEN=test%';

        expect(getXsrfTokenFromCookie()).toBe('test%');
        expect(warnSpy).toHaveBeenCalledWith(
            'Failed to decode CSRF cookie value.',
            expect.any(Error),
        );

        warnSpy.mockRestore();
    });
});

describe('buildCsrfHeaders', () => {
    it('prefers meta tokens for X-CSRF-TOKEN while still setting the cookie header', () => {
        document.head.innerHTML = '<meta name="csrf-token" content="meta-token">';
        document.cookie = 'XSRF-TOKEN=cookie-token';

        expect(buildCsrfHeaders()).toEqual({
            'X-CSRF-TOKEN': 'meta-token',
            'X-XSRF-TOKEN': 'cookie-token',
        });
    });

    it('falls back to the cookie token when no meta tag exists', () => {
        document.cookie = 'XSRF-TOKEN=cookie-token';

        expect(buildCsrfHeaders()).toEqual({
            'X-CSRF-TOKEN': 'cookie-token',
            'X-XSRF-TOKEN': 'cookie-token',
        });
    });
});
