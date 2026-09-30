import { expect, type Page, test } from '@playwright/test';

import { TEST_USER_EMAIL, TEST_USER_PASSWORD } from '../constants';

const rorId = 'https://ror.org/04z8jg394';
const secondRorId = 'https://ror.org/018mejw64';
const catalog = [
    { prefLabel: 'GFZ Helmholtz Centre for Geosciences', rorId, otherLabel: ['GFZ'] },
    { prefLabel: 'Deutsche Forschungsgemeinschaft', rorId: secondRorId, otherLabel: ['DFG'] },
];

async function expand(page: Page, name: string) {
    const trigger = page.locator('[data-slot="accordion-trigger"]').filter({ hasText: name }).first();
    if ((await trigger.getAttribute('aria-expanded')) !== 'true') await trigger.click();
}

test.beforeEach(async ({ page }) => {
    test.setTimeout(180_000);
    page.setDefaultNavigationTimeout(60_000);
    await page.route('**/api/v1/ror-affiliations', (route) => route.fulfill({ json: catalog }));
    const login = () => page.goto('/login', { waitUntil: 'domcontentloaded' });
    await login().catch((error: unknown) => {
        // Windows WebKit can reject the local certificate on its first handshake.
        if (!(error instanceof Error) || !error.message.includes('SSL connect error')) throw error;
        return login();
    });
    await page.getByLabel('Email address').fill(TEST_USER_EMAIL);
    await page.getByLabel('Password').fill(TEST_USER_PASSWORD);
    await page.getByRole('button', { name: 'Log in' }).click();
    await expect(page).toHaveURL(/\/dashboard/, { timeout: 60_000 });
    await page.goto('/editor', { waitUntil: 'domcontentloaded' });
    await expect(page.getByTestId('main-title-input')).toBeVisible({ timeout: 60_000 });
});

for (const section of ['author', 'contributor'] as const) {
    test(`${section}: confirms IDs, preserves combined labels and keeps unresolved input across accordion changes`, async ({ page }) => {
        const name = section === 'author' ? 'Authors' : 'Contributors';
        await expand(page, name);
        await page.getByRole('button', { name: section === 'author' ? /Add First Author/i : /Add First Contributor/i }).click();
        const field = page.getByTestId(`${section}-0-affiliations-field`);
        const input = field.locator('.tagify__input');
        await input.fill('04z8jg394');
        await expect(field.getByRole('option')).toContainText(rorId);
        await page.getByTestId('main-title-input').click();
        await expect(field.locator('.tagify__tag')).toHaveCount(0);
        await field.getByRole('option').click();
        await expect(field.locator('.tagify__tag-text')).toHaveText(catalog[0].prefLabel);

        await input.fill(`My Institute (Unit A), Potsdam (${rorId})`);
        await input.press('Enter');
        await expect(field.locator('.tagify__tag-text')).toHaveText([catalog[0].prefLabel, 'My Institute (Unit A), Potsdam']);

        await input.fill('Unknown Institute (012345678)');
        await expect(field.getByRole('status')).toHaveText('ROR ID not found in the local directory.');
        const trigger = page.locator('[data-slot="accordion-trigger"]').filter({ hasText: name }).first();
        await trigger.click();
        await trigger.click();
        await expect(field.getByRole('status')).toHaveText('ROR ID not found in the local directory.');
        await field.getByRole('button', { name: 'Use name without ROR ID' }).click();
        await expect(field.locator('.tagify__tag-text')).toHaveText([catalog[0].prefLabel, 'My Institute (Unit A), Potsdam', 'Unknown Institute']);
    });

    test(`${section}: pastes a combined ROR entry and explicitly changes an existing ID`, async ({ page }) => {
        await expand(page, section === 'author' ? 'Authors' : 'Contributors');
        await page.getByRole('button', { name: section === 'author' ? /Add First Author/i : /Add First Contributor/i }).click();
        const field = page.getByTestId(`${section}-0-affiliations-field`);
        const input = field.locator('.tagify__input');
        await input.click();
        await input.evaluate((element, text) => {
            const data = new DataTransfer();
            data.setData('text/plain', text);
            const event = new ClipboardEvent('paste', { bubbles: true, cancelable: true });
            // Firefox drops constructor-supplied clipboard data on synthetic events.
            Object.defineProperty(event, 'clipboardData', { value: data });
            element.dispatchEvent(event);
        }, `Custom (Unit A), Potsdam (${rorId})`);
        await expect(field.getByRole('option')).toContainText(rorId);
        await expect(field.locator('.tagify__tag')).toHaveCount(0);
        await input.press('Enter');
        await expect(field.locator('.tagify__tag-text')).toHaveText('Custom (Unit A), Potsdam');
        await field.locator('.tagify__tag-text').dblclick();
        const editing = field.locator('.tagify__tag [contenteditable="true"]');
        await editing.fill(`Replacement (${secondRorId})`);
        await expect(field.getByRole('option')).toContainText(secondRorId);
        await editing.press('Enter');
        await expect(field.locator('.tagify__tag-text')).toHaveText('Replacement');
        await expect(page.getByTestId(`${section}-0-affiliations-ror-ids`)).toContainText(secondRorId);
        await expect(page.getByTestId(`${section}-0-affiliations-ror-ids`)).not.toContainText(rorId);
        await input.fill('Unlinked A, Unlinked B');
        await input.press('Enter');
        await expect(field.locator('.tagify__tag-text')).toHaveText(['Replacement', 'Unlinked A', 'Unlinked B']);
    });
}

