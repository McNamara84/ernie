import { expect, type Page, test } from '@playwright/test';

import { loginAsTestUser } from '../helpers/test-helpers';

// All projects share one homepage selection. Run this workflow with --workers=1.
test.describe.configure({ mode: 'serial' });

const berlinInput = (date: Date) =>
    new Intl.DateTimeFormat('sv-SE', {
        timeZone: 'Europe/Berlin',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
    })
        .format(date)
        .replace(' ', 'T');

const savedRow = (page: Page, title: string) => page.getByRole('row').filter({ has: page.getByText(title, { exact: true }) });

async function archive(page: Page, title: string) {
    const row = savedRow(page, title);
    if ((await row.count()) === 0) return;
    await row.getByRole('button', { name: 'Archive', exact: true }).click();
    await page.getByRole('alertdialog').getByRole('button', { name: 'Archive news' }).click();
    await expect(row).toHaveCount(0);
}

for (const [role, email] of [
    ['admin', 'admin@example.com'],
    ['leader', 'groupleader@example.com'],
] as const) {
    test(`manages homepage news end to end as ${role}`, async ({ page, context }, testInfo) => {
        test.skip(testInfo.config.workers !== 1, 'This workflow changes the shared homepage selection; run it with --workers=1.');
        await context.route('https://example.org/news-image.png', (route) =>
            route.fulfill({
                contentType: 'image/png',
                body: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=', 'base64'),
            }),
        );
        await loginAsTestUser(page, email);
        await page.goto('/manage-news');
        await expect(page.getByRole('heading', { level: 1, name: 'Manage News' })).toBeVisible();
        await expect(page.locator('a[data-sidebar="menu-button"][href="/manage-news"]')).toBeVisible();
        const originalId = await page.getByRole('row').filter({ hasText: 'Active regular' }).getAttribute('data-news-id');
        const title = `News ${testInfo.project.name} ${role} ${Date.now()}`;
        const temporaryTitle = `${title} maintenance`;
        const publicPage = await context.newPage();
        try {
            await page.getByLabel('Title', { exact: true }).fill(title);
            await page
                .getByLabel('News text (Markdown)')
                .fill('**Published announcement** with [a link](/about).\n\n![Status](https://example.org/news-image.png)');
            await expect(page.getByLabel('News preview').getByText('Published announcement')).toBeVisible();
            await page.getByRole('button', { name: 'Save draft', exact: true }).click();
            const regular = savedRow(page, title);
            await expect(regular).toContainText('Draft');
            const body = page.getByLabel('News text (Markdown)');
            await body.fill(`${await body.inputValue()}\n\nEdited before activation.`);
            await expect(regular.getByRole('button', { name: 'Set as active' })).toBeDisabled();
            await expect(regular.getByRole('button', { name: 'Archive', exact: true })).toBeDisabled();
            await page.getByRole('button', { name: 'Save changes', exact: true }).click();
            await expect(regular.getByRole('button', { name: 'Set as active' })).toBeEnabled();
            await regular.getByRole('button', { name: 'Set as active' }).click();
            await expect(regular).toContainText('Active regular');
            await publicPage.goto('/');
            await expect(publicPage.getByRole('article', { name: title, exact: true })).toContainText('Published announcement');
            await expect(publicPage.getByRole('article', { name: title, exact: true })).toContainText('Edited before activation.');
            await expect(publicPage.getByRole('img', { name: 'Status' })).toBeVisible();

            await page.getByRole('button', { name: 'New news', exact: true }).click();
            await page.getByLabel('Title', { exact: true }).fill(temporaryTitle);
            await page.getByLabel('Type', { exact: true }).selectOption('temporary');
            await page.getByLabel('News text (Markdown)').fill('Scheduled maintenance.');
            await page.getByLabel('Start (Europe/Berlin)', { exact: true }).fill(berlinInput(new Date(Date.now() - 60000)));
            await page.getByLabel('End (Europe/Berlin)', { exact: true }).fill(berlinInput(new Date(Date.now() + 3600000)));
            await page.getByRole('button', { name: 'Save draft', exact: true }).click();
            const temporary = savedRow(page, temporaryTitle);
            await expect(temporary).toBeVisible();
            await expect(temporary.getByRole('cell').nth(2)).toContainText(/GMT\+0[12]:00/);
            await body.fill('Unsaved maintenance edit.');
            await expect(temporary.getByRole('button', { name: 'Schedule', exact: true })).toBeDisabled();
            page.once('dialog', (dialog) => void dialog.accept());
            await page.getByRole('button', { name: 'Reload latest version', exact: true }).click();
            await expect(body).toHaveValue('Scheduled maintenance.');
            await expect(temporary.getByRole('button', { name: 'Schedule', exact: true })).toBeEnabled();
            await temporary.getByRole('button', { name: 'Schedule', exact: true }).click();
            await expect(temporary).toContainText('Currently displayed');
            await publicPage.reload();
            await expect(publicPage.getByRole('article', { name: temporaryTitle, exact: true })).toBeVisible();
            await body.fill('Saved maintenance update.');
            await expect(temporary.getByRole('button', { name: 'Cancel schedule', exact: true })).toBeDisabled();
            await page.getByRole('button', { name: 'Save changes', exact: true }).click();
            await expect(temporary.getByRole('button', { name: 'Cancel schedule', exact: true })).toBeEnabled();
            await publicPage.reload();
            await expect(publicPage.getByRole('article', { name: temporaryTitle, exact: true })).toContainText('Saved maintenance update.');
            await temporary.getByRole('button', { name: 'Cancel schedule', exact: true }).click();
            await expect(temporary).toContainText('Draft');
            await publicPage.reload();
            await expect(publicPage.getByRole('article', { name: title, exact: true })).toBeVisible();

            await archive(page, temporaryTitle);
            await page.getByLabel('Show archived news').check();
            await savedRow(page, temporaryTitle).getByRole('button', { name: 'Restore', exact: true }).click();
            await expect(savedRow(page, temporaryTitle)).toHaveCount(0);
            await page.getByLabel('Show archived news').uncheck();
            await expect(savedRow(page, temporaryTitle)).toContainText('Draft');
            await page.setViewportSize({ width: 390, height: 844 });
            await expect(page.getByRole('heading', { level: 1, name: 'Manage News' })).toBeVisible();
            expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
            await page.screenshot({ path: testInfo.outputPath('manage-news-mobile.png'), fullPage: true });
        } finally {
            await publicPage.close();
            await page.goto('/manage-news');
            const original = page.locator(`tr[data-news-id="${originalId}"]`);
            const activate = original.getByRole('button', { name: 'Set as active' });
            if (await activate.isEnabled()) {
                await activate.click();
                await expect(original).toContainText('Active regular');
            }
            await archive(page, temporaryTitle);
            await archive(page, title);
        }
    });
}

test('denies curator and beginner access and redirects guests', async ({ page }) => {
    await page.goto('/manage-news');
    await expect(page).toHaveURL(/\/login$/);
    for (const email of ['curator@example.com', 'beginner@example.com']) {
        await page.context().clearCookies();
        await loginAsTestUser(page, email);
        const response = await page.goto('/manage-news');
        expect(response?.status()).toBe(403);
    }
});
