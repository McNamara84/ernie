import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { defineConfig } from 'vitest/config';

export default defineConfig(() => {
    const viteServerPort = parseInt(process.env.VITE_SERVER_PORT ?? '5173');

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
            port: viteServerPort,
            strictPort: true,
            // Configure origin for Docker/Traefik setup
            origin: process.env.VITE_DEV_SERVER_URL || undefined,
            // Allow all hosts in development (required for Docker networking)
            allowedHosts: true,
            hmr: {
                // When running in Docker with Traefik, HMR uses WebSocket through the proxy
                protocol: 'wss',
                host: process.env.VITE_HMR_HOST || 'localhost',
                // IMPORTANT:
                // - `port` is the *Vite server* port (inside Docker / behind Traefik)
                // - `clientPort` is the *public proxy* port the browser connects to
                // If `port` != Vite's server port, Vite starts a separate WS server and HMR breaks.
                port: viteServerPort,
                clientPort: process.env.VITE_HMR_PORT ? parseInt(process.env.VITE_HMR_PORT) : 3333,
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
                    // Test files and mocks
                    'tests/vitest/**',
                    'resources/js/**/__mocks__/**',
                    // Auto-generated Wayfinder files (Laravel route helpers)
                    'resources/js/actions/**',
                    'resources/js/routes/**',
                    // Application entry points (not unit-testable)
                    'resources/js/app.tsx',
                    'resources/js/ssr.tsx',
                    // Type definitions (no executable code)
                    'resources/js/types/**/*.d.ts',
                    'resources/js/**/*.d.ts',
                ],
            },
        },
    };
});
