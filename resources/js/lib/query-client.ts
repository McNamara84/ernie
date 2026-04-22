import { QueryClient } from '@tanstack/react-query';

/**
 * Default options applied to every query created through the shared client.
 *
 * Rationale:
 * - `staleTime: 60s` strikes a balance between freshness and request reduction
 *   for the external lookup APIs (ROR, MSL, PID4INST, GCMD).
 * - `gcTime: 5 min` keeps cached data available when components re-mount
 *   without indefinitely growing memory.
 * - `retry: 1` avoids hammering failing external services while still tolerating
 *   transient network blips.
 * - `refetchOnWindowFocus: false` prevents surprising re-fetches while the
 *   curator is working in the editor for extended periods.
 */
export const defaultQueryClientOptions = {
    defaultOptions: {
        queries: {
            staleTime: 60_000,
            gcTime: 5 * 60_000,
            retry: 1,
            refetchOnWindowFocus: false,
        },
        mutations: {
            retry: 0,
        },
    },
} as const;

/**
 * Create a fresh QueryClient instance.
 *
 * A factory is used (rather than a singleton) so that each request on the
 * SSR server receives its own isolated cache.
 */
export function createQueryClient(): QueryClient {
    return new QueryClient(defaultQueryClientOptions);
}
