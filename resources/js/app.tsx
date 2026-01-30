import '../css/app.css';

import { createInertiaApp } from '@inertiajs/react';
import axios from 'axios';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';

import { initializeTheme } from './hooks/use-appearance';
import { initializeFontSize } from './hooks/use-font-size';
import { buildCsrfHeaders } from './lib/csrf-token';
import { normalizeUrlLike } from './lib/url-normalizer';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

// Inject notification animation styles once at module load.
// This avoids race conditions that could occur if styles were injected
// dynamically when the notification is created.
const notificationStyleId = 'session-refresh-notification-styles';
if (typeof document !== 'undefined' && !document.getElementById(notificationStyleId)) {
    const style = document.createElement('style');
    style.id = notificationStyleId;
    style.textContent = `
        @keyframes session-notification-slide-in {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        @keyframes session-notification-slide-out {
            from { transform: translateX(0); opacity: 1; }
            to { transform: translateX(100%); opacity: 0; }
        }
    `;
    document.head.appendChild(style);
}

// Configure Axios for CSRF token with dynamic token refresh
axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

const applyAxiosCsrfHeaders = () => {
    const headers = buildCsrfHeaders();

    if (!headers['X-CSRF-TOKEN'] && !headers['X-XSRF-TOKEN']) {
        console.error('CSRF token not found: https://laravel.com/docs/csrf#csrf-x-csrf-token');
        return;
    }

    Object.entries(headers).forEach(([key, value]) => {
        axios.defaults.headers.common[key] = value;
    });
};

applyAxiosCsrfHeaders();

// Add axios interceptor to refresh CSRF token on each request
axios.interceptors.request.use(
    function (config) {
        if (typeof config.baseURL === 'string') {
            const normalizedBaseUrl = normalizeUrlLike(config.baseURL);

            if (normalizedBaseUrl !== config.baseURL && import.meta.env.DEV) {
                console.debug('[Axios] Normalized baseURL', {
                    from: config.baseURL,
                    to: normalizedBaseUrl,
                });
            }

            config.baseURL = normalizedBaseUrl;
        }

        if (typeof config.url === 'string') {
            const normalizedUrl = normalizeUrlLike(config.url);

            if (normalizedUrl !== config.url && import.meta.env.DEV) {
                console.debug('[Axios] Normalized url', {
                    from: config.url,
                    to: normalizedUrl,
                });
            }

            config.url = normalizedUrl;
        }

        // Get fresh CSRF token for each request
        const freshHeaders = buildCsrfHeaders();
        config.headers = config.headers ?? {};

        Object.entries(freshHeaders).forEach(([key, value]) => {
            config.headers![key] = value;
        });
        return config;
    },
    function (error) {
        return Promise.reject(error);
    },
);

// Track if we've shown the CSRF refresh notification to avoid spamming
let csrfRefreshNotificationShown = false;

/**
 * Shows a brief notification to the user explaining the session refresh.
 * This improves UX by informing users why the page reloaded.
 * Animation styles are pre-defined at module load to avoid race conditions.
 *
 * The notification is non-blocking (pointer-events: none) to avoid interfering
 * with user interactions, and auto-dismisses after 3 seconds.
 */
