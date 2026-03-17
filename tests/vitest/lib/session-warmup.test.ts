import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { isSessionWarmedUp, resetWarmupState, warmupSession } from '@/lib/session-warmup';

// Mock axios
vi.mock('axios', () => ({
    default: {
        get: vi.fn(),
    },
}));

import axios from 'axios';

describe('session-warmup', () => {
    beforeEach(() => {
        resetWarmupState();
        vi.clearAllMocks();
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('isSessionWarmedUp returns false initially', () => {
        expect(isSessionWarmedUp()).toBe(false);
    });

    it('warmupSession returns success with data on success', async () => {
        const mockData = [{ id: 1, name: 'Dataset' }];
        vi.mocked(axios.get).mockResolvedValueOnce({ data: mockData });

        const result = await warmupSession();
        expect(result.success).toBe(true);
        expect(result.data).toEqual(mockData);
    });

    it('isSessionWarmedUp returns true after successful warmup', async () => {
        vi.mocked(axios.get).mockResolvedValueOnce({ data: [] });
        await warmupSession();
        expect(isSessionWarmedUp()).toBe(true);
    });

    it('returns cached data on subsequent calls', async () => {
        vi.mocked(axios.get).mockResolvedValueOnce({ data: ['cached'] });
        await warmupSession();
        const result = await warmupSession();
        expect(result.success).toBe(true);
        expect(result.data).toEqual(['cached']);
        // Should only have been called once
        expect(axios.get).toHaveBeenCalledTimes(1);
    });

    it('returns failure on network error', async () => {
        vi.mocked(axios.get).mockRejectedValueOnce(new Error('Network Error'));
        const result = await warmupSession();
        expect(result.success).toBe(false);
        expect(result.data).toBeNull();
    });

    it('allows retry after failure', async () => {
        vi.mocked(axios.get).mockRejectedValueOnce(new Error('fail'));
        await warmupSession();

        vi.mocked(axios.get).mockResolvedValueOnce({ data: ['retry'] });
        const result = await warmupSession();
        expect(result.success).toBe(true);
        expect(result.data).toEqual(['retry']);
    });

    it('resetWarmupState clears cache', async () => {
        vi.mocked(axios.get).mockResolvedValueOnce({ data: ['data'] });
        await warmupSession();
        expect(isSessionWarmedUp()).toBe(true);

        resetWarmupState();
        expect(isSessionWarmedUp()).toBe(false);
    });

    it('deduplicates concurrent calls', async () => {
        let resolvePromise: (val: { data: string[] }) => void;
        vi.mocked(axios.get).mockReturnValueOnce(
            new Promise((resolve) => {
                resolvePromise = resolve;
            }) as ReturnType<typeof axios.get>,
        );

        const p1 = warmupSession();
        const p2 = warmupSession();

        resolvePromise!({ data: ['shared'] });

        const [r1, r2] = await Promise.all([p1, p2]);
        expect(r1.data).toEqual(['shared']);
        expect(r2.data).toEqual(['shared']);
        expect(axios.get).toHaveBeenCalledTimes(1);
    });
});
