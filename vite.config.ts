import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { defineConfig } from 'vitest/config';

export const computeBasePath = (assetUrl?: string): string => {
    if (!assetUrl) {
        return '/build/';
    }

    const normalizedUrl = assetUrl.endsWith('/') ? assetUrl.slice(0, -1) : assetUrl;

    return `${normalizedUrl}/build/`;
};

export default defineConfig(({ command }) => ({
    ...(command === 'build'
        ? {
              base: computeBasePath(process.env.ASSET_URL),
          }
        : {}),
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
}));
