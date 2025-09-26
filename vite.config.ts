import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { defineConfig } from 'vitest/config';

export default defineConfig(({ mode }) => {
    // Get base URL from environment for production builds
    const isProduction = mode === 'production';
    const appUrl = process.env.VITE_APP_URL || process.env.APP_URL || 'http://localhost';
    const baseUrl = isProduction && appUrl !== 'http://localhost' ? new URL(appUrl).pathname : undefined;

    return {
        base: baseUrl,
        plugins: [
            laravel({
                input: ['resources/css/app.css', 'resources/js/app.tsx', 'resources/js/swagger.tsx'],
                ssr: 'resources/js/ssr.tsx',
                refresh: true,
            }),
            react(),
            tailwindcss(),
            wayfinder({
                formVariants: true,
            }),
        ],
        build: {
            outDir: 'public/build',
            assetsDir: 'assets',
            rollupOptions: {
                output: {
                    manualChunks: undefined
                }
            }
        },
        esbuild: {
            jsx: 'automatic',
        },
        resolve: {
            alias: {
                '@': '/resources/js',
            },
        },
        test: {
            environment: 'jsdom',
            globals: true,
            setupFiles: './vitest.setup.ts',
            include: ['resources/js/**/*.{test,spec}.{js,ts,jsx,tsx}'],
            coverage: {
                provider: 'v8',
                reporter: ['text', 'json-summary'],
                reportsDirectory: 'coverage',
                include: ['resources/js/**/*.{js,ts,jsx,tsx}'],
                exclude: [
                    'resources/js/**/*.{test,spec}.{js,ts,jsx,tsx}',
                    'resources/js/**/__tests__/**',
                    'resources/js/**/__mocks__/**',
                ],
            },
        },
    };
});
