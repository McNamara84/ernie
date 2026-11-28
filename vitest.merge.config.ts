import { defineConfig } from 'vitest/config';

import CompletenessReporter from './scripts/vitest-completeness-reporter.ts';
import { testCoverage } from './vitest.shared.ts';

export default defineConfig({
    // Report merging replays existing Vitest blobs. Loading the application Vite
    // plugins here would unnecessarily run Laravel and Wayfinder build hooks.
    test: {
        reporters: ['default', new CompletenessReporter()],
        coverage: testCoverage,
    },
});
