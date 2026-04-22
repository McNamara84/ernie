import { createInertiaApp } from '@inertiajs/react';
import createServer from '@inertiajs/react/server';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import ReactDOMServer from 'react-dom/server';

import { createQueryClient } from './lib/query-client';
import { QueryProvider } from './providers/query-provider';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createServer((page) =>
    createInertiaApp({
        page,
        render: ReactDOMServer.renderToString,
        title: (title) => (title ? `${title} - ${appName}` : appName),
        resolve: (name) => resolvePageComponent(`./pages/${name}.tsx`, import.meta.glob('./pages/**/*.tsx')),
        setup: ({ App, props }) => {
            // Create a fresh QueryClient per request so SSR caches do not leak
            // between concurrent requests on the Node SSR server.
            const queryClient = createQueryClient();

            return (
                <QueryProvider client={queryClient}>
                    <App {...props} />
                </QueryProvider>
            );
        },
    }),
);
