/**
 * Shared Route Mock Factory
 *
 * Provides a unified way to mock Wayfinder-generated route functions.
 * Supports simple paths, query parameters, and `.url()` method chains.
 *
 * @example
 * // Simple routes:
 * vi.mock('@/routes', () => createRouteMocks({
 *     dashboard: '/dashboard',
 *     editor: '/editor',
 *     login: '/login',
 * }));
 *
 * // With query parameter support:
 * vi.mock('@/routes', () => createRouteMocks({
 *     dashboard: '/dashboard',
 *     editor: '/editor', // automatically supports { query: {...} }
 * }));
 */

// ============================================================================
// Route Factory
// ============================================================================

interface RouteDefinition {
    url: string;
    method?: string;
}

type RouteFn = ((options?: { query?: Record<string, string | number> }) => RouteDefinition) & {
    url: (options?: { query?: Record<string, string | number> }) => string;
    definition: RouteDefinition;
    get: (options?: { query?: Record<string, string | number> }) => RouteDefinition;
    head: (options?: { query?: Record<string, string | number> }) => RouteDefinition;
};

/**
 * Creates a single route mock function that mimics Wayfinder route behavior.
 * The returned function:
 * - Returns `{ url, method }` when called
 * - Has a `.url()` method that returns just the URL string
 * - Has `.get()` and `.head()` methods
 * - Has a `.definition` property
 * - Supports `{ query: {...} }` options for query parameters
 */
export function createRoute(path: string): RouteFn {
    const buildUrl = (options?: { query?: Record<string, string | number> }): string => {
        if (!options?.query || Object.keys(options.query).length === 0) {
            return path;
        }
        const searchParams = new URLSearchParams();
        Object.entries(options.query).forEach(([key, value]) => {
            searchParams.append(key, String(value));
        });
        return `${path}?${searchParams.toString()}`;
    };

    const routeFn = ((options?: { query?: Record<string, string | number> }) => ({
        url: buildUrl(options),
        method: 'get',
    })) as RouteFn;

    routeFn.url = (options?: { query?: Record<string, string | number> }) => buildUrl(options);
    routeFn.definition = { url: path, method: 'get' };
    routeFn.get = (options?: { query?: Record<string, string | number> }) => ({
        url: buildUrl(options),
        method: 'get',
    });
    routeFn.head = (options?: { query?: Record<string, string | number> }) => ({
        url: buildUrl(options),
        method: 'head',
    });

    return routeFn;
}

/**
 * Creates a complete `@/routes` module mock from a map of route names to paths.
 *
 * @example
 * vi.mock('@/routes', () => createRouteMocks({
 *     dashboard: '/dashboard',
 *     editor: '/editor',
 *     login: '/login',
 *     about: '/about',
 *     legalNotice: '/legal-notice',
 *     changelog: '/changelog',
 * }));
 */
export function createRouteMocks(routes: Record<string, string>): Record<string, RouteFn> {
    const result: Record<string, RouteFn> = {};

    for (const [name, path] of Object.entries(routes)) {
        result[name] = createRoute(path);
    }

    return result;
}

// ============================================================================
// Common Route Sets
// ============================================================================

/**
 * Common public routes used across many tests.
 */
export const COMMON_PUBLIC_ROUTES: Record<string, string> = {
    home: '/',
    login: '/login',
    about: '/about',
    legalNotice: '/legal-notice',
    changelog: '/changelog',
    docs: '/docs',
};

/**
 * Common authenticated routes.
 */
export const COMMON_AUTH_ROUTES: Record<string, string> = {
    ...COMMON_PUBLIC_ROUTES,
    dashboard: '/dashboard',
    editor: '/editor',
    resources: '/resources',
    settings: '/settings',
    logout: '/logout',
};

/**
 * Common admin routes (includes auth routes).
 */
export const COMMON_ADMIN_ROUTES: Record<string, string> = {
    ...COMMON_AUTH_ROUTES,
    users: '/users',
    logs: '/logs',
    igsns: '/igsns',
};
