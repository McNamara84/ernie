/**
 * Session Warmup Utility
 *
 * When Docker containers are freshly started, the session database is empty.
 * The first request creates the session and CSRF token, but if the user
 * submits a form before the cookie is fully synchronized, a 419 CSRF mismatch
 * occurs. This utility provides a "warmup" mechanism to initialize the session
 * before user interactions.
 *
 * The warmup request fetches resource types, which can be reused by the caller
 * to avoid duplicate network requests.
 */

import axios from 'axios';

/**
 * Result of a successful session warmup, containing the fetched data.
 */
export interface WarmupResult<T = unknown> {
    success: true;
    data: T;
}

/**
 * Result of a failed session warmup.
 */
export interface WarmupFailure {
    success: false;
    data: null;
}

export type WarmupResponse<T = unknown> = WarmupResult<T> | WarmupFailure;

/**
 * Cache validity duration in milliseconds.
 * After this time, the cached warmup result is considered stale and will be refreshed.
 * 5 minutes provides a good balance between avoiding duplicate requests and ensuring
 * the session state remains fresh during longer browsing sessions.
 */
const CACHE_TTL_MS = 5 * 60 * 1000;

let warmupSucceeded = false;
let warmupPromise: Promise<WarmupResponse> | null = null;
let cachedData: unknown = null;
let cacheTimestamp: number | null = null;

/**
 * Check if the cached warmup data is still valid based on TTL.
 */
function isCacheValid(): boolean {
    if (!warmupSucceeded || cachedData === null || cacheTimestamp === null) {
        return false;
    }
    return Date.now() - cacheTimestamp < CACHE_TTL_MS;
}

/**
 * Performs a lightweight request to initialize the session and CSRF token.
 * This should be called early in the page lifecycle before any form submissions.
 *
 * The function uses time-based cache invalidation (5 minute TTL) to ensure
 * session state remains fresh during longer browsing sessions while avoiding
 * unnecessary duplicate requests during normal navigation.
 *
 * @returns Promise with success status and fetched data (resource types)
 */
export async function warmupSession<T = unknown>(): Promise<WarmupResponse<T>> {
    // Return cached data if still valid (within TTL)
    if (isCacheValid()) {
        return { success: true, data: cachedData as T };
    }

    // Warmup in progress - return existing promise to avoid duplicate requests
    if (warmupPromise) {
        return warmupPromise as Promise<WarmupResponse<T>>;
    }

    warmupPromise = performWarmup<T>();
    return warmupPromise as Promise<WarmupResponse<T>>;
}

async function performWarmup<T>(): Promise<WarmupResponse<T>> {
    try {
        // Use a lightweight endpoint that doesn't require authentication
        // The /api/v1/resource-types/ernie endpoint is public and fast
        const response = await axios.get<T>('/api/v1/resource-types/ernie', {
            // Ensure cookies are sent and received
            withCredentials: true,
            // Short timeout since this is just for session init
            timeout: 5000,
        });

        warmupSucceeded = true;
        cachedData = response.data;
        cacheTimestamp = Date.now();

        if (import.meta.env.DEV) {
            console.debug('[Session] Warmup completed successfully');
        }

        return { success: true, data: response.data };
    } catch (error) {
        // Log but don't fail - the session might still work
        if (import.meta.env.DEV) {
            console.warn('[Session] Warmup request failed, session may not be initialized:', error);
        }

        // Return failure but allow retry on next call.
        // This handles transient network issues while preventing infinite loops.
        return { success: false, data: null };
    } finally {
        warmupPromise = null;
    }
}

/**
 * Check if the session warmup was successful and cache is still valid.
 */
export function isSessionWarmedUp(): boolean {
    return isCacheValid();
}

/**
 * Reset the warmup state. Called automatically when cache expires,
 * but can also be called manually (e.g., for testing or after logout).
 */
export function resetWarmupState(): void {
    warmupSucceeded = false;
    warmupPromise = null;
    cachedData = null;
    cacheTimestamp = null;
}
