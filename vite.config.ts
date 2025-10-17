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
            sourcemap: false, // Auf 'hidden' setzen für Production-Debugging ohne Offenlegung
            chunkSizeWarningLimit: 1200, // Erhöht auf 1200 kB für Swagger UI
            cssCodeSplit: true, // CSS Code-Splitting für besseres Caching
            minify: 'esbuild', // esbuild ist schneller als terser
            target: 'es2020', // Moderne Browser für kleinere Bundles
            rollupOptions: {
                output: {
                    // Optimierte Chunk-Namen für besseres Caching
                    chunkFileNames: 'assets/[name]-[hash].js',
                    entryFileNames: 'assets/[name]-[hash].js',
                    assetFileNames: 'assets/[name]-[hash].[ext]',
                    manualChunks: (id) => {
                        // Swagger UI in separaten Chunk (wird nur auf API-Docs-Seite geladen)
                        if (id.includes('swagger-ui-react')) {
                            return 'swagger-ui';
                        }
                        // Framer Motion für Animationen (hauptsächlich Changelog)
                        if (id.includes('framer-motion')) {
                            return 'framer-motion';
                        }
                        // Große UI-Bibliotheken (Radix UI Komponenten)
                        if (id.includes('@radix-ui')) {
                            return 'radix-ui';
                        }
                        // React Core Libraries separat für besseres Caching
                        if (id.includes('node_modules')) {
                            if (id.includes('react-dom')) {
                                return 'react-dom';
                            }
                            if (id.includes('react') && !id.includes('react-dom')) {
                                return 'react';
                            }
                            // Lucide Icons separat (viele Icons)
                            if (id.includes('lucide-react')) {
                                return 'lucide';
                            }
                            // Axios separat für HTTP-Requests
                            if (id.includes('axios')) {
                                return 'axios';
                            }
                            // Inertia.js separat
                            if (id.includes('@inertiajs')) {
                                return 'inertia';
                            }
                        }
                    }
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
            include: [
                'react',
                'react-dom',
                'swagger-ui-react',
                'papaparse',
                '@inertiajs/react',
                'axios',
            ],
            // Exkludiere große Bibliotheken, die bereits optimiert sind
            exclude: ['@ffmpeg/ffmpeg', '@ffmpeg/util'],
        },
        server: {
            warmup: {
                clientFiles: [
                    'resources/js/app.tsx',
                    'resources/js/pages/dashboard.tsx',
                    'resources/js/swagger.tsx',
                ],
            },
            // CORS für lokale Entwicklung
            cors: true,
            // HMR Konfiguration
            hmr: {
                overlay: true,
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
