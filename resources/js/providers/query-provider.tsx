import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { lazy, Suspense, useRef } from 'react';

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
    // Lazily allocate a fallback client only when the consumer does not pass
    // one in. Callers such as `app.tsx` and the SSR entry point always supply
    // a `client`, so eagerly creating an internal instance would waste an
    // allocation per provider mount / SSR request.
    //
    // We keep the fallback in a ref (not `useState`) so the allocation is
    // skipped entirely on renders where `client` is supplied. The ref is
    // memoised for the lifetime of the component, which means that if a
    // caller initially passes a client and later omits it we still get a
    // stable fallback from that point on — honouring the prop contract
    // without paying the cost up front.
    const fallbackClientRef = useRef<QueryClient | null>(null);
    const activeClient = client ?? (fallbackClientRef.current ??= createQueryClient());

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
