const CSRF_META_SELECTOR = 'meta[name="csrf-token"]';
const XSRF_COOKIE_NAME = 'XSRF-TOKEN';

const isBrowser = () => typeof document !== 'undefined';

const safeTrim = (value?: string | null): string | null => {
    if (!value) {
        return null;
    }

    const trimmed = value.trim();
    return trimmed.length > 0 ? trimmed : null;
};

/**
 * Safely decodes URI-encoded cookie values while tolerating malformed input.
 *
 * The browser may persist cookie values that are not valid percent-encoded
 * strings (for example when set by non-URI-aware tooling). Because
 * `decodeURIComponent` throws on malformed input, this helper falls back to
 * returning the original value when decoding fails so that consumers can still
 * read the cookie.
 */
const safeDecode = (value: string): string => {
    try {
        return decodeURIComponent(value);
    } catch (error) {
        console.warn('Failed to decode CSRF cookie value.', error);
        return value;
    }
};

const readCookie = (name: string): string | null => {
    if (!isBrowser()) {
        return null;
    }

    const cookies = document.cookie ? document.cookie.split(';') : [];

    for (const rawCookie of cookies) {
        const cookie = rawCookie.trim();
        if (cookie.startsWith(`${name}=`)) {
            const value = cookie.substring(name.length + 1);
            return value ? safeDecode(value) : null;
        }
    }

    return null;
};

export const getMetaCsrfToken = (): string | null => {
    if (!isBrowser()) {
        return null;
    }

    const meta = document.querySelector<HTMLMetaElement>(CSRF_META_SELECTOR);
    return safeTrim(meta?.content ?? null);
};

export const getXsrfTokenFromCookie = (): string | null => readCookie(XSRF_COOKIE_NAME);

export const getCsrfToken = (): string | null => {
    return getMetaCsrfToken() ?? getXsrfTokenFromCookie();
};

export const ensureCsrfToken = (): string => {
    const token = getCsrfToken();

    if (!token) {
        throw new Error('CSRF token not found');
    }

    return token;
};

/**
 * Builds CSRF headers prioritising the meta tag token for `X-CSRF-TOKEN` while
 * always sending the cookie token when available.
 *
 * Some middleware validates `X-CSRF-TOKEN`, which should mirror the meta tag
 * value when present, while others expect `X-XSRF-TOKEN` sourced from the
 * cookie. Sending both ensures compatibility across deployments that rely on
 * either header, with meta tokens taking precedence over cookie fallbacks for
 * `X-CSRF-TOKEN`.
 */
export const buildCsrfHeaders = (): Record<string, string> => {
    const headers: Record<string, string> = {};
    const metaToken = getMetaCsrfToken();
    const cookieToken = getXsrfTokenFromCookie();

    console.debug('[CSRF] Building headers', { 
        metaToken: metaToken ? `${metaToken.substring(0, 10)}...` : null,
        cookieToken: cookieToken ? `${cookieToken.substring(0, 10)}...` : null 
    });

    if (metaToken) {
        headers['X-CSRF-TOKEN'] = metaToken;
    }

    if (cookieToken) {
        headers['X-XSRF-TOKEN'] = cookieToken;

        if (!headers['X-CSRF-TOKEN']) {
            headers['X-CSRF-TOKEN'] = cookieToken;
        }
    }

    if (Object.keys(headers).length === 0) {
        console.error('[CSRF] No CSRF token found in meta tag or cookie');
    }

    return headers;
};
