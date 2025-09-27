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
            // Wayfinder plugin removed for Docker build
        ],
        build: {
            outDir: 'public/build',
            emptyOutDir: true,
            rollupOptions: {
                input: [
                    'resources/css/app.css', 
                    'resources/js/app.tsx',
                    'resources/js/swagger.tsx', 
                    'resources/js/pages/dashboard.tsx'
                ],
            },
        },
    };
});