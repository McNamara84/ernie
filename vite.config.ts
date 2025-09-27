import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { defineConfig } from 'vitest/config';

export default defineConfig(() => {
    return {
        plugins: [
            laravel({
                input: ['resources/css/app.css', 'resources/js/app.tsx', 'resources/js/swagger.tsx', 'resources/js/pages/dashboard.tsx'],
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
            jsx: 'automatic' as const,
        },
        define: {
            global: 'globalThis',
        },
        resolve: {
            alias: {
                '@': '/resources/js',
            },
        },
        optimizeDeps: {
            exclude: ['swagger-ui-react'],
            include: ['react', 'react-dom'],
        },
        test: {
            environment: 'jsdom',
            globals: true,
            setupFiles: './vitest.setup.ts',
            include: ['resources/js/**/*.{test,spec}.{js,ts,jsx,tsx}'],
            env: {
                VITE_APP_URL: '',
                APP_URL: '',
            },
            coverage: {
                provider: 'v8' as const,
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
