import AxeBuilder from '@axe-core/playwright';
import { expect, type Locator, type Page, test, type TestInfo } from '@playwright/test';

import { openHomepage } from '../helpers/homepage';

// WCAG 2.1 A/AA is the web baseline of EN 301 549 V3.2.1. WCAG 2.2 AA
// and axe best practices are additional regression checks, not a legal certificate.
const tags = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa', 'best-practice'];

async function scan(page: Page, testInfo: TestInfo, state: string) {
    await page.evaluate(async () => {
        await document.fonts.ready;
        await Promise.all(document.getAnimations().map((animation) => animation.finished));
    });
    const results = await new AxeBuilder({ page }).withTags(tags).analyze();
    // Keep incomplete checks for manual review; they are not passes.
    await testInfo.attach(`axe-${state}.json`, { body: JSON.stringify(results, null, 2), contentType: 'application/json' });
    expect(results.violations, `axe violations in ${state}`).toEqual([]);
}

async function expectReadableTopics(page: Page) {
    await page.evaluate(async () => {
        await document.fonts.ready;
        await new Promise<void>((resolve) => requestAnimationFrame(() => requestAnimationFrame(() => resolve())));
    });
    const clipped = await page.locator('.science-topic-caption').evaluateAll((captions) =>
        captions.flatMap((caption) => {
            const hexagon = caption.closest('.science-topic-hexagon')!;
            const box = hexagon.getBoundingClientRect();
            const isHexagon = getComputedStyle(hexagon).clipPath !== 'none';
            const walker = document.createTreeWalker(caption, NodeFilter.SHOW_TEXT);
            while (walker.nextNode()) {
                const range = document.createRange();
                range.selectNodeContents(walker.currentNode);
                for (const rect of range.getClientRects()) {
                    for (const x of [rect.left + 1, rect.right - 1]) {
                        for (const y of [rect.top + 1, rect.bottom - 1]) {
                            const dx = Math.abs(x - box.x - box.width / 2) / box.width;
                            const dy = Math.abs(y - box.y - box.height / 2) / box.height;
                            if (dx > 0.5 || dy > 0.5 || (isHexagon && 2 * dx + dy > 1)) {
                                return [
                                    {
                                        label: caption.textContent,
                                        width: box.width,
                                        height: box.height,
                                        font: getComputedStyle(caption).fontSize,
                                        containerWidth: caption.closest('.science-topics')!.clientWidth,
                                        textWidth: rect.width,
                                        dx,
                                        dy,
                                    },
                                ];
                            }
                        }
                    }
                }
            }
            return [];
        }),
    );
    expect(clipped, 'Topic labels must fit inside their visible card or hexagon').toEqual([]);
    await expect
        .poll(() => page.evaluate(() => document.documentElement.scrollWidth - innerWidth), {
            message: 'Text enlargement and spacing must not cause horizontal page overflow',
        })
        .toBeLessThanOrEqual(1);
}

function requireLinkTabbing(browserName: string) {
    // Windows MiniBrowser skips native links with both Tab and Alt+Tab, even on
    // a plain HTML control page. Keep these tests enabled in Linux CI.
    test.skip(browserName === 'webkit' && process.platform === 'win32', 'Windows WebKit omits links from its native Tab cycle.');
}

async function expectContrastingFocus(control: Locator) {
    await control.evaluate(async (element) => {
        await Promise.all(element.getAnimations().map((animation) => animation.finished));
    });
    const contrast = await control.evaluate((element) => {
        const canvas = document.createElement('canvas');
        const context = canvas.getContext('2d')!;
        function luminance(color: string) {
            context.fillStyle = color;
            context.fillRect(0, 0, 1, 1);
            const rgb = [...context.getImageData(0, 0, 1, 1).data].slice(0, 3).map((channel) => {
                const value = channel / 255;
                return value <= 0.04045 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4;
            });
            return 0.2126 * rgb[0] + 0.7152 * rgb[1] + 0.0722 * rgb[2];
        }
        const style = getComputedStyle(element);
        const background = getComputedStyle(document.querySelector('.home-page')!).backgroundColor;
        const foregroundLuminance = luminance(style.outlineColor);
        const backgroundLuminance = luminance(background);
        return {
            style: style.outlineStyle,
            width: parseFloat(style.outlineWidth),
            ratio: (Math.max(foregroundLuminance, backgroundLuminance) + 0.05) / (Math.min(foregroundLuminance, backgroundLuminance) + 0.05),
        };
    });
    expect(contrast.style).not.toBe('none');
    expect(contrast.width).toBeGreaterThan(0);
    expect(contrast.ratio).toBeGreaterThanOrEqual(3);
}

