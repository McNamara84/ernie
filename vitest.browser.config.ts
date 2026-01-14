import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { defineConfig } from 'vitest/config';

export default defineConfig(() => {
    return {
        plugins: [
            laravel({
                input: ['resources/css/app.css', 'resources/js/app.tsx'],
                refresh: true,
            }),
            react(),
            tailwindcss(),
            wayfinder({
                formVariants: true,
            }),
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
                provider: 'playwright',
                instances: [{ browser: 'chromium' }],
            },
            env: {
                VITE_APP_URL: '',
                APP_URL: '',
            },
        },
    };
});
