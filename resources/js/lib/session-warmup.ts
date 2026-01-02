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

let warmupSucceeded = false;
let warmupPromise: Promise<WarmupResponse> | null = null;
let cachedData: unknown = null;

/**
 * Performs a lightweight request to initialize the session and CSRF token.
 * This should be called early in the page lifecycle before any form submissions.
 *
 * The function allows retries after failures - only successful warmups are cached.
 * This handles transient network issues while preventing unnecessary duplicate requests.
 *
 * @returns Promise with success status and fetched data (resource types)
 */
export async function warmupSession<T = unknown>(): Promise<WarmupResponse<T>> {
    // Already successfully warmed up - return cached data
    if (warmupSucceeded && cachedData !== null) {
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
 * Check if the session warmup was successful.
 */
export function isSessionWarmedUp(): boolean {
    return warmupSucceeded;
}

/**
 * Reset the warmup state (useful for testing).
 */
export function resetWarmupState(): void {
    warmupSucceeded = false;
    warmupPromise = null;
    cachedData = null;
}
