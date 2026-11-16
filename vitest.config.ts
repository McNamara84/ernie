import { defineConfig } from 'vitest/config';

import { resolveVitestMaxWorkers, testCoverage, testWatchOptions } from './vitest.shared.ts';

const reportDirectory = process.env.ERNIE_VITEST_REPORT_DIR?.trim();

export default defineConfig({
    server: { watch: testWatchOptions },
    test: {
        projects: ['./vitest.jsdom.config.ts', './vitest.browser.config.ts'],
        maxWorkers: resolveVitestMaxWorkers(),
        // Keep warnings from passing tests visible; the minimal reporter hides them.
        reporters: reportDirectory ? ['default', 'json', 'blob'] : ['default'],
        silent: false,
        outputFile: reportDirectory ? { json: `${reportDirectory}/results.json`, blob: `${reportDirectory}/blob.json` } : undefined,
        coverage: testCoverage,
    },
});
