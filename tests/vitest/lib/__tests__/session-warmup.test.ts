import axios from 'axios';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { isSessionWarmedUp, resetWarmupState, warmupSession } from '@/lib/session-warmup';

const mocks = vi.hoisted(() => ({
    axiosGet: vi.fn(),
}));

vi.mock('axios', () => ({
    default: {
        get: mocks.axiosGet,
    },
}));

describe('session-warmup', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        resetWarmupState();
        vi.useFakeTimers();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    describe('warmupSession', () => {
        it('makes a request to the resource-types endpoint', async () => {
            mocks.axiosGet.mockResolvedValue({ data: { types: ['dataset'] } });

            await warmupSession();

            expect(mocks.axiosGet).toHaveBeenCalledWith('/api/v1/resource-types/ernie', {
                withCredentials: true,
                timeout: 5000,
            });
        });

        it('returns success with data when request succeeds', async () => {
            const mockData = { types: ['dataset', 'software'] };
            mocks.axiosGet.mockResolvedValue({ data: mockData });

            const result = await warmupSession();

            expect(result).toEqual({
                success: true,
                data: mockData,
            });
        });

        it('returns failure when request fails', async () => {
            mocks.axiosGet.mockRejectedValue(new Error('Network error'));

            const result = await warmupSession();

            expect(result).toEqual({
                success: false,
                data: null,
            });
        });

        it('uses cached data for subsequent calls within TTL', async () => {
            const mockData = { types: ['dataset'] };
            mocks.axiosGet.mockResolvedValue({ data: mockData });

            // First call
            await warmupSession();
            expect(mocks.axiosGet).toHaveBeenCalledTimes(1);

            // Second call - should use cache
            const result = await warmupSession();
            expect(mocks.axiosGet).toHaveBeenCalledTimes(1); // No additional call
            expect(result).toEqual({
                success: true,
                data: mockData,
            });
        });

        it('makes a new request when cache expires (5 minutes TTL)', async () => {
            const mockData1 = { types: ['dataset'] };
            const mockData2 = { types: ['software'] };
            mocks.axiosGet
                .mockResolvedValueOnce({ data: mockData1 })
                .mockResolvedValueOnce({ data: mockData2 });

            // First call
            await warmupSession();
            expect(mocks.axiosGet).toHaveBeenCalledTimes(1);

            // Advance time by 6 minutes (past 5 minute TTL)
            vi.advanceTimersByTime(6 * 60 * 1000);

            // Second call - should make new request
            const result = await warmupSession();
            expect(mocks.axiosGet).toHaveBeenCalledTimes(2);
            expect(result).toEqual({
                success: true,
                data: mockData2,
            });
        });

        it('deduplicates concurrent requests', async () => {
            let resolveRequest: (value: { data: object }) => void;
            const pendingRequest = new Promise<{ data: object }>((resolve) => {
                resolveRequest = resolve;
            });
            mocks.axiosGet.mockReturnValue(pendingRequest);

            // Start multiple concurrent warmup calls
            const promise1 = warmupSession();
            const promise2 = warmupSession();
            const promise3 = warmupSession();

            // Only one actual request should be made
            expect(mocks.axiosGet).toHaveBeenCalledTimes(1);

            // Resolve the request
            resolveRequest!({ data: { types: ['dataset'] } });

            // All promises should resolve with the same data
            const [result1, result2, result3] = await Promise.all([promise1, promise2, promise3]);

            expect(result1).toEqual(result2);
            expect(result2).toEqual(result3);
            expect(result1).toEqual({
                success: true,
                data: { types: ['dataset'] },
            });
        });

        it('allows retry after failure', async () => {
            mocks.axiosGet
                .mockRejectedValueOnce(new Error('Network error'))
                .mockResolvedValueOnce({ data: { types: ['dataset'] } });

            // First call fails
            const result1 = await warmupSession();
            expect(result1.success).toBe(false);
            expect(mocks.axiosGet).toHaveBeenCalledTimes(1);

            // Second call should retry (not use failed cache)
            const result2 = await warmupSession();
            expect(result2.success).toBe(true);
            expect(mocks.axiosGet).toHaveBeenCalledTimes(2);
        });
    });

    describe('isSessionWarmedUp', () => {
        it('returns false before warmup', () => {
            expect(isSessionWarmedUp()).toBe(false);
        });

        it('returns true after successful warmup within TTL', async () => {
            mocks.axiosGet.mockResolvedValue({ data: { types: ['dataset'] } });

            await warmupSession();

            expect(isSessionWarmedUp()).toBe(true);
        });

        it('returns false after failed warmup', async () => {
            mocks.axiosGet.mockRejectedValue(new Error('Network error'));

            await warmupSession();

            expect(isSessionWarmedUp()).toBe(false);
        });

        it('returns false after cache expires', async () => {
            mocks.axiosGet.mockResolvedValue({ data: { types: ['dataset'] } });

            await warmupSession();
            expect(isSessionWarmedUp()).toBe(true);

            // Advance time by 6 minutes (past 5 minute TTL)
            vi.advanceTimersByTime(6 * 60 * 1000);

            expect(isSessionWarmedUp()).toBe(false);
        });
    });

    describe('resetWarmupState', () => {
        it('clears the warmup state', async () => {
            mocks.axiosGet.mockResolvedValue({ data: { types: ['dataset'] } });

            await warmupSession();
            expect(isSessionWarmedUp()).toBe(true);

            resetWarmupState();

            expect(isSessionWarmedUp()).toBe(false);
        });

        it('forces a new request on next warmup call', async () => {
            const mockData1 = { types: ['dataset'] };
            const mockData2 = { types: ['software'] };
            mocks.axiosGet
                .mockResolvedValueOnce({ data: mockData1 })
                .mockResolvedValueOnce({ data: mockData2 });

            // First call
            await warmupSession();
            expect(mocks.axiosGet).toHaveBeenCalledTimes(1);

            // Reset state
            resetWarmupState();

            // Next call should make a new request
            const result = await warmupSession();
            expect(mocks.axiosGet).toHaveBeenCalledTimes(2);
            expect(result).toEqual({
                success: true,
                data: mockData2,
            });
        });
    });
});
