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

export const buildCsrfHeaders = (): Record<string, string> => {
    const headers: Record<string, string> = {};
    const metaToken = getMetaCsrfToken();
    const cookieToken = getXsrfTokenFromCookie();

    if (metaToken) {
        headers['X-CSRF-TOKEN'] = metaToken;
    }

    if (cookieToken) {
        headers['X-XSRF-TOKEN'] = cookieToken;

        if (!headers['X-CSRF-TOKEN']) {
            headers['X-CSRF-TOKEN'] = cookieToken;
        }
    }

    return headers;
};
