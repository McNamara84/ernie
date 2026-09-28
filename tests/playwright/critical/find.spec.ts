import { expect, test } from '@playwright/test';

import { openFind } from '../helpers/find';
import { openHomepage } from '../helpers/homepage';

test.describe('Find overview', () => {
    for (const width of [320, 768, 1024, 1440]) {
        test(`serves the complete public overview at ${width}px`, async ({ page }, testInfo) => {
            await page.setViewportSize({ width, height: 900 });
            const response = await openFind(page);
            expect(response?.status()).toBe(200);
            await expect(page).toHaveTitle(/^Find(?:\s|$)/);
            await expect(page.locator('link[rel="canonical"]')).toHaveAttribute('href', 'https://dataservices.gfz.de/find');
            await expect(page.locator('meta[name="description"]')).toHaveAttribute('content', /Data Portal and IGSN Portal/);
            await expect(page.getByRole('main').getByRole('heading', { level: 2 })).toHaveText([
                'Data Portal',
                'Data Centres',
                'Research Infrastructures at GFZ',
                'IGSN Portal',
            ]);
            for (const heading of await page.getByRole('heading', { level: 2 }).all()) {
                await heading.scrollIntoViewIfNeeded();
                await expect(heading).toBeVisible();
            }
            const sections = await page.getByRole('main').getByRole('region').all();
            for (const [index, section] of sections.entries()) {
                const image = section.getByRole('img');
                await image.scrollIntoViewIfNeeded();
                await expect(image).toBeVisible();
                await expect.poll(() => image.evaluate((element: HTMLImageElement) => element.complete && element.naturalWidth > 0)).toBe(true);
                const geometry = await section.evaluate((element) => {
                    const image = element.querySelector('img')!;
                    const picture = image.getBoundingClientRect();
                    const copy = element.querySelector('h2')!.parentElement!.getBoundingClientRect();
                    return {
                        picture: { left: picture.left, right: picture.right, bottom: picture.bottom },
                        copy: { left: copy.left, right: copy.right, top: copy.top },
                        ratio: picture.width / picture.height,
                        originalRatio: image.naturalWidth / image.naturalHeight,
                    };
                });
                expect(geometry.ratio).toBeCloseTo(geometry.originalRatio, 1);
                if (width < 1024) {
                    expect(geometry.picture.bottom).toBeLessThan(geometry.copy.top);
                } else if (index % 2 === 0) {
                    expect(geometry.picture.right).toBeLessThan(geometry.copy.left);
                } else {
                    expect(geometry.copy.right).toBeLessThan(geometry.picture.left);
                }
            }
            await page.getByRole('navigation', { name: 'Footer navigation' }).scrollIntoViewIfNeeded();
            await expect(page.getByRole('link', { name: 'Data Centres', exact: true })).toHaveAttribute(
                'href',
                'https://dataservices.gfz-potsdam.de/web/find/data-centres',
            );
            await expect(page.getByRole('link', { name: 'MESI', exact: true })).toHaveAttribute(
                'href',
                'https://www.gfz.de/en/research/topics/our-research-program/research-infrastructures/mesi',
            );
            await expect(page.getByRole('navigation', { name: 'Footer navigation' })).toBeVisible();
            expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
            await page.screenshot({ path: testInfo.outputPath(`find-${width}.png`), fullPage: true });
        });
    }

    for (const width of [390, 1440]) {
        test(`navigates from Home through Overview to each portal and back at ${width}px`, async ({ page }) => {
            await page.setViewportSize({ width, height: 900 });
            await openHomepage(page);
            const mobile = width < 768;
            const openMenu = () => page.getByRole('button', { name: mobile ? 'Open menu' : 'Find', exact: true }).click();
            const overview = () => page.getByRole(mobile ? 'link' : 'menuitem', { name: 'Overview', exact: true });
            await openMenu();
            await overview().click();
            await expect(page).toHaveURL(/\/find$/);
            await expect(page.getByRole('heading', { level: 1 })).toHaveText('Find');

            for (const [label, path] of [
                ['Data Portal', '/doi-search'],
                ['IGSN Portal', '/igsn-search'],
            ]) {
                await openMenu();
                await expect(overview()).toHaveAttribute('aria-current', 'page');
                await page.keyboard.press('Escape');
                await page.getByRole('link', { name: `Explore the ${label}`, exact: true }).click();
                await expect(page).toHaveURL(new RegExp(`${path}$`));
                await expect(page.getByTestId('portal-wordmark')).toHaveText('GFZ Data Services Portal');
                await openMenu();
                await expect(overview()).not.toHaveAttribute('aria-current');
                await expect(page.getByRole(mobile ? 'link' : 'menuitem', { name: label, exact: true })).toHaveAttribute('aria-current', 'page');
                await overview().click();
                await expect(page).toHaveURL(/\/find$/);
                await expect(page.getByRole('heading', { level: 1 })).toHaveText('Find');
                if (mobile) await expect(page.getByTestId('mobile-menu')).toHaveCount(0);
            }
            if (mobile) await openMenu();
            await page.getByRole('link', { name: 'Home', exact: true }).click();
            await expect(page).toHaveURL(/\/$/);
            await expect(page.getByRole('heading', { level: 1 })).toHaveText('GFZ Data Services');
        });
    }
});