test.describe('Homepage accessibility', () => {
    test.beforeEach(async ({ page }) => {
        await page.emulateMedia({ reducedMotion: 'reduce' });
    });

    for (const width of [320, 768, 1440]) {
        for (const colorScheme of ['light', 'dark'] as const) {
            test(`has no axe violations at ${width}px in ${colorScheme}, including open navigation`, async ({ page }, testInfo) => {
                await page.setViewportSize({ width, height: 900 });
                await page.emulateMedia({ colorScheme });
                await openHomepage(page);
                await expect(page.locator('html')).toHaveAttribute('lang', /^en(?:-|$)/);
                expect(await page.locator('html').evaluate((element) => element.classList.contains('dark'))).toBe(colorScheme === 'dark');
                await expect(page.getByRole('main')).toHaveCount(1);
                await expect(page.getByRole('heading', { level: 1 })).toHaveText('GFZ Data Services');
                await expect(page.getByRole('searchbox', { name: 'Search research data' })).toBeVisible();
                await scan(page, testInfo, 'page');
                const trigger = page.getByRole('button', { name: width < 768 ? /^(Open|Close) menu$/ : 'Find', exact: true });
                await trigger.click();
                await expect(trigger).toHaveAttribute('aria-expanded', 'true');
                if (width >= 768) {
                    const menu = page.getByRole('menu');
                    await expect(menu).toBeVisible();
                    const menuId = await menu.getAttribute('id');
                    expect(menuId).toBeTruthy();
                    await expect(trigger).toHaveAttribute('aria-controls', menuId!);
                    await expect(menu).toHaveAttribute('aria-labelledby', (await trigger.getAttribute('id'))!);
                }
                await scan(page, testInfo, 'open-navigation');
                await expect(trigger).toHaveAttribute('aria-expanded', 'true');
            });
        }
    }

    for (const colorScheme of ['light', 'dark'] as const) {
        test(`supports skip link and sequential keyboard access with visible focus in ${colorScheme}`, async ({ page, browserName }) => {
            requireLinkTabbing(browserName);
            await page.emulateMedia({ colorScheme });
            await openHomepage(page);
            await page.keyboard.press('Tab');
            const skip = page.getByRole('link', { name: 'Skip to content' });
            await expect(skip).toBeFocused();
            await expect(skip).toBeInViewport();
            await page.keyboard.press('Enter');
            await expect(page.getByRole('main')).toBeFocused();
            const controls = page.getByRole('main').locator('a[href], button, input');
            for (const control of await controls.all()) {
                await page.keyboard.press('Tab');
                await expect(control).toBeFocused();
                await expect(control).toBeInViewport();
                await expectContrastingFocus(control);
                if (await control.evaluate((element) => element.classList.contains('science-topic-link'))) {
                    await expect(control.locator('.science-topic-caption')).toHaveCSS('opacity', '1');
                    await expect(control).toHaveAccessibleName((await control.locator('.science-topic-caption').textContent())!.trim());
                }
            }
            await page.keyboard.press('Tab');
            await expect(page.getByRole('navigation', { name: 'Footer navigation' }).getByRole('link').first()).toBeFocused();
        });
    }

    test('opens and dismisses mobile navigation by keyboard and restores focus', async ({ page, browserName }) => {
        requireLinkTabbing(browserName);
        await page.setViewportSize({ width: 390, height: 844 });
        await openHomepage(page);
        await page.keyboard.press('Tab');
        await page.keyboard.press('Tab');
        const trigger = page.getByRole('button', { name: /^(Open|Close) menu$/ });
        await expect(trigger).toBeFocused();
        await page.keyboard.press('Enter');
        const menu = page.getByTestId('mobile-menu');
        await expect(menu).toBeVisible();
        const menuId = await menu.getAttribute('id');
        expect(menuId).toBeTruthy();
        await expect(trigger).toHaveAttribute('aria-controls', menuId!);
        await page.keyboard.press('Tab');
        await expect(menu.getByRole('link', { name: 'Home', exact: true })).toBeFocused();
        await page.keyboard.press('Escape');
        await expect(menu).not.toBeVisible();
        await expect(page.getByRole('button', { name: 'Open menu' })).toBeFocused();
        await expect(page.getByRole('button', { name: 'Open menu' })).toHaveAttribute('aria-expanded', 'false');
    });

    test('operates the Find menu with arrow keys and returns focus with Escape', async ({ page, browserName }) => {
        requireLinkTabbing(browserName);
        await page.emulateMedia({ colorScheme: 'dark' });
        await openHomepage(page);
        for (let step = 0; step < 3; step++) await page.keyboard.press('Tab');
        const trigger = page.getByRole('button', { name: 'Find', exact: true });
        await expect(trigger).toBeFocused();
        await page.keyboard.press('Enter');
        await expect(page.getByRole('menuitem', { name: 'Data Portal', exact: true })).toBeFocused();
        await page.keyboard.press('ArrowDown');
        await expect(page.getByRole('menuitem', { name: 'IGSN Portal', exact: true })).toBeFocused();
        await expectContrastingFocus(page.getByRole('menuitem', { name: 'IGSN Portal', exact: true }));
        await page.keyboard.press('Escape');
        await expect(page.getByRole('menu')).not.toBeVisible();
        await expect(trigger).toBeFocused();
        await page.keyboard.press('Tab');
        await expect(page.getByRole('navigation', { name: 'Portal navigation' }).getByRole('link', { name: 'Publish Data' })).toBeFocused();
    });

    for (const width of [320, 768, 1440]) {
        test(`preserves content with 200% text size at ${width}px`, async ({ page }) => {
            await page.setViewportSize({ width, height: 900 });
            await openHomepage(page);
            // Text-only enlargement, not deviceScaleFactor (which only changes density).
            await page.locator('html').evaluate((element) => {
                element.style.fontSize = '200%';
            });
            await expectReadableTopics(page);
            await expect(page.getByRole('link', { name: /SUBMIT METADATA/ })).toBeVisible();
            await expect(page.getByRole('searchbox')).toBeVisible();
            expect((await page.getByRole('searchbox').boundingBox())!.width).toBeGreaterThanOrEqual(44);
        });

        test(`preserves labels with WCAG text spacing at ${width}px`, async ({ page }) => {
            await page.setViewportSize({ width, height: 900 });
            await openHomepage(page);
            await page.addStyleTag({
                content: `
                * { line-height: 1.5 !important; letter-spacing: 0.12em !important; word-spacing: 0.16em !important; }
                p { margin-bottom: 2em !important; }
            `,
            });
            await expectReadableTopics(page);
        });
    }

    test('retains topic labels and focus in Windows high contrast', async ({ page, browserName }) => {
        test.skip(browserName !== 'chromium', 'Windows forced-colors regression is exercised in Chromium.');
        await page.emulateMedia({ forcedColors: 'active' });
        await openHomepage(page);
        const topic = page.getByRole('link', { name: 'Gravity/ Gravitational Field', exact: true });
        await topic.focus();
        await expect(topic.locator('.science-topic-caption')).toHaveCSS('opacity', '1');
        const colors = await topic.locator('.science-topic-caption').evaluate((element) => {
            const style = getComputedStyle(element);
            return { text: style.color, background: style.backgroundColor };
        });
        expect(colors.background).not.toBe('rgba(0, 0, 0, 0)');
        expect(colors.text).not.toBe(colors.background);
        expect(await topic.evaluate((element) => getComputedStyle(element).outlineStyle)).not.toBe('none');
        await expectReadableTopics(page);
    });
});
