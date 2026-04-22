import { QueryClient } from '@tanstack/react-query';
import { describe, expect, it } from 'vitest';

import { createQueryClient, defaultQueryClientOptions } from '@/lib/query-client';

describe('createQueryClient', () => {
    it('returns a QueryClient instance', () => {
        const client = createQueryClient();
        expect(client).toBeInstanceOf(QueryClient);
    });

    it('applies the expected default query options', () => {
        const client = createQueryClient();
        const defaults = client.getDefaultOptions();

        expect(defaults.queries?.staleTime).toBe(60_000);
        expect(defaults.queries?.gcTime).toBe(5 * 60_000);
        expect(defaults.queries?.retry).toBe(1);
        expect(defaults.queries?.refetchOnWindowFocus).toBe(false);
    });

    it('applies the expected default mutation options', () => {
        const client = createQueryClient();
        const defaults = client.getDefaultOptions();

        expect(defaults.mutations?.retry).toBe(0);
    });

    it('returns a fresh instance on every call', () => {
        const a = createQueryClient();
        const b = createQueryClient();

        expect(a).not.toBe(b);
    });
});

describe('defaultQueryClientOptions', () => {
    it('exposes the shared default options object', () => {
        expect(defaultQueryClientOptions.defaultOptions.queries.staleTime).toBe(60_000);
        expect(defaultQueryClientOptions.defaultOptions.queries.gcTime).toBe(5 * 60_000);
        expect(defaultQueryClientOptions.defaultOptions.queries.retry).toBe(1);
        expect(defaultQueryClientOptions.defaultOptions.queries.refetchOnWindowFocus).toBe(false);
        expect(defaultQueryClientOptions.defaultOptions.mutations.retry).toBe(0);
    });
});
