/**
 * Custom render utility that wraps components with required providers.
 *
 * After the shadcn/ui v4.1 upgrade, Tooltip no longer bundles its own
 * TooltipProvider – it is expected at the layout level. This wrapper
 * supplies that context so every test behaves like the real app.
 *
 * The wrapper also provides a fresh `QueryClientProvider` so that
 * components using TanStack Query hooks (e.g. `useDoiValidation`,
 * `useRorAffiliations`) work out of the box in component tests.
 *
 * Usage: import { render } from '@tests/vitest/utils/render';
 *         (all other exports from @testing-library/react are re-exported)
 */

import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render as rtlRender, type RenderOptions } from '@testing-library/react';
import type { ReactElement } from 'react';
import { useState } from 'react';

import { TooltipProvider } from '@/components/ui/tooltip';

function createTestQueryClient() {
    return new QueryClient({
        defaultOptions: {
            queries: { retry: false, gcTime: 0, staleTime: 0 },
            mutations: { retry: false },
        },
    });
}

function AllProviders({ children }: { children: React.ReactNode }) {
    // Create the QueryClient exactly once per mount. Without the lazy
    // initialiser a re-render (e.g. triggered by state changes in the
    // component under test) would replace the client and wipe its cache,
    // which can cause flaky assertions and unnecessary refetches.
    const [client] = useState(createTestQueryClient);
    return (
        <QueryClientProvider client={client}>
            <TooltipProvider>{children}</TooltipProvider>
        </QueryClientProvider>
    );
}

function render(ui: ReactElement, options?: Omit<RenderOptions, 'wrapper'>) {
    return rtlRender(ui, { wrapper: AllProviders, ...options });
}

// Re-export everything from testing-library, overriding only render
export * from '@testing-library/react';
export { render };
