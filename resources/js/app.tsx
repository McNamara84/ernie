import '../css/app.css';

import { createInertiaApp } from '@inertiajs/react';
import axios from 'axios';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';

import { initializeTheme } from './hooks/use-appearance';
import { initializeFontSize } from './hooks/use-font-size';
import { buildCsrfHeaders } from './lib/csrf-token';
import { setupUrlTransformation } from './url-fix';

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
    }
);

// Add response interceptor to handle CSRF token refresh on 419 errors
axios.interceptors.response.use(
    function (response) {
        return response;
    },
    function (error) {
        if (error.response && error.response.status === 419) {
            console.warn('CSRF token mismatch, attempting to refresh...');
            // Force page reload to get new CSRF token
            window.location.reload();
        }
        return Promise.reject(error);
    }
);

// Setup URL transformation for production
setupUrlTransformation();

createInertiaApp({
    title: (title) => title ? `${title} - ${appName}` : appName,
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
    onError: (errors) => {
        console.error('[Inertia] Error:', errors);
        
        // Check if it's a 419 CSRF error
        if (typeof errors === 'object' && errors !== null) {
            const errorObj = errors as any;
            if (errorObj.response?.status === 419 || errorObj.status === 419) {
                console.warn('[Inertia] CSRF token expired (419), reloading page...');
                setTimeout(() => {
                    window.location.reload();
                }, 100);
            }
        }
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
