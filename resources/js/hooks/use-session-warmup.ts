import axios from 'axios';
import { useEffect, useRef } from 'react';

import { syncCsrfTokenToAxiosAndMeta } from '@/lib/csrf-token';

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

        // After the cookie is set, sync to axios headers and meta tag
        // using the shared helper for consistency with 419 refresh handling
        syncCsrfTokenToAxiosAndMeta(axios.defaults.headers.common);

        if (import.meta.env.DEV) {
            console.debug('[Session] CSRF token initialized successfully');
        }
    } catch (error) {
        if (import.meta.env.DEV) {
            console.warn('[Session] Failed to initialize CSRF token:', error);
        }
        // Even if this fails, the user can still try - the 419 handler in app.tsx
        // will attempt to refresh the token and retry the request automatically
    }
}