test('funding: confirms an exact ROR, saves custom name and round trips the identifier', async ({ page }) => {
    await page.getByTestId('main-title-input').fill(`ROR entry regression ${Date.now()}`);
    await expand(page, 'Funding References');
    await page.getByRole('button', { name: 'Add Funding Reference', exact: true }).click();
    const input = page.getByLabel('Funder Name', { exact: false });
    await input.fill(`My Funding Institute (${rorId})`);
    await input.press('Escape');
    await expect(page.getByRole('option')).toHaveCount(0);
    await input.press('ArrowDown');
    await expect(page.getByRole('option')).toContainText(catalog[0].prefLabel);
    await input.press('Enter');
    await expect(input).toHaveValue('My Funding Institute');
    const responsePromise = page.waitForResponse(
        (response) => response.url().endsWith('/editor/resources/draft') && response.request().method() === 'POST',
    );
    await page.getByTestId('save-draft-button').click();
    const response = await responsePromise;
    expect(response.ok()).toBeTruthy();
    expect(response.request().postDataJSON().fundingReferences[0]).toMatchObject({
        funderName: 'My Funding Institute',
        funderIdentifier: rorId,
        funderIdentifierType: 'ROR',
    });
    const saved = (await response.json()) as { resource: { id: number } };
    await page.goto(`/editor?resourceId=${saved.resource.id}`);
    await expand(page, 'Funding References');
    await expect(page.getByLabel('Funder Name', { exact: false })).toHaveValue('My Funding Institute');
    await expect(page.getByTestId('funding-reference-card')).toContainText(rorId);
});

test('blocks draft saves for unknown ROR IDs until explicitly discarded', async ({ page }) => {
    await page.getByTestId('main-title-input').fill('Unconfirmed ROR must not be saved');
    await expand(page, 'Funding References');
    await page.getByRole('button', { name: 'Add Funding Reference', exact: true }).click();
    await page.getByLabel('Funder Name', { exact: false }).fill('012345678');
    const requests: string[] = [];
    page.on('request', (request) => {
        if (request.method() === 'POST' && request.url().includes('/editor/resources')) requests.push(request.url());
    });
    await page.getByTestId('save-draft-button').click();
    await expect(page.getByText('Please finish the institution or funder input before saving.')).toBeVisible();
    await expect(page.getByLabel('Funder Name', { exact: false })).toBeFocused();
    expect(requests).toHaveLength(0);
    await page.getByRole('button', { name: 'Discard ROR input' }).click();
    await expect(page.getByLabel('Funder Name', { exact: false })).toHaveValue('');
});
