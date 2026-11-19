import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import { playwright } from '@vitest/browser-playwright';
import { defineProject } from 'vitest/config';

import { assertApiHandled, dragBy, mockApi } from './tests/vitest-browser/helpers/commands.ts';
import { testPolicy, testViteConfig } from './vitest.shared.ts';

const sharedViteConfig = testViteConfig();

export default defineProject({
    ...sharedViteConfig,
    // Bind Node and Chromium to the same loopback address on Windows.
    server: { ...sharedViteConfig.server, host: '127.0.0.1' },
    // No Laravel/Wayfinder hooks: source generation completes in the wrapper.
    plugins: [react({ refreshExclude: /./ }), tailwindcss()],
    optimizeDeps: {
        include: [
            'react',
            'react-dom/client',
            'react/jsx-runtime',
            'react/jsx-dev-runtime',
            'vitest-browser-react/pure',
            'cmdk',
            'radix-ui',
            '@tanstack/react-query',
            '@dnd-kit/core',
            '@dnd-kit/sortable',
            '@yaireo/tagify',
        ],
    },
    test: {
        ...testPolicy,
        name: 'browser',
        sequence: { groupOrder: 1 },
        include: ['tests/vitest-browser/**/*.{test,spec}.{js,ts,jsx,tsx}'],
        setupFiles: './tests/vitest-browser/setup.ts',
        browser: {
            enabled: true,
            headless: true,
            provider: playwright({ actionTimeout: 10_000 }),
            instances: [{ browser: 'chromium' }],
            viewport: { width: 1440, height: 1000 },
            // Experiments stay opt-in locally; mandatory runs use interception.
            importMapMocks: false,
            traceView: 'failure-only',
            trace: { mode: 'retain-on-failure', tracesDir: '.vitest/browser/traces' },
            screenshotDirectory: '.vitest/browser/screenshots',
            commands: { mockApi, assertApiHandled, dragBy },
        },
    },
});
