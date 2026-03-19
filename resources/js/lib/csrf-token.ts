import type { AxiosHeaderValue } from 'axios';

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
        if (import.meta.env.DEV) {
            console.warn('Failed to decode CSRF cookie value.', error);
        }
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
 * Builds CSRF headers for outgoing requests.
 *
 * - `X-CSRF-TOKEN` is sourced **exclusively** from the `<meta>` tag. The meta
 *   tag contains the **unencrypted** session token rendered by the server. Laravel
 *   compares this header value directly against the session token (no decryption).
 *
 * - `X-XSRF-TOKEN` is sourced from the `XSRF-TOKEN` cookie, which contains the
 *   **encrypted** session token. Laravel decrypts this header before comparing.
 *
 * The encrypted cookie value must **never** be sent as `X-CSRF-TOKEN` because
 * Laravel does not decrypt that header, causing a 419 CSRF mismatch.
 */
export const buildCsrfHeaders = (): Record<string, string> => {
    const headers: Record<string, string> = {};
    const metaToken = getMetaCsrfToken();
    const cookieToken = getXsrfTokenFromCookie();

    // Only log token presence in development, never log actual token values
    if (import.meta.env.DEV) {
        console.debug('[CSRF] Building headers', {
            hasMetaToken: !!metaToken,
            hasCookieToken: !!cookieToken,
        });
    }

    // X-CSRF-TOKEN: unencrypted token from server-rendered meta tag only
    if (metaToken) {
        headers['X-CSRF-TOKEN'] = metaToken;
    }

    // X-XSRF-TOKEN: encrypted token from cookie (Laravel decrypts this)
    if (cookieToken) {
        headers['X-XSRF-TOKEN'] = cookieToken;
    }

    if (Object.keys(headers).length === 0) {
        console.error('[CSRF] No CSRF token found in meta tag or cookie');
    }

    return headers;
};

/**
 * Syncs the XSRF-TOKEN cookie to the axios default `X-XSRF-TOKEN` header.
 *
 * **Important:** The XSRF-TOKEN cookie is **encrypted** by Laravel's
 * `EncryptCookies` middleware. Laravel's `PreventRequestForgery` middleware only
 * decrypts values received via the `X-XSRF-TOKEN` header. The `X-CSRF-TOKEN`
 * header and the `<meta name="csrf-token">` tag must contain the
 * **unencrypted** session token (rendered server-side) and must NOT be
 * overwritten with the encrypted cookie value.
 *
 * This function intentionally does NOT touch `X-CSRF-TOKEN` or the meta tag.
 *
 * @param axiosDefaultHeaders - The axios default headers object (i.e., `axios.defaults.headers.common`)
 * @returns The cookie token if sync was successful, null otherwise
 */
export const syncXsrfTokenToAxios = (
    axiosDefaultHeaders: Record<string, AxiosHeaderValue | undefined>,
): string | null => {
    const token = getXsrfTokenFromCookie();

    if (!token) {
        if (import.meta.env.DEV) {
            console.warn('[CSRF] No token in cookie to sync');
        }
        return null;
    }

    // Only update X-XSRF-TOKEN (Laravel decrypts this header automatically).
    // Do NOT set X-CSRF-TOKEN or the meta tag here – the cookie value is
    // encrypted and would cause a 419 CSRF mismatch if sent as X-CSRF-TOKEN.
    axiosDefaultHeaders['X-XSRF-TOKEN'] = token;

    if (import.meta.env.DEV) {
        console.debug('[CSRF] X-XSRF-TOKEN header synced from cookie');
    }

    return token;
};
