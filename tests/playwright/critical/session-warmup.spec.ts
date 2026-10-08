import { expect, test } from '@playwright/test';

import { warmUpSession } from '../helpers/session-readiness';
import { loginAsTestUser } from '../helpers/test-helpers';

test('UI login and explicit session warmup work when automatic CSRF refreshes abort', async ({ page }) => {
    let abortedRefreshes = 0;
    await page.route('**/sanctum/csrf-cookie', async (route) => {
        abortedRefreshes++;
        await route.abort('timedout');
    });

    await loginAsTestUser(page);
    await expect(page).toHaveURL(/\/dashboard/);
    await expect(page.getByTestId('unified-dropzone')).toBeVisible();
    expect(abortedRefreshes).toBeGreaterThan(0);

    // This real request uses the shared authenticated cookie jar; page.route
    // only aborts the application's optional browser-side requests.
    await warmUpSession(page);
    const response = await page.request.get('/api/changelog', { headers: { Accept: 'application/json' } });
    try {
        expect(response.status()).toBe(200);
    } finally {
        await response.dispose();
    }
});
