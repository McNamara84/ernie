import { test as base } from '@playwright/test';

import { createPlaywrightTestIp, playwrightClientHeaders } from '../../../scripts/playwright-test-routing.mjs';

export const test = base.extend<{ clientIdentity: void }>({
    clientIdentity: [
        async ({ context }, provide, testInfo) => {
            const ip = createPlaywrightTestIp();
            await context.setExtraHTTPHeaders(playwrightClientHeaders(testInfo.project.use.extraHTTPHeaders, ip));
            testInfo.annotations.push({ type: 'test-client-ip', description: ip });
            await provide();
        },
        { auto: true },
    ],
});
