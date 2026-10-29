import { spawnSync } from 'node:child_process';

import { expect, type Page, type TestInfo } from '@playwright/test';

import { test } from '../fixtures/authenticated';

function trigger(page: Page) {
    return page.getByTestId('settings-accordion').locator('[data-accordion-value="thesauri"] [data-slot="accordion-trigger"]');
}

function fixture(testInfo: TestInfo, action: 'snapshot' | 'open' | 'restore', snapshot?: string): string {
    const phpArgs = ['-d', 'memory_limit=2G', 'tests/playwright/fixtures/thesaurus-review-state.php', action];
    const docker = ['playwright.devstack.config.ts', 'playwright.docker.config.ts'].some((config) => testInfo.config.configFile?.endsWith(config));
    const result = spawnSync(
        docker ? 'docker' : 'php',
        docker ? ['compose', '--env-file', '.env.docker', '-f', 'docker-compose.dev.yml', 'exec', '-T', 'app', 'php', ...phpArgs] : phpArgs,
        { input: snapshot, encoding: 'utf8', timeout: 30_000 },
    );
    if (result.error || result.status !== 0) throw result.error ?? new Error(result.stderr || 'Review fixture failed.');
    return result.stdout;
}

test.describe('Quarterly thesaurus review', () => {
    for (const width of [1280, 375]) {
        test(`opens and focuses the linked card at ${width}px`, async ({ page }) => {
            await page.setViewportSize({ width, height: 812 });
            await page.goto('/settings?section=thesauri');
            const heading = trigger(page);
            await expect(heading).toHaveAttribute('aria-expanded', 'true');
            await expect(heading).toBeFocused();
            await expect(heading).toBeInViewport({ ratio: 1 });
            const headingBox = await heading.boundingBox();
            const saveBarBox = await page.getByTestId('settings-save-status').boundingBox();
            expect(headingBox!.y).toBeGreaterThan(saveBarBox!.y + saveBarBox!.height);
            await expect(page.getByRole('button', { name: 'Save changes' })).toBeDisabled();
            await page.keyboard.press('Enter');
            await expect(heading).toHaveAttribute('aria-expanded', 'false');
            await page.reload();
            await expect(heading).toHaveAttribute('aria-expanded', 'true');
            await expect(heading).toBeFocused();
            await expect(heading).toBeInViewport({ ratio: 1 });
            await page.goto('/settings');
            await expect(heading).toHaveAttribute('aria-expanded', 'false');
        });
    }

    test('persists an explicit shared completion while preserving unsaved editor settings', async ({ page }, testInfo) => {
        test.skip(testInfo.project.name !== 'chromium', 'Change the shared review state once, in Chromium.');
        const hostname = new URL(testInfo.project.use.baseURL!).hostname;
        test.skip(!['localhost', '127.0.0.1'].includes(hostname) && !hostname.endsWith('.localhost'), 'CLI fixtures require the local stack.');
        test.setTimeout(180_000);
        const snapshot = fixture(testInfo, 'snapshot');
        const { period } = JSON.parse(snapshot) as { period: string };
        try {
            fixture(testInfo, 'open', snapshot);
            await page.goto('/dashboard');
            const reminder = page.getByRole('link', { name: /Quarterly thesaurus review/ });
            await expect(reminder).toBeVisible();
            await reminder.focus();
            await page.keyboard.press('Enter');
            await expect(page).toHaveURL(/\/settings\?section=thesauri$/);
            await expect(trigger(page)).toBeFocused();
            await expect(trigger(page)).toHaveAttribute('aria-expanded', 'true');
            await page.goto('/dashboard');
            await expect(reminder).toBeVisible();
            await reminder.click();

            const resourceTypes = page.locator('[data-accordion-value="resource-types"]');
            await resourceTypes.locator('[data-slot="accordion-trigger"]').click();
            const name = resourceTypes.getByRole('textbox', { name: 'Name', exact: true }).first();
            const originalName = await name.inputValue();
            await name.fill(`${originalName} (unsaved review test)`);
            const review = page.getByTestId('thesaurus-review-status');
            await expect(review).toContainText(period);
            const response = page.waitForResponse(
                (result) => result.url().endsWith('/thesauri/review-completion') && result.request().method() === 'POST',
            );
            await review.getByRole('button', { name: "Mark this quarter's review as complete" }).click();
            expect((await response).status()).toBe(200);
            await expect(review.getByRole('status')).toContainText('Review completed for this quarter.');
            await expect(name).toHaveValue(`${originalName} (unsaved review test)`);
            await expect(page.getByRole('button', { name: 'Save changes' })).toBeEnabled();
            await page.reload();
            await expect(review.getByRole('button', { name: 'Review completed' })).toBeDisabled();
            await resourceTypes.locator('[data-slot="accordion-trigger"]').click();
            await expect(name).toHaveValue(originalName);
            await page.goto('/dashboard');
            await expect(reminder).toHaveCount(0);
            await page.reload();
            await expect(reminder).toHaveCount(0);
        } finally {
            fixture(testInfo, 'restore', snapshot);
        }
    });
});
