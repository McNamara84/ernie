import { defineProject } from 'vitest/config';

import { testPolicy, testViteConfig } from './vitest.shared.ts';

export default defineProject({
    ...testViteConfig(),
    test: {
        ...testPolicy,
        name: 'jsdom',
        environment: 'jsdom',
        environmentOptions: { jsdom: { url: 'http://localhost/' } },
        pool: process.env.CI ? 'forks' : 'threads',
        sequence: { groupOrder: 0 },
        globals: true,
        css: false,
        setupFiles: './vitest.setup.ts',
        include: ['tests/vitest/**/*.{test,spec}.{js,ts,jsx,tsx}'],
    },
});
