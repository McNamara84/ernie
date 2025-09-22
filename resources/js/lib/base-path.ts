const BASE_PATH_META_NAME = 'app-base-path';
const ORIGINAL_URL_SYMBOL = Symbol('originalUrl');
let cachedBasePath: string | undefined;

const ABSOLUTE_URL_REGEX = /^(?:[a-z][a-z0-9+.+-]*:|\/\/)/i;

const isWindowDefined = () => typeof window !== 'undefined';

const normalizeBasePath = (value: string): string => {
    const trimmed = value.trim();

    if (!trimmed) {
        return '';
    }

    const prefixed = trimmed.startsWith('/') ? trimmed : `/${trimmed}`;
    const withoutTrailingSlash = prefixed.length > 1 && prefixed.endsWith('/')
        ? prefixed.slice(0, -1)
        : prefixed;

    return withoutTrailingSlash === '/' ? '' : withoutTrailingSlash;
};

const readMetaBasePath = (): string => {
    if (typeof document === 'undefined') {
        return '';
    }

    const meta = document.querySelector<HTMLMetaElement>(
        `meta[name="${BASE_PATH_META_NAME}"]`,
    );

    return meta?.content ?? '';
};

export const getBasePath = (): string => {
    if (typeof cachedBasePath !== 'undefined') {
        if (typeof document !== 'undefined') {
            const metaValue = normalizeBasePath(readMetaBasePath());

            if (metaValue && metaValue !== cachedBasePath) {
                cachedBasePath = metaValue;
            }
        }

        return cachedBasePath;
    }

    let basePath = '';

    // Prefer build-time configuration when available (SSR, tests)
    if (typeof import.meta !== 'undefined' && import.meta.env) {
        basePath =
            import.meta.env.VITE_APP_BASE_PATH ||
            import.meta.env.APP_BASE_PATH ||
            '';
    }

    if (!basePath && typeof process !== 'undefined') {
        basePath =
            (process.env?.VITE_APP_BASE_PATH as string | undefined) ||
            (process.env?.APP_BASE_PATH as string | undefined) ||
            '';
    }

    if (!basePath) {
        basePath = readMetaBasePath();
    }

    cachedBasePath = normalizeBasePath(basePath);

    return cachedBasePath;
};

const alreadyPrefixed = (path: string, basePath: string) =>
    basePath && (path === basePath || path.startsWith(`${basePath}/`));

export const withBasePath = (path: string): string => {
    if (!path) {
        return path;
    }

    const basePath = getBasePath();

    if (!basePath) {
        return path;
    }

    if (ABSOLUTE_URL_REGEX.test(path) || path.startsWith('#')) {
        return path;
    }

    if (alreadyPrefixed(path, basePath)) {
        return path;
    }

    if (path.startsWith('/')) {
        return `${basePath}${path}`;
    }

    return `${basePath}/${path}`;
};

type RouteWithDefinition = {
    definition?: {
        url: string;
        [ORIGINAL_URL_SYMBOL]?: string;
    };
};

const defineBasePathUrl = (definition: RouteWithDefinition['definition']) => {
    const originalUrl = definition?.[ORIGINAL_URL_SYMBOL] ?? definition.url;

    Object.defineProperty(definition, ORIGINAL_URL_SYMBOL, {
        configurable: true,
        enumerable: false,
        writable: true,
        value: originalUrl,
    });

    Object.defineProperty(definition, 'url', {
        configurable: true,
        enumerable: true,
        get() {
            const storedOriginal =
                (this as typeof definition)[ORIGINAL_URL_SYMBOL] ?? originalUrl;

            return withBasePath(storedOriginal);
        },
        set(value: string) {
            (this as typeof definition)[ORIGINAL_URL_SYMBOL] = value;
        },
    });
};

export const applyBasePathToRoutes = (
    routes: Record<string, RouteWithDefinition | undefined>,
): void => {
    Object.values(routes).forEach((route) => {
        if (!route?.definition || typeof route.definition.url !== 'string') {
            return;
        }

        const definition = route.definition as RouteWithDefinition['definition'];

        const descriptor = Object.getOwnPropertyDescriptor(definition, 'url');

        if (descriptor?.get && descriptor.set) {
            // Already wrapped with base path support.
            return;
        }

        defineBasePathUrl(definition);
    });
};

export const __testing = {
    resetBasePathCache: () => {
        cachedBasePath = undefined;
    },
    setMetaBasePath: (value: string) => {
        if (!isWindowDefined()) {
            return;
        }

        let meta = document.querySelector<HTMLMetaElement>(
            `meta[name="${BASE_PATH_META_NAME}"]`,
        );

        if (!meta) {
            meta = document.createElement('meta');
            meta.setAttribute('name', BASE_PATH_META_NAME);
            document.head.appendChild(meta);
        }

        meta.content = value;
        cachedBasePath = undefined;
    },
};

export default withBasePath;
