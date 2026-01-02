/**
 * Session Warmup Utility
 *
 * When Docker containers are freshly started, the session database is empty.
 * The first request creates the session and CSRF token, but if the user
 * submits a form before the cookie is fully synchronized, a 419 CSRF mismatch
 * occurs. This utility provides a "warmup" mechanism to initialize the session
 * before user interactions.
 */

import axios from 'axios';

let warmupSucceeded = false;
let warmupAttempted = false;
let warmupPromise: Promise<boolean> | null = null;

/**
 * Performs a lightweight request to initialize the session and CSRF token.
 * This should be called early in the page lifecycle before any form submissions.
 *
 * The function allows retries after failures - only successful warmups are cached.
 * This handles transient network issues while preventing unnecessary duplicate requests.
 *
 * @returns Promise<boolean> - true if warmup succeeded, false otherwise
 */
export async function warmupSession(): Promise<boolean> {
    // Already successfully warmed up - no need to retry
    if (warmupSucceeded) {
        return true;
    }

    // Warmup in progress - return existing promise to avoid duplicate requests
    if (warmupPromise) {
        return warmupPromise;
    }

    warmupPromise = performWarmup();
    return warmupPromise;
}

async function performWarmup(): Promise<boolean> {
    try {
        // Use a lightweight endpoint that doesn't require authentication
        // The /api/v1/resource-types/ernie endpoint is public and fast
        await axios.get('/api/v1/resource-types/ernie', {
            // Ensure cookies are sent and received
            withCredentials: true,
            // Short timeout since this is just for session init
            timeout: 5000,
        });

        warmupSucceeded = true;
        warmupAttempted = true;

        if (import.meta.env.DEV) {
            console.debug('[Session] Warmup completed successfully');
        }

        return true;
    } catch (error) {
        // Log but don't fail - the session might still work
        if (import.meta.env.DEV) {
            console.warn('[Session] Warmup request failed, session may not be initialized:', error);
        }

        // Mark as attempted but NOT succeeded, allowing retry on next call
        // This handles transient network issues while preventing infinite loops
        warmupAttempted = true;
        return false;
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
 * Check if a warmup was attempted (regardless of success).
 */
export function wasWarmupAttempted(): boolean {
    return warmupAttempted;
}

/**
 * Reset the warmup state (useful for testing).
 */
export function resetWarmupState(): void {
    warmupSucceeded = false;
    warmupAttempted = false;
    warmupPromise = null;
}
