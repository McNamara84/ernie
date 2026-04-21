import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, renderHook, type RenderHookOptions, type RenderOptions } from '@testing-library/react';
import type { ReactElement, ReactNode } from 'react';

/**
 * Build a {@link QueryClient} configured for unit tests.
 *
 * - `retry: false` prevents deterministic assertions from being slowed down
 *   by retry backoffs.
 * - `gcTime: 0` avoids cross-test pollution by discarding unused queries
 *   immediately.
 */
export function createTestQueryClient(): QueryClient {
    return new QueryClient({
        defaultOptions: {
            queries: { retry: false, gcTime: 0, staleTime: 0 },
            mutations: { retry: false },
        },
    });
}

interface TestProvidersProps {
    children: ReactNode;
    client?: QueryClient;
}

function TestProviders({ children, client }: TestProvidersProps) {
    return <QueryClientProvider client={client ?? createTestQueryClient()}>{children}</QueryClientProvider>;
}

/**
 * Render a component wrapped in a `QueryClientProvider`.
 *
 * @returns The render result plus the `QueryClient` instance so callers can
 *          inspect or manipulate the cache.
 */
export function renderWithQueryClient(
    ui: ReactElement,
    options: RenderOptions & { client?: QueryClient } = {},
) {
    const { client = createTestQueryClient(), wrapper, ...rest } = options;

    if (wrapper) {
        throw new Error('renderWithQueryClient does not support a custom wrapper.');
    }

    const result = render(ui, {
        ...rest,
        wrapper: ({ children }) => <TestProviders client={client}>{children}</TestProviders>,
    });

    return { ...result, client };
}

/**
 * Render a hook wrapped in a `QueryClientProvider`.
 *
 * Uses `@testing-library/react` so that the rendered hook is subject to the
 * same rerender/cleanup mechanics as full component tests.
 */
export function renderHookWithQueryClient<TProps, TResult>(
    callback: (props: TProps) => TResult,
    options: RenderHookOptions<TProps> & { client?: QueryClient } = {},
) {
    const { client = createTestQueryClient(), wrapper, ...rest } = options;

    if (wrapper) {
        throw new Error('renderHookWithQueryClient does not support a custom wrapper.');
    }

    const result = renderHook(callback, {
        ...rest,
        wrapper: ({ children }) => <TestProviders client={client}>{children}</TestProviders>,
    });

    return { ...result, client };
}
