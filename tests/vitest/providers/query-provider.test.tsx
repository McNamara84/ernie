import { QueryClient, useQueryClient } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { QueryProvider } from '@/providers/query-provider';

function ClientProbe() {
    const client = useQueryClient();
    return <div data-testid="probe">{String(client instanceof QueryClient)}</div>;
}

describe('QueryProvider', () => {
    it('renders children', () => {
        render(
            <QueryProvider>
                <span data-testid="child">hello</span>
            </QueryProvider>,
        );

        expect(screen.getByTestId('child')).toHaveTextContent('hello');
    });

    it('exposes a QueryClient to descendants', () => {
        render(
            <QueryProvider>
                <ClientProbe />
            </QueryProvider>,
        );

        expect(screen.getByTestId('probe')).toHaveTextContent('true');
    });

    it('uses the provided client when one is passed in', () => {
        const custom = new QueryClient();
        custom.setQueryData(['marker'], 'from-custom');

        function Reader() {
            const client = useQueryClient();
            return <div data-testid="marker">{String(client.getQueryData(['marker']))}</div>;
        }

        render(
            <QueryProvider client={custom}>
                <Reader />
            </QueryProvider>,
        );

        expect(screen.getByTestId('marker')).toHaveTextContent('from-custom');
    });

    it('creates an internal client when none is provided', () => {
        function Reader() {
            const client = useQueryClient();
            return <div data-testid="ref">{client ? 'has-client' : 'none'}</div>;
        }

        render(
            <QueryProvider>
                <Reader />
            </QueryProvider>,
        );

        expect(screen.getByTestId('ref')).toHaveTextContent('has-client');
    });
});
