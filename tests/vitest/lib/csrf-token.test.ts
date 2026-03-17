import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { buildCsrfHeaders, ensureCsrfToken, getCsrfToken, getMetaCsrfToken, getXsrfTokenFromCookie } from '@/lib/csrf-token';

describe('getMetaCsrfToken', () => {
    beforeEach(() => {
        document.head.innerHTML = '';
    });

    it('returns token from meta tag', () => {
        const meta = document.createElement('meta');
        meta.name = 'csrf-token';
        meta.content = 'test-token-123';
        document.head.appendChild(meta);

        expect(getMetaCsrfToken()).toBe('test-token-123');
    });

    it('returns null when no meta tag', () => {
        expect(getMetaCsrfToken()).toBeNull();
    });

    it('returns null for empty content', () => {
        const meta = document.createElement('meta');
        meta.name = 'csrf-token';
        meta.content = '';
        document.head.appendChild(meta);

        expect(getMetaCsrfToken()).toBeNull();
    });

    it('returns null for whitespace-only content', () => {
        const meta = document.createElement('meta');
        meta.name = 'csrf-token';
        meta.content = '   ';
        document.head.appendChild(meta);

        expect(getMetaCsrfToken()).toBeNull();
    });
});

describe('getXsrfTokenFromCookie', () => {
    afterEach(() => {
        // Clear cookies
        document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT';
    });

    it('returns null when no cookie exists', () => {
        expect(getXsrfTokenFromCookie()).toBeNull();
    });

    it('reads XSRF-TOKEN cookie', () => {
        document.cookie = 'XSRF-TOKEN=cookie-token-value';
        expect(getXsrfTokenFromCookie()).toBe('cookie-token-value');
    });

    it('decodes URI-encoded cookie values', () => {
        document.cookie = 'XSRF-TOKEN=token%20with%20spaces';
        expect(getXsrfTokenFromCookie()).toBe('token with spaces');
    });
});

describe('getCsrfToken', () => {
    beforeEach(() => {
        document.head.innerHTML = '';
        document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT';
    });

    it('prefers meta token over cookie', () => {
        const meta = document.createElement('meta');
        meta.name = 'csrf-token';
        meta.content = 'meta-token';
        document.head.appendChild(meta);
        document.cookie = 'XSRF-TOKEN=cookie-token';

        expect(getCsrfToken()).toBe('meta-token');
    });

    it('falls back to cookie token', () => {
        document.cookie = 'XSRF-TOKEN=cookie-token';
        expect(getCsrfToken()).toBe('cookie-token');
    });

    it('returns null when neither exists', () => {
        expect(getCsrfToken()).toBeNull();
    });
});

describe('ensureCsrfToken', () => {
    beforeEach(() => {
        document.head.innerHTML = '';
        document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT';
    });

    it('returns token when available', () => {
        const meta = document.createElement('meta');
        meta.name = 'csrf-token';
        meta.content = 'valid-token';
        document.head.appendChild(meta);

        expect(ensureCsrfToken()).toBe('valid-token');
    });

    it('throws when no token available', () => {
        expect(() => ensureCsrfToken()).toThrow('CSRF token not found');
    });
});

describe('buildCsrfHeaders', () => {
    beforeEach(() => {
        document.head.innerHTML = '';
        document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT';
        vi.spyOn(console, 'debug').mockImplementation(() => {});
        vi.spyOn(console, 'error').mockImplementation(() => {});
    });

    it('includes X-CSRF-TOKEN from meta tag', () => {
        const meta = document.createElement('meta');
        meta.name = 'csrf-token';
        meta.content = 'meta-token';
        document.head.appendChild(meta);

        const headers = buildCsrfHeaders();
        expect(headers['X-CSRF-TOKEN']).toBe('meta-token');
    });

    it('includes X-XSRF-TOKEN from cookie', () => {
        document.cookie = 'XSRF-TOKEN=cookie-token';

        const headers = buildCsrfHeaders();
        expect(headers['X-XSRF-TOKEN']).toBe('cookie-token');
    });

    it('includes both headers when both sources available', () => {
        const meta = document.createElement('meta');
        meta.name = 'csrf-token';
        meta.content = 'meta-token';
        document.head.appendChild(meta);
        document.cookie = 'XSRF-TOKEN=cookie-token';

        const headers = buildCsrfHeaders();
        expect(headers['X-CSRF-TOKEN']).toBe('meta-token');
        expect(headers['X-XSRF-TOKEN']).toBe('cookie-token');
    });

    it('returns empty headers when no tokens', () => {
        const headers = buildCsrfHeaders();
        expect(Object.keys(headers)).toHaveLength(0);
    });
});
