import type { Page } from '@playwright/test';

/** WebKit on Windows can reject the local Traefik certificate on the first handshake. */
export async function navigateWithTlsRetry(page: Page, url: string, options?: Parameters<Page['goto']>[1]) {
    const navigate = () => page.goto(url, options);

    return navigate().catch((error: unknown) => {
        if (!(error instanceof Error) || !error.message.includes('SSL connect error')) {
            throw error;
        }

        return navigate();
    });
}
