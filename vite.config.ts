import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { defineConfig } from 'vite';

const releases = JSON.parse(readFileSync(resolve(process.cwd(), 'resources/data/changelog.json'), 'utf8')) as unknown;

if (!Array.isArray(releases) || typeof releases[0]?.version !== 'string' || releases[0].version.length === 0) {
    throw new Error('The changelog must start with a release version.');
}

const latestVersion: string = releases[0].version;

export default defineConfig(({ command }) => {
    const viteServerPort = parseInt(process.env.VITE_SERVER_PORT ?? '5173');
    const isDev = command === 'serve';
    const wayfinderCommand = process.env.WAYFINDER_COMMAND ?? 'php artisan ernie:wayfinder-generate';

    return {
        devtools: isDev,
        plugins: [
            laravel({
                input: ['resources/css/app.css', 'resources/js/app.tsx', 'resources/js/swagger.tsx', 'resources/js/pages/dashboard.tsx'],
                ssr: 'resources/js/ssr.tsx',
                refresh: true,
            }),
            react(),
            tailwindcss(),
            wayfinder({
                command: wayfinderCommand,
                formVariants: true,
            }),
        ],
        build: {
            outDir: 'public/build',
            assetsDir: 'assets',
            sourcemap: false,
        },
        define: {
            global: 'globalThis',
            __ERNIE_VERSION__: JSON.stringify(latestVersion),
        },
        resolve: {
            alias: {
                '@': '/resources/js',
                '@data': '/resources/data',
                '@tests': '/tests',
                '@test-helpers': '/tests/vitest/test-helpers',
                '@assistants': '/modules/assistants',
            },
        },
        optimizeDeps: {
            include: ['react', 'react-dom', 'swagger-ui-react'],
        },
        server: {
            forwardConsole: true,
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
                // Runtime data, dependencies and generated reports are not HMR sources.
                // Polling them across the Windows bind mount can stall CSS generation.
                ignored: [/(?:^|[/\\])(?:\.tmp|\.trivy-cache|vendor|storage|coverage|test-results|playwright-report|blob-report)(?:[/\\]|$)/],
            },
        },
    };
});
