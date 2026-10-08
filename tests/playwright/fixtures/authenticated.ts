import { type BrowserContext, test as base } from '@playwright/test';

import { warmUpSession } from '../helpers/session-readiness';
import { loginAsTestUser } from '../helpers/test-helpers';

type StorageState = Awaited<ReturnType<BrowserContext['storageState']>>;

// Use only for scenarios whose purpose is already-authenticated behavior.
// Authentication/session scenarios continue to use Playwright's base fixture.
export const test = base.extend<object, { authenticatedState: StorageState }>({
    authenticatedState: [
        async ({ browser }, provide, workerInfo) => {
            const options = workerInfo.project.use;
            const context = await browser.newContext({
                baseURL: options.baseURL,
                ignoreHTTPSErrors: options.ignoreHTTPSErrors,
                extraHTTPHeaders: options.extraHTTPHeaders,
                viewport: options.viewport,
                userAgent: options.userAgent,
                locale: options.locale,
                timezoneId: options.timezoneId,
            });
            let state: StorageState;
            try {
                const page = await context.newPage();
                await loginAsTestUser(page);
                await warmUpSession(page);
                state = await context.storageState();
            } finally {
                await context.close();
            }
            // Keep credentials/cookies in memory; every test still receives its
            // own browser context, DOM, and copy of this worker's login state.
            await provide(state);
        },
        { scope: 'worker' },
    ],
    storageState: async ({ authenticatedState }, provide) => {
        await provide(authenticatedState);
    },
});
