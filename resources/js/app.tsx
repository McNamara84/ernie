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
 */
function showSessionRefreshNotification(): void {
    if (csrfRefreshNotificationShown) {
        return;
    }

    csrfRefreshNotificationShown = true;

    // Create a toast-like notification
    const notification = document.createElement('div');
    notification.id = 'session-refresh-notification';
    notification.setAttribute('role', 'alert');
    notification.setAttribute('aria-live', 'polite');
    notification.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        background: #1f2937;
        color: white;
        padding: 16px 24px;
        border-radius: 8px;
        box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
        z-index: 9999;
        font-family: system-ui, -apple-system, sans-serif;
        font-size: 14px;
        max-width: 320px;
        animation: slideIn 0.3s ease-out;
    `;
    notification.innerHTML = `
        <div style="display: flex; align-items: center; gap: 12px;">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M21 12a9 9 0 1 1-9-9c2.52 0 4.93 1 6.74 2.74L21 8"/>
                <path d="M21 3v5h-5"/>
            </svg>
            <div>
                <strong style="display: block; margin-bottom: 4px;">Session refreshed</strong>
                <span style="opacity: 0.9;">Please try your action again.</span>
            </div>
        </div>
    `;

    // Add animation keyframes
    const style = document.createElement('style');
    style.textContent = `
        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
    `;
    document.head.appendChild(style);

    document.body.appendChild(notification);

    // Auto-remove after 5 seconds
    setTimeout(() => {
        notification.style.animation = 'slideIn 0.3s ease-out reverse';
        setTimeout(() => {
            notification.remove();
            style.remove();
            csrfRefreshNotificationShown = false;
        }, 300);
    }, 5000);
}

// Add response interceptor to handle CSRF token refresh on 419 errors
axios.interceptors.response.use(
    function (response) {
        return response;
    },
    function (error) {
        if (error.response && error.response.status === 419) {
            console.warn('CSRF token mismatch, attempting to refresh...');

            // Store flag in sessionStorage so we can show notification after reload
            try {
                sessionStorage.setItem('csrf_refresh_pending', 'true');
            } catch {
                // Ignore sessionStorage errors (e.g., private browsing)
            }

            // Force page reload to get new CSRF token
            window.location.reload();
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
    // Ignore sessionStorage errors
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
