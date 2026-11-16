import { QueryClient } from '@tanstack/react-query';

/** A fresh cache per test, without network retries or lingering GC timers. */
export function createTestQueryClient(): QueryClient {
    return new QueryClient({
        defaultOptions: {
            queries: { retry: false, gcTime: 0, staleTime: 0 },
            mutations: { retry: false },
        },
    });
}
