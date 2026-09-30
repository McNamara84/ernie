import AxeBuilder from '@axe-core/playwright';
import { expect, type Locator, test } from '@playwright/test';

import { openDataCentres, useDataCentreCatalogue } from '../helpers/data-centres';

const tags = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa', 'best-practice'];

async function expectCaptionInsideHexagon(link: Locator) {
    const fits = await link.locator('.data-centre-overlay > span').evaluate((caption) => {
        const box = caption.closest('.data-centre-hexagon')!.getBoundingClientRect();
        const range = document.createRange();
        range.selectNodeContents(caption);
        return [...range.getClientRects()].every((rect) =>
            [rect.left + 1, rect.right - 1].every((x) =>
                [rect.top + 1, rect.bottom - 1].every((y) => {
                    const dx = Math.abs(x - box.x - box.width / 2) / box.width;
                    const dy = Math.abs(y - box.y - box.height / 2) / box.height;
                    return dx <= 0.5 && dy <= 0.5 && 2 * dx + dy <= 1.01;
                }),
            ),
        );
    });
    expect(fits, 'The hover/focus caption must fit inside the visible hexagon').toBe(true);
}

test.describe('Data Centres accessibility', () => {
    test.beforeEach(async ({ page }) => {
        await useDataCentreCatalogue(page);
        await page.emulateMedia({ reducedMotion: 'reduce' });
    });

    for (const colorScheme of ['light', 'dark'] as const) {
        test(`reveals the hexagon only on hover or keyboard focus in ${colorScheme}`, async ({ page }) => {
            await page.emulateMedia({ colorScheme });
            await openDataCentres(page);
            await page.getByRole('heading', { level: 1 }).hover();
            const links = page.locator('.data-centre-link');
            for (const link of await links.all()) {
                await expect(link.locator('.data-centre-hexagon')).toHaveCSS('background-color', 'rgba(0, 0, 0, 0)');
                await expect(link.locator('.data-centre-overlay')).toHaveCSS('opacity', '0');
                await expect(link.locator('.data-centre-label')).toBeVisible();
            }
            const link = links.first();
            const overlay = link.locator('.data-centre-overlay');
            await link.hover();
            await expect(overlay).toHaveCSS('opacity', '1');
            await expect(overlay).toHaveCSS('color', 'rgb(255, 255, 255)');
            await expect(overlay).not.toHaveCSS('background-color', 'rgba(0, 0, 0, 0)');
            await expectCaptionInsideHexagon(link);
            await page.getByRole('heading', { level: 1 }).hover();
            await expect(overlay).toHaveCSS('opacity', '0');
            await page.keyboard.press('Tab');
            await link.focus();
            await expect(link).toBeFocused();
            await expect(overlay).toHaveCSS('opacity', '1');
            // The shared reduced-motion rule uses a near-zero duration so
            // transition completion events still fire.
            expect(await overlay.evaluate((element) => Number.parseFloat(getComputedStyle(element).transitionDuration))).toBeLessThanOrEqual(0.001);
            expect(await link.evaluate((element) => getComputedStyle(element).outlineStyle)).not.toBe('none');
            await page.keyboard.press('Tab');
            await expect(overlay).toHaveCSS('opacity', '0');
        });
    }

    for (const width of [320, 768, 1440]) {
        for (const colorScheme of ['light', 'dark'] as const) {
            test(`has no axe violations on either page at ${width}px in ${colorScheme}`, async ({ page }, testInfo) => {
                await page.setViewportSize({ width, height: 900 });
                await page.emulateMedia({ colorScheme });
                for (const path of ['/data-centres', '/data-centres/description']) {
                    await openDataCentres(page, path);
                    await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1);
                    expect(await page.locator('html').evaluate((element) => element.classList.contains('dark'))).toBe(colorScheme === 'dark');
                    if (path.endsWith('/description')) {
                        for (const trigger of await page.getByRole('main').getByRole('button').all()) await trigger.click();
                    }
                    for (const state of ['page', 'open-navigation']) {
                        if (state === 'open-navigation')
                            await page.getByRole('button', { name: width < 768 ? 'Open menu' : 'Find', exact: true }).click();
                        await page.evaluate(async () => {
                            await document.fonts.ready;
                            await Promise.all(document.getAnimations().map((animation) => animation.finished));
                        });
                        const results = await new AxeBuilder({ page }).withTags(tags).analyze();
                        await testInfo.attach(`axe-${path.split('/').at(-1)}-${state}.json`, {
                            body: JSON.stringify(results, null, 2),
                            contentType: 'application/json',
                        });
                        expect(results.violations, `${path}, ${state}`).toEqual([]);
                    }
                }
            });
        }

        test(`retains links and content with enlarged text at ${width}px`, async ({ page }) => {
            await page.setViewportSize({ width, height: 900 });
            for (const path of ['/data-centres', '/data-centres/description']) {
                await openDataCentres(page, path);
                if (path.includes('/description')) await page.getByRole('main').getByRole('button').last().click();
                // Measure reflow after fonts and logos have finished loading, including
                // images that would otherwise load while WebKit scrolls the long list.
                await page.evaluate(async () => {
                    await document.fonts.ready;
                    await Promise.all(
                        [...document.querySelectorAll<HTMLImageElement>('main img')].map((image) => {
                            image.loading = 'eager';
                            return image.decode();
                        }),
                    );
                });
                await page.addStyleTag({
                    content:
                        'html { font-size: 200% !important; } main * { letter-spacing: .12em !important; word-spacing: .16em !important; } p { line-height: 1.5 !important; margin-bottom: 2em !important; }',
                });
                for (const link of await page.getByRole('main').getByRole('link').all()) {
                    await link.scrollIntoViewIfNeeded();
                    await expect(link).toBeVisible();
                    const rects = await link.evaluate((element) =>
                        [...element.getClientRects()].map((rect) => ({ left: rect.left, right: rect.right })),
                    );
                    for (const rect of rects) {
                        expect(rect.left).toBeGreaterThanOrEqual(0);
                        expect(rect.right).toBeLessThanOrEqual(width + 1);
                    }
                    if (await link.evaluate((element) => element.classList.contains('data-centre-link'))) {
                        await expectCaptionInsideHexagon(link);
                    }
                }
                expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
            }
        });
    }

    test('supports the skip link, visible keyboard focus and reduced motion', async ({ page, browserName }) => {
        test.skip(
            browserName === 'webkit' && process.platform === 'win32',
            'Windows WebKit omits links from its native Tab cycle; Linux CI covers this.',
        );
        await openDataCentres(page);
        await page.keyboard.press('Tab');
        await expect(page.getByRole('link', { name: 'Skip to content' })).toBeFocused();
        await page.keyboard.press('Enter');
        await expect(page.getByRole('main')).toBeFocused();
        for (const link of await page.getByRole('main').getByRole('link').all()) {
            await page.keyboard.press('Tab');
            await expect(link).toBeFocused();
            await expect(link).toBeInViewport();
            expect(await link.evaluate((element) => getComputedStyle(element).outlineStyle)).not.toBe('none');
        }
        await openDataCentres(page, '/data-centres/description#fid-geo');
        const content = page.locator('#fid-geo [data-slot="accordion-content"]');
        await expect(content).toBeVisible();
        expect(await content.evaluate((element) => getComputedStyle(element).animationName)).toBe('none');
    });
});
