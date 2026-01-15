import axios from 'axios';
import { useEffect, useRef } from 'react';

/**
 * Hook that ensures the session and CSRF token are properly initialized.
 *
 * When Docker containers are freshly started or the session expires,
 * the CSRF token might not be synchronized between the cookie and the
 * server. This hook performs a lightweight request on mount to ensure
 * the session is established before any user interactions.
 *
 * This prevents the "Session refresh" / 419 errors that occur when
 * submitting forms before the session is fully synchronized.
 */
export function useSessionWarmup(): void {
    const hasWarmedUp = useRef(false);

    useEffect(() => {
        // Only warm up once per component mount
        if (hasWarmedUp.current) {
            return;
        }

        // Check if we have a valid XSRF token
        const xsrfToken = document.cookie
            .split('; ')
            .find((row) => row.startsWith('XSRF-TOKEN='))
            ?.split('=')[1];

        // If no XSRF token, do a warmup request
        if (!xsrfToken) {
            performWarmup();
        } else {
            // Token exists, but might be stale. Do a quick validation.
            validateSession();
        }

        hasWarmedUp.current = true;
    }, []);
}

/**
 * Perform a lightweight request to initialize the session.
 */
async function performWarmup(): Promise<void> {
    try {
        // First, get the CSRF cookie via sanctum endpoint
        await axios.get('/sanctum/csrf-cookie', {
            withCredentials: true,
            timeout: 5000,
        });

        if (import.meta.env.DEV) {
            console.debug('[Session] CSRF cookie initialized via sanctum');
        }

        // Update axios default headers with new token
        updateAxiosHeaders();
    } catch (error) {
        if (import.meta.env.DEV) {
            console.warn('[Session] Failed to initialize CSRF cookie:', error);
        }
    }
}

/**
 * Validate the existing session with a lightweight request.
 * If it fails with 419, the global error handler will reload the page.
 */
async function validateSession(): Promise<void> {
    try {
        // Use a public endpoint that doesn't require auth
        await axios.get('/api/v1/resource-types/ernie', {
            withCredentials: true,
            timeout: 5000,
        });

        if (import.meta.env.DEV) {
            console.debug('[Session] Session validated successfully');
        }
    } catch (error) {
        // The global 419 handler in app.tsx will handle session expiry
        if (import.meta.env.DEV) {
            console.debug('[Session] Session validation request failed (may trigger refresh):', error);
        }
    }
}

/**
 * Update axios default headers with the current XSRF token from cookies.
 */
function updateAxiosHeaders(): void {
    const xsrfToken = document.cookie
        .split('; ')
        .find((row) => row.startsWith('XSRF-TOKEN='))
        ?.split('=')[1];

    if (xsrfToken) {
        axios.defaults.headers.common['X-XSRF-TOKEN'] = decodeURIComponent(xsrfToken);
    }
}
