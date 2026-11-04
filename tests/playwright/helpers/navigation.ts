import type { Page } from '@playwright/test';

export function isLocalWindowsWebKit(browserName: string) {
    return (
        browserName === 'webkit' && process.platform === 'win32' && !process.env.ERNIE_WEBKIT_WS_ENDPOINT && !process.env.PW_TEST_CONNECT_WS_ENDPOINT
    );
}

/** Windows browsers can reject the local Traefik certificate on the first exchange. */
export async function navigateWithTlsRetry(page: Page, url: string, options?: Parameters<Page['goto']>[1]) {
    const navigate = () => page.goto(url, options);

    return navigate().catch((error: unknown) => {
        if (!(error instanceof Error) || !/SSL connect error|net::ERR_TOO_MANY_RETRIES/.test(error.message)) {
            throw error;
        }

        return navigate();
    });
}

/** Establish native TLS before fulfilled HTML moves the first real request to CSS. */
export async function prepareHtmlInterception(page: Page) {
    const browserName = page.context().browser()?.browserType().name();
    if (process.platform === 'win32' && (browserName === 'webkit' || browserName === 'chromium')) {
        await navigateWithTlsRetry(page, '/robots.txt', { waitUntil: 'domcontentloaded' });
    }
}
