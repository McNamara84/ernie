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
            sourcemap: false,
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
                '@data': '/resources/data',
                '@tests': '/tests',
            },
        },
        optimizeDeps: {
            include: ['react', 'react-dom', 'swagger-ui-react'],
        },
        server: {
            warmup: {
                clientFiles: ['resources/js/swagger.tsx'],
            },
            // Docker development configuration
            host: '0.0.0.0',
            port: 5173,
            strictPort: true,
            // Configure origin for Docker/Traefik setup
            origin: process.env.VITE_DEV_SERVER_URL || undefined,
            // Allow all hosts in development (required for Docker networking)
            allowedHosts: true,
            hmr: {
                // When running in Docker with Traefik, HMR uses WebSocket through the proxy
                protocol: 'wss',
                host: process.env.VITE_HMR_HOST || 'localhost',
                // IMPORTANT: Do not override the server-side HMR port.
                // Vite listens on `server.port` (5173) inside the container.
                // Only the browser-facing port must be the Traefik port (3333).
                clientPort: process.env.VITE_HMR_PORT ? parseInt(process.env.VITE_HMR_PORT) : 3333,
                path: process.env.VITE_HMR_PATH || '/hmr',
            },
            cors: true,
            watch: {
                // Use polling for file changes in Docker volumes (Windows compatibility)
                usePolling: process.env.VITE_USE_POLLING === 'true',
            },
        },
        test: {
            environment: 'jsdom',
            globals: true,
            setupFiles: './vitest.setup.ts',
            include: ['tests/vitest/**/*.{test,spec}.{js,ts,jsx,tsx}'],
            testTimeout: 10000, // Increased from default 5000ms for controlled vocabulary (GCMD) loading in DataCiteForm initialization
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
                    'tests/vitest/**',
                    'resources/js/**/__mocks__/**',
                ],
            },
        },
    };
});
