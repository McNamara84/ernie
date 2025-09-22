import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { defineConfig } from 'vitest/config';

import { laravelPluginOptions, viteBasePath } from './resources/js/config/vite';

export default defineConfig({
    base: viteBasePath,
    plugins: [
        laravel(laravelPluginOptions),
        react(),
        tailwindcss(),
        wayfinder({
            formVariants: true,
        }),
    ],
    esbuild: {
        jsx: 'automatic',
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
});
