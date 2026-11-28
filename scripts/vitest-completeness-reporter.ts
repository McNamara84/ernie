import type { Reporter, TestModule } from 'vitest/node';

import { testInventory } from './test-inventory.mjs';
import { verifyVitestFiles } from './verify-vitest-files.mjs';

/** Only enabled for the final merge, never for a focused developer run. */
export default class CompletenessReporter implements Reporter {
    onTestRunEnd(modules: ReadonlyArray<TestModule>) {
        const counts = verifyVitestFiles(
            testInventory().vitest.configured,
            modules.map((module) => module.relativeModuleId),
        );
        if (!counts.jsdom || !counts.browser) throw new Error('Both jsdom and browser results are required for coverage upload.');
        console.log(`[vitest] Verified complete reports: ${counts.jsdom} jsdom and ${counts.browser} browser files.`);
    }
}
