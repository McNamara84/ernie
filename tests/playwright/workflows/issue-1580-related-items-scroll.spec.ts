import { expect, type Locator, type Page } from '@playwright/test';

import { test as authenticated } from '../fixtures/authenticated';

const test = authenticated.extend<{ draft: { id: number; title: string }; relatedItemCount: number }>({
    relatedItemCount: [0, { option: true }],
    draft: async ({ page, relatedItemCount }, provide) => {
        await page.goto('/dashboard');
        const token = await page.locator('meta[name="csrf-token"]').getAttribute('content');
        const headers = { 'X-CSRF-TOKEN': token!, Accept: 'application/json' };
        const title = `Related items scroll ${crypto.randomUUID()}`;
        const response = await page.request.post('/editor/resources/draft', {
            headers,
            data: {
                intent: 'save-draft',
                titles: [{ title, titleType: 'main-title' }],
                relatedItems: Array.from({ length: relatedItemCount }, (_, index) => ({
                    related_item_type: 'JournalArticle',
                    relation_type_slug: 'Cites',
                    titles: [{ title: `Paper ${index + 1}`, title_type: 'MainTitle' }],
                })),
            },
        });
        expect(response.status()).toBe(201);
        const { resource } = await response.json();
        try {
            await provide({ id: resource.id, title });
        } finally {
            const removed = await page.request.delete(`/resources/${resource.id}`, { headers, maxRedirects: 0 });
            expect(removed.status()).toBe(302);
        }
    },
});

// Check the target before clicking it. Locator.click() would scroll it into view for us.
async function scrollDownTo(page: Page, scrollArea: Locator, target: Locator) {
    await scrollArea.hover();
    await expect(async () => {
        await page.mouse.wheel(0, 800);
        await expect(target).toBeInViewport({ ratio: 1, timeout: 1000 });
    }).toPass({ timeout: 10_000 });
}

test('scrolls the editor form to create and edit a related item on a short screen', async ({ page, draft }) => {
    const viewport = { width: 1280, height: 720 };
    await page.setViewportSize(viewport);
    await page.goto(`/editor?resourceId=${draft.id}`);
    await expect(page.getByTestId('main-title-input')).toHaveValue(draft.title);
    const trigger = page.getByTestId('citations-accordion-trigger');
    if ((await trigger.getAttribute('aria-expanded')) !== 'true') await trigger.click();
    await page.getByRole('button', { name: 'Manage Related Items' }).click();

    const dialog = page.getByRole('dialog', { name: 'Related Item Manager' });
    await expect(dialog.getByText('No related items yet')).toBeInViewport();
    const empty = (await dialog.boundingBox())!;
    expect(empty.height).toBeLessThan(viewport.height / 2);
    expect(Math.abs(empty.y + empty.height / 2 - viewport.height / 2)).toBeLessThan(1);
    await dialog.getByRole('button', { name: 'Add related item', exact: true }).click();
    await expect(dialog).toBeInViewport({ ratio: 1 });

    const create = dialog.locator('[data-slot="citation-manager-create"]');
    const type = dialog.getByRole('combobox', { name: 'Type *', exact: true });
    await expect(type).toBeInViewport({ ratio: 1 });
    await type.click();
    await page.getByRole('option', { name: 'Dataset', exact: true }).click();
    await dialog.getByRole('combobox', { name: 'Relation type *', exact: true }).click();
    await page.getByRole('option', { name: 'Cites', exact: true }).click();
    await dialog.getByPlaceholder('Title', { exact: true }).fill('A related paper');

    const heading = dialog.getByRole('heading', { name: 'Related Item Manager' });
    const numbering = dialog.getByRole('button', { name: 'Numbering' });
    const lastPage = dialog.getByRole('textbox', { name: 'Last page' });
    const save = dialog.getByRole('button', { name: 'Save', exact: true });
    const backgroundY = await page.evaluate(() => window.scrollY);
    await scrollDownTo(page, create, numbering);
    await numbering.click();
    await scrollDownTo(page, create, save);
    await expect(lastPage).toBeInViewport({ ratio: 1 });
    await expect(heading).toBeInViewport({ ratio: 1 });
    expect(await page.evaluate(() => window.scrollY)).toBe(backgroundY);
    await lastPage.fill('42');
    await page.mouse.wheel(0, -1600);
    await expect(type).toBeInViewport({ ratio: 1 });
    await scrollDownTo(page, create, save);
    await save.click();

    await dialog.getByRole('button', { name: 'Edit related item' }).click();
    await expect(type).toBeInViewport({ ratio: 1 });
    const edit = dialog.locator('[data-slot="citation-manager-edit"]');
    await scrollDownTo(page, edit, numbering);
    await numbering.click();
    await scrollDownTo(page, edit, save);
    await expect(lastPage).toHaveValue('42');
    await lastPage.fill('84');
    await save.click();
    await expect(dialog.getByRole('button', { name: 'Edit related item' })).toBeVisible();
    const stored = await page.request.get(`/resources/${draft.id}/related-items`);
    await expect(stored).toBeOK();
    expect((await stored.json()).data).toMatchObject([{ last_page: '84' }]);
});

