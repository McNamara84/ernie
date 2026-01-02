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

let sessionWarmedUp = false;
let warmupPromise: Promise<boolean> | null = null;

/**
 * Performs a lightweight request to initialize the session and CSRF token.
 * This should be called early in the page lifecycle before any form submissions.
 *
 * The function is idempotent - subsequent calls return immediately if the
 * session has already been warmed up.
 *
 * @returns Promise<boolean> - true if warmup succeeded, false otherwise
 */
export async function warmupSession(): Promise<boolean> {
    // Already warmed up
    if (sessionWarmedUp) {
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

        sessionWarmedUp = true;

        if (import.meta.env.DEV) {
            console.debug('[Session] Warmup completed successfully');
        }

        return true;
    } catch (error) {
        // Log but don't fail - the session might still work
        if (import.meta.env.DEV) {
            console.warn('[Session] Warmup request failed, session may not be initialized:', error);
        }

        // Still mark as attempted to avoid repeated failures
        sessionWarmedUp = true;
        return false;
    } finally {
        warmupPromise = null;
    }
}

/**
 * Check if the session has been warmed up.
 */
export function isSessionWarmedUp(): boolean {
    return sessionWarmedUp;
}

/**
 * Reset the warmup state (useful for testing).
 */
export function resetWarmupState(): void {
    sessionWarmedUp = false;
    warmupPromise = null;
}
