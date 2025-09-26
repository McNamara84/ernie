import '../css/app.css';

import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';
import { initializeTheme } from './hooks/use-appearance';
import { setupUrlTransformation } from './url-fix';
import axios from 'axios';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

// Configure Axios for CSRF token with dynamic token refresh
axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

// Function to get fresh CSRF token
function getCSRFToken(): string | null {
    const token = document.head.querySelector('meta[name="csrf-token"]') as HTMLMetaElement;
    return token ? token.content : null;
}

// Set initial CSRF token
const initialToken = getCSRFToken();
if (initialToken) {
    axios.defaults.headers.common['X-CSRF-TOKEN'] = initialToken;
} else {
    console.error('CSRF token not found: https://laravel.com/docs/csrf#csrf-x-csrf-token');
}

// Add axios interceptor to refresh CSRF token on each request
axios.interceptors.request.use(
    function (config) {
        // Get fresh CSRF token for each request
        const freshToken = getCSRFToken();
        if (freshToken) {
            config.headers['X-CSRF-TOKEN'] = freshToken;
        }
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
        const root = createRoot(el);
        root.render(<App {...props} />);
    },
    progress: {
        color: '#4B5563',
    },
});

// Listen for page events to refresh CSRF token
document.addEventListener('inertia:finish', () => {
    // Refresh CSRF token after each page change
    const token = getCSRFToken();
    if (token) {
        axios.defaults.headers.common['X-CSRF-TOKEN'] = token;
    }
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
