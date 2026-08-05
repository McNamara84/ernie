import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import { playwright } from '@vitest/browser-playwright';
import { defineConfig } from 'vitest/config';

export default defineConfig(() => {
    return {
        // Keep file-generating Laravel/Wayfinder buildStart hooks out of Vitest's browser environments.
        plugins: [
            react(),
            tailwindcss(),
        ],
        resolve: {
            alias: {
                '@': '/resources/js',
                '@data': '/resources/data',
                '@tests': '/tests',
            },
        },
        test: {
            name: 'browser',
            include: ['tests/vitest-browser/**/*.{test,spec}.{js,ts,jsx,tsx}'],
            browser: {
                enabled: true,
                provider: playwright(),
                instances: [{ browser: 'chromium' }],
            },
            env: {
                VITE_APP_URL: '',
                APP_URL: '',
            },
        },
    };
});
