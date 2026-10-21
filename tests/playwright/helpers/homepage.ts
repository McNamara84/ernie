import { expect, type Page } from '@playwright/test';

export async function openHomepage(page: Page) {
    // WebKit can render the React heading before pending stylesheets apply.
    // Keyboard navigation and computed colours need the completed page load.
    const navigate = () => page.goto('/', { waitUntil: 'load' });
    const response = await navigate().catch((error: unknown) => {
        if (!(error instanceof Error) || !error.message.includes('SSL connect error')) throw error;
        // Windows WebKit occasionally rejects the local Traefik certificate
        // on its first handshake, despite ignoreHTTPSErrors.
        return navigate();
    });
    await expect(page.getByRole('heading', { name: 'Welcome to GFZ Data Services' })).toBeVisible();
    return response;
}
