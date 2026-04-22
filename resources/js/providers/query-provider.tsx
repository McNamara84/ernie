import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { lazy, Suspense, useState } from 'react';

import { createQueryClient } from '@/lib/query-client';

// Devtools are only loaded in the dev build *and* on the client: `QueryProvider`
// is also used from the SSR entry point (`resources/js/ssr.tsx`), where
// rendering a `React.lazy` / `Suspense` boundary can emit Suspense markers or
// warnings from `renderToString`. The `typeof window` guard ensures the lazy
// import is never evaluated on the SSR server.
const ReactQueryDevtools =
    import.meta.env.DEV && typeof window !== 'undefined'
        ? lazy(() =>
              import('@tanstack/react-query-devtools').then((mod) => ({
                  default: mod.ReactQueryDevtools,
              })),
          )
        : null;

export interface QueryProviderProps {
    /**
     * Optional pre-configured client. When omitted, a fresh instance is created
     * once per provider mount.
     *
     * Passing a custom client is primarily useful for tests and for the SSR
     * entry point, where a new client must be created per request.
     */
    client?: QueryClient;
    children: React.ReactNode;
}

/**
 * Wraps the React tree with a shared {@link QueryClient}.
 *
 * Keeps the devtools out of the production bundle by guarding the import and
 * render with `import.meta.env.DEV`, and additionally out of the SSR server
 * by checking for `window` — `renderToString` does not support Suspense
 * boundaries and would otherwise emit stray Suspense markers / warnings.
 */
export function QueryProvider({ client, children }: QueryProviderProps) {
    // Always maintain our own fallback client so we honour the prop contract
    // even if `client` is unset after a previous render: lazily creating the
    // internal client inside a `useState` initialiser off of `client` would
    // freeze the originally-provided instance for the lifetime of the
    // component and keep using it after the prop is removed.
    const [internalClient] = useState(() => createQueryClient());
    const activeClient = client ?? internalClient;

    return (
        <QueryClientProvider client={activeClient}>
            {children}
            {ReactQueryDevtools && (
                <Suspense fallback={null}>
                    <ReactQueryDevtools buttonPosition="bottom-left" initialIsOpen={false} />
                </Suspense>
            )}
        </QueryClientProvider>
    );
}
