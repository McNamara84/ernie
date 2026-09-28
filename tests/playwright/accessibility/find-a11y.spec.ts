import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

import { openFind } from '../helpers/find';

const tags = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa', 'best-practice'];

test.describe('Find accessibility', () => {
    test.beforeEach(async ({ page }) => {
        await page.emulateMedia({ reducedMotion: 'reduce' });
    });

    for (const width of [320, 768, 1440]) {
        for (const colorScheme of ['light', 'dark'] as const) {
            test(`has no axe violations at ${width}px in ${colorScheme}, including open navigation`, async ({ page }, testInfo) => {
                await page.setViewportSize({ width, height: 900 });
                await page.emulateMedia({ colorScheme });
                await openFind(page);
                await expect(page.locator('html')).toHaveAttribute('lang', /^en(?:-|$)/);
                expect(await page.locator('html').evaluate((element) => element.classList.contains('dark'))).toBe(colorScheme === 'dark');
                await expect(page.getByRole('main')).toHaveCount(1);
                await expect(page.getByRole('heading', { level: 1 })).toHaveText('Find');
                for (const state of ['page', 'open-navigation']) {
                    if (state === 'open-navigation') {
                        await page.getByRole('button', { name: width < 768 ? 'Open menu' : 'Find', exact: true }).click();
                        await expect(page.getByRole(width < 768 ? 'link' : 'menuitem', { name: 'Overview', exact: true })).toHaveAttribute(
                            'aria-current',
                            'page',
                        );
                    }
                    await page.evaluate(async () => {
                        await document.fonts.ready;
                        await Promise.all(document.getAnimations().map((animation) => animation.finished));
                    });
                    const results = await new AxeBuilder({ page }).withTags(tags).analyze();
                    await testInfo.attach(`axe-find-${state}.json`, { body: JSON.stringify(results, null, 2), contentType: 'application/json' });
                    expect(results.violations, `axe violations in ${state}`).toEqual([]);
                }
            });
        }

        test(`preserves content and links with enlarged text and spacing at ${width}px`, async ({ page }) => {
            await page.setViewportSize({ width, height: 900 });
            await openFind(page);
            await page.addStyleTag({
                content:
                    'html { font-size: 200% !important; } p { line-height: 1.5 !important; margin-bottom: 2em !important; } main * { letter-spacing: .12em !important; word-spacing: .16em !important; }',
            });
            for (const link of await page.getByRole('main').getByRole('link').all()) {
                await link.scrollIntoViewIfNeeded();
                await expect(link).toBeVisible();
                const rects = await link.evaluate((element) => [...element.getClientRects()].map((rect) => ({ left: rect.left, right: rect.right })));
                for (const rect of rects) {
                    expect(rect.left).toBeGreaterThanOrEqual(0);
                    expect(rect.right).toBeLessThanOrEqual(width + 1);
                }
            }
            expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
        });
    }

    for (const colorScheme of ['light', 'dark'] as const) {
        test(`supports the skip link and keyboard access to every content link in ${colorScheme}`, async ({ page, browserName }) => {
            // Windows MiniBrowser omits native links from Tab traversal; Linux CI covers WebKit.
            test.skip(browserName === 'webkit' && process.platform === 'win32', 'Windows WebKit omits links from its native Tab cycle.');
            await page.emulateMedia({ colorScheme });
            await openFind(page);
            await page.keyboard.press('Tab');
            await expect(page.getByRole('link', { name: 'Skip to content' })).toBeFocused();
            await page.keyboard.press('Enter');
            await expect(page.getByRole('main')).toBeFocused();
            for (const link of await page.getByRole('main').getByRole('link').all()) {
                await page.keyboard.press('Tab');
                await expect(link).toBeFocused();
                await expect(link).toBeInViewport();
                expect(await link.evaluate((element) => getComputedStyle(element).outlineStyle)).not.toBe('none');
                expect(await link.evaluate((element) => parseFloat(getComputedStyle(element).outlineWidth))).toBeGreaterThan(0);
            }
        });
    }

    test('operates the Overview dropdown using the keyboard and returns focus on Escape', async ({ page }) => {
        await openFind(page);
        const trigger = page.getByRole('button', { name: 'Find', exact: true });
        await trigger.focus();
        await page.keyboard.press('Enter');
        for (const label of ['Overview', 'Data Portal', 'IGSN Portal']) {
            await expect(page.getByRole('menuitem', { name: label, exact: true })).toBeFocused();
            await page.keyboard.press('ArrowDown');
        }
        await page.keyboard.press('Escape');
        await expect(page.getByRole('menu')).toHaveCount(0);
        await expect(trigger).toBeFocused();
    });

    test('closes the mobile menu with Escape and restores focus', async ({ page }) => {
        await page.setViewportSize({ width: 390, height: 844 });
        await openFind(page);
        await page.getByRole('button', { name: 'Open menu' }).click();
        await page.getByRole('link', { name: 'Overview', exact: true }).focus();
        await page.keyboard.press('Escape');
        await expect(page.getByTestId('mobile-menu')).toHaveCount(0);
        await expect(page.getByRole('button', { name: 'Open menu' })).toBeFocused();
    });
});
