import { expect, type Page, test } from '@playwright/test';

import { TEST_USER_EMAIL, TEST_USER_PASSWORD } from '../constants';

function xmlFile(title: string) {
    return {
        name: 'metadata.xml',
        mimeType: 'application/xml',
        buffer: Buffer.from(`<?xml version="1.0" encoding="UTF-8"?>
<resource xmlns="http://datacite.org/schema/kernel-4">
  <titles><title>${title}</title><title titleType="Subtitle">Imported subtitle</title></titles>
  <creators><creator><creatorName nameType="Personal">Doe, Jane</creatorName></creator></creators>
  <publicationYear>2024</publicationYear>
</resource>`),
    };
}

async function navigate(page: Page, url: string) {
    for (let attempt = 1; attempt <= 2; attempt += 1) {
        try {
            await page.goto(url, { waitUntil: 'domcontentloaded' });
            return;
        } catch (error) {
            if (attempt === 2) throw error;
        }
    }
}

test.beforeEach(async ({ page }) => {
    test.setTimeout(240_000);
    page.setDefaultNavigationTimeout(60_000);
    await navigate(page, '/login');
    await page.getByLabel('Email address').fill(TEST_USER_EMAIL);
    await page.getByLabel('Password').fill(TEST_USER_PASSWORD);
    await page.getByRole('button', { name: 'Log in' }).click();
    await expect(page).toHaveURL(/\/dashboard/, { timeout: 60_000 });
});

test('adds XML to a new editor without replacing typed data and persists the draft', async ({ page }) => {
    await navigate(page, '/editor');
    const title = `Typed title ${Date.now()}`;
    await expect(page.getByTestId('main-title-input')).toBeVisible({ timeout: 60_000 });
    await page.getByTestId('main-title-input').fill(title);
    await page.getByTestId('editor-metadata-file-input').setInputFiles(xmlFile('Different imported title'));
    await expect(page.getByTestId('editor-metadata-upload').getByRole('status')).toContainText('metadata.xml was added');
    await expect(page.getByTestId('main-title-input')).toHaveValue(title);
    await expect(page.locator('#year')).toHaveValue('2024');

    const saveResponsePromise = page.waitForResponse(
        (response) => response.url().endsWith('/editor/resources/draft') && response.request().method() === 'POST',
    );
    await page.getByTestId('save-draft-button').click();
    const saveResponse = await saveResponsePromise;
    expect(saveResponse.ok()).toBeTruthy();
    expect(saveResponse.request().postDataJSON()).toMatchObject({
        titles: [
            { title, titleType: 'main-title' },
            { title: 'Imported subtitle', titleType: 'subtitle' },
        ],
        year: 2024,
    });

    const saved = (await saveResponse.json()) as { resource: { id: number } };
    await navigate(page, `/editor?resourceId=${saved.resource.id}`);
    await expect(page.getByTestId('main-title-input')).toHaveValue(title, { timeout: 60_000 });
    await expect(page.locator('#year')).toHaveValue('2024');
    await expect(page.getByTestId('editor-metadata-upload')).toHaveCount(0);
});

test('opens a new draft in the editor after XML upload from the resource list', async ({ page }) => {
    const title = `List upload ${Date.now()}`;
    await navigate(page, '/resources');
    await page.getByTestId('resources-xml-upload-input').setInputFiles(xmlFile(title));
    await expect(page).toHaveURL(/\/editor\?resourceId=\d+/, { timeout: 30_000 });
    await expect(page.getByTestId('main-title-input')).toHaveValue(title, { timeout: 60_000 });
});