function showSessionRefreshNotification(): void {
    if (csrfRefreshNotificationShown) {
        return;
    }

    csrfRefreshNotificationShown = true;

    // Create a toast-like notification
    const notification = document.createElement('div');
    notification.id = 'session-refresh-notification';
    notification.setAttribute('role', 'status');
    notification.setAttribute('aria-live', 'polite');
    notification.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        background: #1f2937;
        color: white;
        padding: 12px 20px;
        border-radius: 8px;
        box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
        z-index: 9999;
        font-family: system-ui, -apple-system, sans-serif;
        font-size: 14px;
        max-width: 280px;
        pointer-events: none;
        animation: session-notification-slide-in 0.3s ease-out;
    `;
    notification.innerHTML = `
        <div style="display: flex; align-items: center; gap: 10px;">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M21 12a9 9 0 1 1-9-9c2.52 0 4.93 1 6.74 2.74L21 8"/>
                <path d="M21 3v5h-5"/>
            </svg>
            <span>Session refreshed</span>
        </div>
    `;

    document.body.appendChild(notification);

    // Auto-remove after 3 seconds (reduced from 5s for less intrusion)
    setTimeout(() => {
        notification.style.animation = 'session-notification-slide-out 0.3s ease-out forwards';
        setTimeout(() => {
            notification.remove();
            csrfRefreshNotificationShown = false;
        }, 300);
    }, 3000);
}

// Track if we're currently refreshing the CSRF token to avoid loops
let isRefreshingCsrf = false;
let failedQueue: Array<{
    resolve: (value: unknown) => void;
    reject: (reason?: unknown) => void;
    config: typeof axios.defaults;
}> = [];

/**
 * Refresh the CSRF token via the Sanctum endpoint.
 * Returns true if successful, false otherwise.
 */
async function refreshCsrfToken(): Promise<boolean> {
    try {
        await axios.get('/sanctum/csrf-cookie', {
            withCredentials: true,
            timeout: 5000,
            // Skip the interceptor for this request to avoid infinite loops
            headers: { 'X-Skip-CSRF-Refresh': 'true' },
        });

        // Update axios headers with new token
        const xsrfCookie = document.cookie
            .split('; ')
            .find((row) => row.startsWith('XSRF-TOKEN='));

        if (xsrfCookie) {
            const token = decodeURIComponent(xsrfCookie.split('=')[1] || '');
            axios.defaults.headers.common['X-XSRF-TOKEN'] = token;
            axios.defaults.headers.common['X-CSRF-TOKEN'] = token;

            // Also update the meta tag
            const metaTag = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]');
            if (metaTag) {
                metaTag.content = token;
            }

            if (import.meta.env.DEV) {
                console.debug('[CSRF] Token refreshed successfully');
            }
            return true;
        }
        return false;
    } catch (error) {
        if (import.meta.env.DEV) {
            console.warn('[CSRF] Failed to refresh token:', error);
        }
        return false;
    }
}

/**
 * Process queued requests after CSRF refresh
 */
function processQueue(success: boolean): void {
    failedQueue.forEach(({ resolve, reject }) => {
        if (success) {
            resolve(true);
        } else {
            reject(new Error('CSRF refresh failed'));
        }
    });
    failedQueue = [];
}

// Add response interceptor to handle CSRF token refresh on 419 errors
axios.interceptors.response.use(
    function (response) {
        return response;
    },
    async function (error) {
        const originalRequest = error.config;

        // Check if this is a 419 error and we haven't already tried to refresh
        if (
            error.response?.status === 419 &&
            !originalRequest._retry &&
            !originalRequest.headers?.['X-Skip-CSRF-Refresh']
        ) {
            // If we're already refreshing, queue this request
            if (isRefreshingCsrf) {
                return new Promise((resolve, reject) => {
                    failedQueue.push({ resolve, reject, config: originalRequest });
                }).then(() => axios(originalRequest));
            }

            originalRequest._retry = true;
            isRefreshingCsrf = true;

            try {
                const refreshed = await refreshCsrfToken();

                if (refreshed) {
                    processQueue(true);
                    isRefreshingCsrf = false;

                    // Retry the original request with updated headers
                    const token =
                        axios.defaults.headers.common['X-XSRF-TOKEN'] ||
                        axios.defaults.headers.common['X-CSRF-TOKEN'];

                    if (token) {
                        originalRequest.headers['X-XSRF-TOKEN'] = token;
                        originalRequest.headers['X-CSRF-TOKEN'] = token;
                    }

                    if (import.meta.env.DEV) {
                        console.debug('[CSRF] Retrying request after token refresh');
                    }

                    return axios(originalRequest);
                }
            } catch {
                processQueue(false);
                isRefreshingCsrf = false;
            }

            // If refresh failed, fall back to page reload
            console.warn('CSRF token refresh failed, reloading page...');

            try {
                sessionStorage.setItem('csrf_refresh_pending', 'true');
            } catch {
                // Intentionally ignored
            }

            window.location.reload();
            return new Promise(() => {});
        }

        return Promise.reject(error);
    },
);

// Check if we just refreshed due to CSRF mismatch and show notification
try {
    if (sessionStorage.getItem('csrf_refresh_pending') === 'true') {
        sessionStorage.removeItem('csrf_refresh_pending');
        // Show notification after DOM is ready
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', showSessionRefreshNotification);
        } else {
            setTimeout(showSessionRefreshNotification, 100);
        }
    }
} catch {
    // Intentionally ignored: sessionStorage may be unavailable in private browsing
    // mode or when storage quota is exceeded. This only affects the notification
    // display, not the core session refresh functionality.
}

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    resolve: (name) => resolvePageComponent(`./pages/${name}.tsx`, import.meta.glob('./pages/**/*.tsx')),
    setup({ el, App, props }) {
        // Initialize font size before rendering
        const fontSizePreference = props.initialPage.props.fontSizePreference as 'regular' | 'large';
        initializeFontSize(fontSizePreference);

        const root = createRoot(el);
        root.render(<App {...props} />);
    },
    progress: {
        color: '#4B5563',
    },
});

// Listen for page events to refresh CSRF token
document.addEventListener('inertia:finish', () => {
    const headers = buildCsrfHeaders();

    if (Object.keys(headers).length === 0) {
        return;
    }

    Object.entries(headers).forEach(([key, value]) => {
        axios.defaults.headers.common[key] = value;
    });
});

document.addEventListener('inertia:error', (event) => {
    const detail = (event as CustomEvent).detail;
    if (detail?.response?.status === 419) {
        console.warn('CSRF token expired, refreshing page...');
        setTimeout(() => {
            window.location.reload();
        }, 100);
    }
});

// This will set light / dark mode on load...
initializeTheme();
