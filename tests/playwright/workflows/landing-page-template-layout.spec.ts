import { expect } from '@playwright/test';

import { test } from '../fixtures/authenticated';

for (const width of [390, 768, 1024, 1440]) {
    for (const type of ['resource', 'igsn'] as const) {
        test(`${type} template editor keeps module controls inside their rows at ${width}px`, async ({ page }) => {
            await page.setViewportSize({ width, height: 1000 });
            // Render a custom copy through the real page without saving a template.
            let injected = false;
            await page.route('**/landing-pages', async (route) => {
                if (route.request().resourceType() !== 'document') return route.continue();
                const response = await route.fetch();
                const html = (await response.text()).replace(
                    /(<script[^>]*data-page="app"[^>]*>)([\s\S]*?)(<\/script>)/,
                    (_match, open: string, raw: string, close: string) => {
                        const payload = JSON.parse(raw);
                        const original = payload.props.templates.find(
                            (template: { template_type: string; is_default: boolean }) => template.template_type === type && template.is_default,
                        );
                        expect(original).toBeTruthy();
                        payload.props.templates = [
                            {
                                ...original,
                                id: 999999,
                                name: 'Responsive layout test',
                                is_default: false,
                                hidden_sections: [],
                                datacenters: [],
                                datacenters_count: 0,
                                landing_pages_count: 0,
                            },
                        ];
                        injected = true;
                        return open + JSON.stringify(payload).replaceAll('<', '\\u003c') + close;
                    },
                );
                await route.fulfill({ status: response.status(), contentType: 'text/html', body: html });
            });
            await page.goto('/landing-pages');
            expect(injected).toBe(true);
            await page.getByRole('button', { name: 'Edit', exact: true }).click();
            const dialog = page.getByRole('dialog', { name: 'Edit Template', exact: true });
            await expect(dialog).toBeVisible();
            await expect
                .poll(() =>
                    dialog.evaluate((element) => {
                        const rect = element.getBoundingClientRect();
                        return rect.left >= 15 && rect.right <= innerWidth - 15 && element.scrollWidth <= element.clientWidth;
                    }),
                )
                .toBe(true);
            if (width >= 1024) {
                expect((await dialog.boundingBox())!.width).toBeGreaterThanOrEqual(990);
            }
            const left = await dialog.getByTestId(`${type}-left-column`).boundingBox();
            const right = await dialog.getByTestId(`${type}-right-column`).boundingBox();
            if (width >= 1024) {
                expect(Math.abs(left!.y - right!.y)).toBeLessThan(1);
                expect(right!.x).toBeGreaterThan(left!.x + left!.width);
            } else {
                expect(right!.y).toBeGreaterThan(left!.y + left!.height);
            }

            async function expectControlsFit() {
                const violations = await dialog.getByRole('button', { name: /^Reorder / }).evaluateAll((handles) =>
                    handles.flatMap((handle) => {
                        const row = handle.parentElement!;
                        const bounds = row.getBoundingClientRect();
                        const label = row.querySelector('span')!.getBoundingClientRect();
                        const controls = [...row.querySelectorAll('button')];
                        const fits =
                            row.scrollWidth <= row.clientWidth &&
                            controls.every((control, index) => {
                                const rect = control.getBoundingClientRect();
                                return (
                                    rect.left >= bounds.left &&
                                    rect.right <= bounds.right &&
                                    (index === 0 ? rect.right <= label.left : rect.left >= label.right)
                                );
                            });
                        return fits ? [] : [handle.getAttribute('aria-label')];
                    }),
                );
                expect(violations).toEqual([]);
            }
            await expectControlsFit();
            await dialog.getByRole('button', { name: 'Hide Usage', exact: true }).click();
            await expect(dialog.getByTestId(`${type}-hidden-column`).getByText('Usage', { exact: true })).toBeVisible();
            await expectControlsFit();
            await dialog.getByRole('button', { name: 'Show Usage in right column', exact: true }).click();
            await expect(dialog.getByTestId(`${type}-right-column`).getByText('Usage', { exact: true })).toBeVisible();
            await dialog.getByRole('button', { name: 'Cancel', exact: true }).click();
        });
    }
}
