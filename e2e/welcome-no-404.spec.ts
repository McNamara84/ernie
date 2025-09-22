import { expect, test } from '@playwright/test';

// Ensures the welcome page works when hosted behind a sub-path and that all
// console messages stay free of 404 errors, preventing regressions when the
// asset build directory changes.
test('welcome page behind sub-path loads without 404s', async ({ page }) => {
    const consoleMessages: string[] = [];

    page.on('console', (message) => {
        const text = message.text();
        if (text.includes('404')) {
            consoleMessages.push(text);
        }
    });

    const response = await page.goto('/ernie', { waitUntil: 'networkidle' });
    expect(response?.status() ?? 0).toBeLessThan(400);

    await expect(page.locator('#app')).toHaveAttribute('data-page', /"component":"welcome"/);

    expect(consoleMessages).toEqual([]);
});
