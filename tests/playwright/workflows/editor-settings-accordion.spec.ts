import { expect, type Locator, type Page } from '@playwright/test';

import { test } from '../fixtures/authenticated';

function card(page: Page, value: string): Locator {
    return page.getByTestId('settings-accordion').locator(`[data-accordion-value="${value}"]`);
}

function trigger(page: Page, value: string): Locator {
    return card(page, value).locator('[data-slot="accordion-trigger"]');
}

async function expectAllCards(page: Page, expanded: boolean) {
    const accordion = page.getByTestId('settings-accordion');
    await expect(accordion.locator('[data-slot="accordion-trigger"]')).toHaveCount(16);
    await expect(accordion.locator(`[data-slot="accordion-trigger"][aria-expanded="${expanded}"]`)).toHaveCount(16);
    await expect(accordion.getByRole('button', { name: 'Expand all settings cards' })).toHaveCount(expanded ? 0 : 16);
    await expect(accordion.getByRole('button', { name: 'Collapse all settings cards' })).toHaveCount(expanded ? 16 : 0);
}

async function saveSettings(page: Page) {
    // Include Inertia's client-side page update in the completed save.
    const finishedVisit = page.evaluate(() => {
        return new Promise<void>((resolve) => {
            document.addEventListener('inertia:finish', () => resolve(), { once: true });
        });
    });
    const response = page.waitForResponse((result) => {
        return result.request().method() === 'POST' && new URL(result.url()).pathname === '/settings';
    });
    const refreshed = page.waitForResponse((result) => {
        return result.request().method() === 'GET' && new URL(result.url()).pathname === '/settings';
    });
    await page.getByRole('button', { name: 'Save changes' }).click();
    expect((await response).status()).toBeLessThan(400);
    const refreshedResponse = await refreshed;
    expect(refreshedResponse.status()).toBe(200);
    // Response headers can arrive well before the complete settings payload.
    // Wait for the body before checking that Inertia finished the save.
    expect(await refreshedResponse.finished()).toBeNull();
    expect(refreshedResponse.headers()['x-inertia']).toBe('true');
    await finishedVisit;
    await expect(page.getByRole('button', { name: 'Save changes' })).toBeDisabled();
}

test.describe('Editor Settings accordion', () => {
    test.beforeEach(async ({ page }) => {
        await page.goto('/settings');
        await expect(page.getByRole('heading', { name: 'Editor Settings', exact: true })).toBeVisible();
    });

    for (const viewport of [
        { width: 1280, height: 800 },
        { width: 375, height: 812 },
    ]) {
        test(`supports independent cards and keyboard bulk controls at ${viewport.width}px`, async ({ page }) => {
            await page.setViewportSize(viewport);
            await expectAllCards(page, false);

            for (const value of ['contributor-person-roles', 'contributor-institution-roles', 'contributor-both-roles']) {
                await trigger(page, value).click();
            }
            for (const value of ['contributor-person-roles', 'contributor-institution-roles', 'contributor-both-roles']) {
                await expect(trigger(page, value)).toHaveAttribute('aria-expanded', 'true');
                await expect(card(page, value).getByRole('table')).toBeVisible();
            }

            const heading = trigger(page, 'contributor-both-roles');
            const expand = card(page, 'contributor-both-roles').getByRole('button', { name: 'Expand all settings cards' });
            await expand.focus();
            await expect(expand).toBeInViewport();
            await page.keyboard.press('Enter');
            await expectAllCards(page, true);
            await expect(heading).toBeFocused();

            const collapse = card(page, 'contributor-both-roles').getByRole('button', { name: 'Collapse all settings cards' });
            await collapse.focus();
            await page.keyboard.press('Space');
            await expectAllCards(page, false);
            await expect(heading).toBeFocused();
            await expect(page.getByRole('button', { name: 'Save changes' })).toBeDisabled();

            // Expansion state is page-local for every card. Use a short list for
            // reload/navigation; bulk expansion above covers the full license list.
            const transientCard = trigger(page, 'resource-types');
            await transientCard.click();
            await expect(transientCard).toHaveAttribute('aria-expanded', 'true');
            await page.reload();
            await expectAllCards(page, false);

            await transientCard.click();
            await expect(transientCard).toHaveAttribute('aria-expanded', 'true');
            await page.goto('/dashboard');
            await page.goto('/settings');
            await expectAllCards(page, false);
        });
    }

    test.describe('saving settings', () => {
        // Settings are shared across browser projects. Save and restore them once
        // while the other projects exercise the controls without changing data.
        test.skip(({ browserName }) => browserName !== 'chromium', 'The shared settings save is covered in Chromium.');

        test('preserves unsaved values through bulk actions and open cards after a real save', async ({ page }) => {
            // This round trip saves and restores the shared settings, including the
            // two redirected responses and a reload of the complete license list.
            test.setTimeout(180_000);
            await trigger(page, 'resource-types').click();
            const name = card(page, 'resource-types').getByRole('textbox', { name: 'Name', exact: true }).first();
            const originalName = await name.inputValue();
            const editedName = `${originalName} (accordion test)`;

            try {
                await name.fill(editedName);
                await card(page, 'resource-types').getByRole('button', { name: 'Collapse all settings cards' }).click();
                await expect(page.getByRole('button', { name: 'Save changes' })).toBeEnabled();
                await card(page, 'licenses').getByRole('button', { name: 'Expand all settings cards' }).click();
                await expect(name).toHaveValue(editedName);

                await saveSettings(page);
                await expectAllCards(page, true);
                await expect(name).toHaveValue(editedName);
                await page.reload();
                await expectAllCards(page, false);
                await trigger(page, 'resource-types').click();
                await expect(name).toHaveValue(editedName);
            } finally {
                // Recover the saved values even if a client-side assertion fails.
                await page.reload();
                if ((await trigger(page, 'resource-types').getAttribute('aria-expanded')) === 'false') {
                    await trigger(page, 'resource-types').click();
                }
                await name.fill(originalName);
                if (await page.getByRole('button', { name: 'Save changes' }).isEnabled()) {
                    await saveSettings(page);
                }
            }
        });
    });
});
