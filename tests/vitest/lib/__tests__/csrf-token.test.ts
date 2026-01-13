import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { buildCsrfHeaders, ensureCsrfToken, getCsrfToken, getMetaCsrfToken, getXsrfTokenFromCookie } from '@/lib/csrf-token';

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

describe('getMetaCsrfToken', () => {
    it('returns the token from the meta tag when present', () => {
        document.head.innerHTML = '<meta name="csrf-token" content="test-meta-token">';

        expect(getMetaCsrfToken()).toBe('test-meta-token');
    });

    it('returns null when meta tag is missing', () => {
        expect(getMetaCsrfToken()).toBeNull();
    });

    it('returns null when meta content is empty', () => {
        document.head.innerHTML = '<meta name="csrf-token" content="">';

        expect(getMetaCsrfToken()).toBeNull();
    });

    it('returns null when meta content is only whitespace', () => {
        document.head.innerHTML = '<meta name="csrf-token" content="   ">';

        expect(getMetaCsrfToken()).toBeNull();
    });

    it('trims whitespace from the token', () => {
        document.head.innerHTML = '<meta name="csrf-token" content="  trimmed-token  ">';

        expect(getMetaCsrfToken()).toBe('trimmed-token');
    });
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

    it('returns null when the cookie is missing', () => {
        expect(getXsrfTokenFromCookie()).toBeNull();
    });

    it('returns null when cookie value is empty', () => {
        document.cookie = 'XSRF-TOKEN=';

        expect(getXsrfTokenFromCookie()).toBeNull();
    });

    it('returns the token when other cookies are present', () => {
        document.cookie = 'other-cookie=value1';
        document.cookie = 'XSRF-TOKEN=my-token';
        document.cookie = 'another-cookie=value2';

        expect(getXsrfTokenFromCookie()).toBe('my-token');
    });
});

describe('getCsrfToken', () => {
    it('returns the meta token when available', () => {
        document.head.innerHTML = '<meta name="csrf-token" content="meta-token">';
        document.cookie = 'XSRF-TOKEN=cookie-token';

        expect(getCsrfToken()).toBe('meta-token');
    });

    it('falls back to cookie token when meta tag is missing', () => {
        document.cookie = 'XSRF-TOKEN=cookie-token';

        expect(getCsrfToken()).toBe('cookie-token');
    });

    it('returns null when both are missing', () => {
        expect(getCsrfToken()).toBeNull();
    });
});

describe('ensureCsrfToken', () => {
    it('returns the token when available', () => {
        document.head.innerHTML = '<meta name="csrf-token" content="ensured-token">';

        expect(ensureCsrfToken()).toBe('ensured-token');
    });

    it('throws an error when no token is found', () => {
        expect(() => ensureCsrfToken()).toThrow('CSRF token not found');
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

    it('returns only X-CSRF-TOKEN when only meta tag is present', () => {
        document.head.innerHTML = '<meta name="csrf-token" content="meta-only">';

        expect(buildCsrfHeaders()).toEqual({
            'X-CSRF-TOKEN': 'meta-only',
        });
    });

    it('logs an error and returns empty object when no tokens are available', () => {
        const errorSpy = vi.spyOn(console, 'error').mockImplementation(() => undefined);

        const headers = buildCsrfHeaders();

        expect(headers).toEqual({});
        expect(errorSpy).toHaveBeenCalledWith('[CSRF] No CSRF token found in meta tag or cookie');

        errorSpy.mockRestore();
    });
});
