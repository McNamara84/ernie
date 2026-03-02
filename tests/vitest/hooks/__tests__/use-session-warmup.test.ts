import '@testing-library/jest-dom/vitest';

import { renderHook, waitFor } from '@testing-library/react';
import axios from 'axios';
import { beforeEach, describe, expect, it, type Mock, vi } from 'vitest';

vi.mock('axios', () => ({
    default: {
        get: vi.fn(),
        defaults: {
            headers: {
                common: {} as Record<string, string>,
            },
        },
    },
}));

vi.mock('@/lib/csrf-token', () => ({
    syncXsrfTokenToAxios: vi.fn(),
}));

import { syncXsrfTokenToAxios } from '@/lib/csrf-token';
import { useSessionWarmup } from '@/hooks/use-session-warmup';

describe('useSessionWarmup', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        (axios.get as Mock).mockResolvedValue({ data: {} });
    });

    it('calls sanctum csrf-cookie endpoint on mount', async () => {
        renderHook(() => useSessionWarmup());

        await waitFor(() => {
            expect(axios.get).toHaveBeenCalledWith('/sanctum/csrf-cookie', {
                withCredentials: true,
                timeout: 5000,
            });
        });
    });

    it('syncs CSRF token after successful fetch', async () => {
        renderHook(() => useSessionWarmup());

        await waitFor(() => {
            expect(syncXsrfTokenToAxios).toHaveBeenCalledWith(
                axios.defaults.headers.common,
            );
        });
    });

    it('only warms up once even on re-render', async () => {
        const { rerender } = renderHook(() => useSessionWarmup());

        await waitFor(() => {
            expect(axios.get).toHaveBeenCalledTimes(1);
        });

        rerender();

        // Should still only have been called once
        expect(axios.get).toHaveBeenCalledTimes(1);
    });

    it('does not throw when csrf-cookie request fails', async () => {
        (axios.get as Mock).mockRejectedValue(new Error('Network error'));

        expect(() => {
            renderHook(() => useSessionWarmup());
        }).not.toThrow();
    });

    it('does not sync token when request fails', async () => {
        (axios.get as Mock).mockRejectedValue(new Error('Network error'));

        renderHook(() => useSessionWarmup());

        // Wait a tick for the async operation to settle
        await waitFor(() => {
            expect(axios.get).toHaveBeenCalledTimes(1);
        });

        expect(syncXsrfTokenToAxios).not.toHaveBeenCalled();
    });
});
