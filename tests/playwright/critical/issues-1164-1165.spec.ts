import { expect, test } from '@playwright/test';

import { LandingPage } from '../helpers/page-objects/LandingPage';

const LEGACY_IGSN = '10273/GFBNO7002EXZ3001';
const FIXTURE_TITLE = 'Playwright: Legacy IGSN Identity and Repository Contact';

test.describe('Issues #1164 and #1165 - legacy IGSN identity and repository privacy', () => {
    test('renders the direct Handle link and protected repository forms without exposing addresses', async ({ page }) => {
        const landingPage = new LandingPage(page);
        await landingPage.goto('playwright-legacy-igsn');
        await landingPage.verifyPageLoaded();

        const legacyLink = page.getByRole('link', { name: LEGACY_IGSN });
        await expect(legacyLink).toHaveAttribute('href', `https://hdl.handle.net/${LEGACY_IGSN}`);
        await expect(legacyLink).not.toHaveAttribute('href', /igsn\.org\/10273\//);

        const html = await page.content();
        expect(html).not.toContain('playwright-current-repository@example.org');
        expect(html).not.toContain('playwright-original-repository@example.org');

        const currentButton = page.getByRole('button', { name: 'Contact current repository' });
        const originalButton = page.getByRole('button', { name: 'Contact original repository' });
        await expect(currentButton).toBeVisible();
        await expect(originalButton).toBeVisible();

        let submittedBody: Record<string, unknown> | null = null;
        await page.route('**/playwright-legacy-igsn/contact', async (route) => {
            submittedBody = route.request().postDataJSON() as Record<string, unknown>;
            await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ message: 'ok' }) });
        });

        await currentButton.click();
        await page.getByLabel(/Your name/).fill('Playwright User');
        await page.getByLabel(/Your email/).fill('sender@example.org');
        await page.getByLabel(/Message/).fill('Please provide more information about this physical sample.');
        await page.getByRole('button', { name: 'Send Message' }).click();

        await expect(page.getByText('Message sent successfully!')).toBeVisible();
        expect(submittedBody).toMatchObject({
            send_to_all: false,
            repository_contact_type: 'current',
            resource_creator_id: null,
            resource_contributor_id: null,
        });
        expect(submittedBody).not.toHaveProperty('recipient_email');
    });

    test('finds the published sample through its legacy identity Handle', async ({ page }) => {
        await page.goto(`/portal?q=${encodeURIComponent(LEGACY_IGSN)}`);

        await expect(page.getByTestId('portal-results-list').first()).toBeVisible();
        await expect(page.getByText(FIXTURE_TITLE, { exact: true }).first()).toBeVisible();
    });
});
