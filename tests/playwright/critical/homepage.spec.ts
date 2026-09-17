import AxeBuilder from '@axe-core/playwright';
import { expect, type Page, test } from '@playwright/test';

async function openHomepage(page: Page) {
    const navigate = () => page.goto('/', { waitUntil: 'domcontentloaded' });
    return navigate().catch((error: unknown) => {
        if (!(error instanceof Error) || !error.message.includes('SSL connect error')) throw error;
        // Match the existing portal tests: Windows WebKit can reject the local
        // Traefik certificate on its first handshake, despite ignoreHTTPSErrors.
        return navigate();
    });
}

test.describe('GFZ Data Services homepage', () => {
    for (const width of [320, 390, 768, 1440]) {
        test(`keeps all 25 hexagons and public content usable at ${width}px`, async ({ page }, testInfo) => {
            await page.setViewportSize({ width, height: 900 });
            const response = await openHomepage(page);
            expect(response?.status()).toBe(200);
            await expect(page.getByRole('heading', { level: 1 })).toHaveText('GFZ Data Services');
            await expect(page.getByRole('heading', { name: 'Welcome to GFZ Data Services' })).toBeVisible();
            await expect(page.getByRole('article', { name: 'Welcome ELMO - our new Metadata Editor!' })).toBeVisible();
            const topics = page.getByRole('list', { name: 'Science topics' });
            await expect(topics.getByRole('link')).toHaveCount(25);
            for (const link of await topics.getByRole('link').all()) {
                await link.scrollIntoViewIfNeeded();
                await expect(link).toBeVisible();
                await expect(link).toHaveAttribute('href', /^\/doi-search\?topic=[a-z-]+$/);
                await expect
                    .poll(() => link.locator('img').evaluate((image: HTMLImageElement) => image.complete && image.naturalWidth > 0))
                    .toBe(true);
                expect(await link.locator('.science-topic-hexagon').evaluate((element) => getComputedStyle(element).clipPath)).toContain('polygon');
            }
            await expect(page.getByRole('heading', { name: 'Services', exact: true })).toBeVisible();
            await expect(page.getByRole('heading', { name: 'Guides', exact: true })).toBeVisible();
            await expect(page.getByRole('heading', { name: 'External Links to our data', exact: true })).toBeVisible();
            expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
            await page.screenshot({ path: testInfo.outputPath(`homepage-${width}.png`), fullPage: true });
        });
    }

    test('reveals labels on hover and keyboard focus with reduced motion', async ({ page }) => {
        await page.emulateMedia({ reducedMotion: 'reduce' });
        await openHomepage(page);
        const topic = page.getByRole('link', { name: 'Atmosphere', exact: true });
        const caption = topic.locator('.science-topic-caption');
        await topic.scrollIntoViewIfNeeded();
        await expect(caption).toHaveCSS('opacity', '0');
        await topic.hover();
        await expect(caption).toHaveCSS('opacity', '1');
        expect(await caption.evaluate((element) => parseFloat(getComputedStyle(element).transitionDuration))).toBeLessThanOrEqual(0.001);
        await page.getByRole('heading', { name: 'Explore by science topic' }).hover();
        await expect(caption).toHaveCSS('opacity', '0');
        // Establish keyboard modality independently of the browser's preference
        // for including links in its default Tab cycle (notably Windows WebKit).
        await page.keyboard.press('Tab');
        await topic.focus();
        await expect(topic).toBeFocused();
        await expect(caption).toHaveCSS('opacity', '1');
        expect(await topic.evaluate((element) => getComputedStyle(element).outlineStyle)).not.toBe('none');
    });

    test('keeps labels visible on touch and opens a topic with one tap', async ({ browser, baseURL }) => {
        const context = await browser.newContext({
            baseURL,
            ignoreHTTPSErrors: true,
            viewport: { width: 390, height: 844 },
            hasTouch: true,
            extraHTTPHeaders: { 'X-ERNIE-Playwright-Test': '1' },
        });
        const page = await context.newPage();
        await openHomepage(page);
        const topic = page.getByRole('link', { name: 'Scientific Drilling', exact: true });
        await topic.scrollIntoViewIfNeeded();
        await expect(topic.locator('.science-topic-caption')).toHaveCSS('opacity', '1');
        for (const caption of await page.locator('.science-topic-caption').all()) {
            expect(await caption.evaluate((element) => element.scrollHeight <= element.clientHeight)).toBe(true);
        }
        await topic.tap();
        await expect(page).toHaveURL(/\/doi-search\?topic=scientific-drilling$/);
        await page
            .getByRole('button', { name: /Filters/ })
            .first()
            .click();
        await expect(page.getByTestId('portal-topic-filter')).toContainText('Scientific Drilling');
        await context.close();
    });

    test('submits encoded searches with Enter and supports a blank search', async ({ page }) => {
        await openHomepage(page);
        await page.getByRole('searchbox').fill('  Höhle & CO2 + ice  ');
        await page.getByRole('searchbox').press('Enter');
        await expect(page).toHaveURL(/\/doi-search\?/);
        expect(new URL(page.url()).searchParams.get('q')).toBe('Höhle & CO2 + ice');
        await expect(page.getByTestId('portal-wordmark')).toHaveText('GFZ Data Services Portal');
        await page.getByRole('link', { name: 'Home', exact: true }).click();
        await expect(page).toHaveURL(/\/$/);
        await page.getByRole('button', { name: 'Search', exact: true }).click();
        await expect(page).toHaveURL(/\/doi-search$/);
    });

    test('preserves and removes a topic while refining a DOI search', async ({ page }) => {
        await openHomepage(page);
        await page.getByRole('link', { name: 'Scientific Drilling', exact: true }).click();
        await expect(page.getByTestId('portal-topic-filter')).toContainText('Scientific Drilling');
        await page.locator('#portal-search').fill('core');
        await page.locator('#portal-search').press('Enter');
        await expect(page).toHaveURL(/q=core/);
        expect(new URL(page.url()).searchParams.get('topic')).toBe('scientific-drilling');
        await page.getByRole('button', { name: 'Remove topic: Scientific Drilling' }).click();
        await expect(page.getByTestId('portal-topic-filter')).toHaveCount(0);
        expect(new URL(page.url()).searchParams.get('topic')).toBeNull();
        expect(new URL(page.url()).searchParams.get('q')).toBe('core');
    });

    test('opens ELMO in a new tab and keeps the homepage open', async ({ page, context }) => {
        await context.route('https://dataservices.gfz.de/elmo', (route) =>
            route.fulfill({ contentType: 'text/html', body: '<title>ELMO test destination</title>' }),
        );
        await openHomepage(page);
        const popupPromise = page.waitForEvent('popup');
        await page.getByRole('link', { name: /SUBMIT METADATA/ }).click();
        const popup = await popupPromise;
        await expect(popup).toHaveURL('https://dataservices.gfz.de/elmo');
        await expect(page).toHaveURL(/\/$/);
        await popup.close();
    });

    test('provides mobile navigation and accessible light and dark content', async ({ page }) => {
        await page.emulateMedia({ reducedMotion: 'reduce' });
        await page.setViewportSize({ width: 390, height: 844 });
        await openHomepage(page);
        await page.getByRole('button', { name: 'Open menu' }).click();
        const menu = page.getByTestId('mobile-menu');
        await expect(menu.getByRole('link', { name: 'Home' })).toHaveAttribute('aria-current', 'page');
        await expect(menu.getByRole('link', { name: 'Data Portal' })).toHaveAttribute('href', '/doi-search');
        await expect(menu.getByRole('link', { name: 'IGSN Portal' })).toHaveAttribute('href', '/igsn-search');
        await page.getByRole('button', { name: 'Close menu' }).click();
        for (const dark of [false, true]) {
            await page.emulateMedia({ colorScheme: dark ? 'dark' : 'light' });
            await openHomepage(page);
            await expect(page.getByRole('heading', { name: 'Welcome to GFZ Data Services' })).toBeVisible();
            expect(await page.locator('html').evaluate((element) => element.classList.contains('dark'))).toBe(dark);
            await page.getByRole('link', { name: /SUBMIT METADATA/ }).evaluate(async (element) => {
                await Promise.all(element.getAnimations().map((animation) => animation.finished));
            });
            const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa']).analyze();
            expect(results.violations).toEqual([]);
        }
    });
});
