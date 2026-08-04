import { defineConfig } from 'vitest/config';

export default defineConfig({
    // Report merging replays existing Vitest blobs. Loading the application Vite
    // plugins here would unnecessarily run Laravel and Wayfinder build hooks.
    test: {
        coverage: {
            provider: 'v8',
            reporter: ['text', 'json-summary', 'lcov'],
            reportsDirectory: 'coverage',
        },
    },
});
