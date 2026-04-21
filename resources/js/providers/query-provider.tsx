import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { lazy, Suspense, useState } from 'react';

import { createQueryClient } from '@/lib/query-client';

const ReactQueryDevtools = import.meta.env.DEV
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
 * render with `import.meta.env.DEV`.
 */
export function QueryProvider({ client, children }: QueryProviderProps) {
    const [internalClient] = useState(() => client ?? createQueryClient());
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
