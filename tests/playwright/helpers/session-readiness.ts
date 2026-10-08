import { expect, type Page } from '@playwright/test';

/** Explicitly warm the shared cookie jar after a genuine UI login. */
export async function warmUpSession(page: Page) {
    // The application's automatic refresh is optional and may abort after 5s.
    // APIRequestContext shares this browser context's authenticated cookies and
    // returns after the response body has finished, independently of that hook.
    const response = await page.request.get('/sanctum/csrf-cookie', { timeout: 15_000 });
    try {
        expect(response.status()).toBe(204);
        expect(await response.body()).toHaveLength(0);
    } finally {
        await response.dispose();
    }
}
