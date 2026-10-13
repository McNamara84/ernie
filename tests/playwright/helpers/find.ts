import { expect, type Page } from '@playwright/test';

export async function openFind(page: Page) {
    const navigate = () => page.goto('/find', { waitUntil: 'domcontentloaded' });
    const response = await navigate().catch((error: unknown) => {
        if (!(error instanceof Error) || !error.message.includes('SSL connect error')) throw error;
        // Same local Windows WebKit TLS workaround as openHomepage.
        return navigate();
    });
    await expect(page.getByRole('heading', { level: 1, name: 'Find', exact: true })).toBeVisible();
    return response;
}
