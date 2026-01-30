import axios from 'axios';
import { useEffect, useRef } from 'react';

import { getXsrfTokenFromCookie } from '@/lib/csrf-token';

/**
 * Hook that ensures the session and CSRF token are properly initialized.
 *
 * The 419 CSRF error typically occurs when:
 * 1. Docker containers are freshly started (empty session database)
 * 2. The session has expired
 * 3. The CSRF token cookie and meta tag are out of sync
 *
 * This hook ensures proper CSRF token initialization by:
 * 1. Always calling the Sanctum CSRF endpoint on first mount
 * 2. Updating both the cookie and axios headers
 * 3. Syncing the meta tag with the new token
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
        hasWarmedUp.current = true;

        // Always initialize the CSRF token properly via Sanctum endpoint
        // This ensures the cookie and session are in sync
        initializeCsrfToken();
    }, []);
}

/**
 * Initialize CSRF token via the Sanctum endpoint.
 * This is the most reliable way to ensure CSRF token synchronization.
 */
async function initializeCsrfToken(): Promise<void> {
    try {
        // The Sanctum CSRF endpoint sets the XSRF-TOKEN cookie
        // It's specifically designed for SPA CSRF initialization
        await axios.get('/sanctum/csrf-cookie', {
            withCredentials: true,
            timeout: 5000,
        });

        // After the cookie is set, update axios headers using shared helper
        updateAxiosHeaders();

        // Also update the meta tag to ensure consistency
        updateMetaTag();

        if (import.meta.env.DEV) {
            console.debug('[Session] CSRF token initialized successfully');
        }
    } catch (error) {
        if (import.meta.env.DEV) {
            console.warn('[Session] Failed to initialize CSRF token:', error);
        }
        // Even if this fails, the user can still try - the 419 handler will reload
    }
}

/**
 * Update axios default headers with the current XSRF token from cookies.
 * Uses the shared helper to correctly handle base64 values with padding.
 */
function updateAxiosHeaders(): void {
    const token = getXsrfTokenFromCookie();

    if (token) {
        axios.defaults.headers.common['X-XSRF-TOKEN'] = token;
        axios.defaults.headers.common['X-CSRF-TOKEN'] = token;
    }
}

/**
 * Update the CSRF meta tag to match the cookie token.
 * This ensures forms that read from the meta tag use the correct token.
 */
function updateMetaTag(): void {
    const token = getXsrfTokenFromCookie();
    if (!token) return;

    const metaTag = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]');
    if (metaTag) {
        metaTag.content = token;
    }
}