test.describe('a long list from the resources page', () => {
    test.use({ relatedItemCount: 12, viewport: { width: 390, height: 640 } });

    test('starts the forthcoming form at the top and keeps its last fields and actions keyboard accessible', async ({ page, draft }) => {
        await page.goto(`/resources?search=${encodeURIComponent(draft.title)}`);
        await page.getByTestId(`resources-row-checkbox-${draft.id}`).check();
        await page.getByTestId('resources-actions-menu-trigger').click();
        const manageItems = page.getByTestId('resources-action-manage-related-items');
        await expect(manageItems).not.toHaveAttribute('data-unavailable', 'true');
        await manageItems.click();
        const dialog = page.getByRole('dialog', { name: 'Related Item Manager' });
        const list = dialog.locator('[data-slot="citation-manager-list"]');
        await expect(list.locator('[data-slot="citation-card"]')).toHaveCount(12);
        await expect(dialog).toBeInViewport({ ratio: 1 });
        const forthcoming = dialog.getByRole('button', { name: 'Add forthcoming publication' });
        await expect(forthcoming).not.toBeInViewport();
        await scrollDownTo(page, list, forthcoming);
        await forthcoming.click();
        await expect(dialog.getByText(/Add the publication title now/)).toBeInViewport({ ratio: 1 });
        await expect(dialog.getByRole('combobox', { name: 'Type *', exact: true })).toHaveText('Journal Article');

        const create = dialog.locator('[data-slot="citation-manager-create"]');
        for (const name of ['Creators (0)', 'Contributors (0)', 'Publication details']) {
            await dialog.getByRole('button', { name, exact: true }).click();
        }
        const numbering = dialog.getByRole('button', { name: 'Numbering' });
        await scrollDownTo(page, create, numbering);
        await numbering.press('Enter');
        for (const name of ['Volume', 'Issue', 'Number', 'Number type', 'First page', 'Last page']) {
            await page.keyboard.press('Tab');
            const field = dialog.getByLabel(name, { exact: true });
            await expect(field).toBeFocused();
            await expect(field).toBeInViewport();
        }
        await page.keyboard.type('42');
        await expect(dialog.getByLabel('Last page', { exact: true })).toHaveValue('42');
        const cancel = dialog.getByRole('button', { name: 'Cancel' });
        await page.keyboard.press('Tab');
        await expect(cancel).toBeFocused();
        await page.keyboard.press('Tab');
        const save = dialog.getByRole('button', { name: 'Save', exact: true });
        await expect(save).toBeFocused();
        await expect(save).toBeInViewport({ ratio: 1 });
        await expect(dialog.getByRole('heading', { name: 'Related Item Manager' })).toBeInViewport({ ratio: 1 });
        await expect(dialog.getByRole('button', { name: 'Close', exact: true })).toBeInViewport({ ratio: 1 });
        await page.keyboard.press('Shift+Tab');
        await expect(cancel).toBeFocused();
        await page.keyboard.press('Enter');
        await expect(list.locator('[data-slot="citation-card"]').first()).toBeInViewport({ ratio: 1 });
    });
});
