import { expect, test } from '@playwright/test';

import { navigateWithTlsRetry } from '../helpers/navigation';

const states = [
    { name: 'running', date: '2027-01-01', due: false, notice: 'Under embargo until 2027-01-01' },
    { name: 'expired', date: '2027-01-01', due: true, notice: 'Embargo expired on 2027-01-01; publication is pending manual release.' },
    { name: 'invalid date', date: null, due: false, notice: 'Embargo date is missing or invalid; publication is blocked.' },
] as const;

// Reuse the anonymous application bootstrap within each worker. Repeated
// Laravel requests over a Windows bind mount can exceed navigation timeouts.
let appHtml: string | undefined;

// Render the real templates and stylesheet with controlled preview props.
// Backend embargo policy is covered separately by EmbargoWorkflowTest.php.
for (const template of ['default_gfz', 'default_gfz_igsn']) {
    for (const colorScheme of ['light', 'dark'] as const) {
        for (const state of states) {
            test(`${template}: centers the ${state.name} embargo notice in ${colorScheme} mode at mobile and desktop widths`, async ({ page }) => {
                await page.emulateMedia({ colorScheme });
                // Use the application's font fallback without depending on the font CDN.
                await page.route('https://fonts.bunny.net/**', (route) => route.fulfill({ contentType: 'text/css', body: '' }));
                if (appHtml === undefined) {
                    const response = await page.request.get('/login', { timeout: 60_000 });
                    expect(response.ok()).toBe(true);
                    appHtml = await response.text();
                }
                const body = appHtml;
                const script = /(<script\b[^>]*data-page="app"[^>]*>)([\s\S]*?)(<\/script>)/;
                expect(body).toMatch(script);
                await page.route('**/embargo-layout-preview', async (route) => {
                    await route.fulfill({
                        status: 200,
                        contentType: 'text/html',
                        body: body.replace(script, (_match, start: string, json: string, end: string) => {
                            const payload = JSON.parse(json);
                            payload.component = `LandingPages/${template}`;
                            payload.url = '/embargo-layout-preview';
                            payload.props = {
                                ...payload.props,
                                isPreview: true,
                                embargoPending: true,
                                embargoDate: state.date,
                                embargoDue: state.due,
                                resource: {
                                    id: 1437,
                                    resource_type: { id: 1, name: template === 'default_gfz' ? 'Dataset' : 'PhysicalObject' },
                                    titles: [{ id: 1, title: 'Embargo layout preview', title_type: 'MainTitle' }],
                                    descriptions: [],
                                    creators: [],
                                    funding_references: [],
                                    subjects: [],
                                    related_identifiers: [],
                                    contact_persons: [],
                                    geo_locations: [],
                                    licenses: [],
                                },
                                landingPage: { id: 1437, status: 'draft', ftp_url: 'https://example.org/data.zip' },
                            };
                            return start + JSON.stringify(payload).replaceAll('<', '\\u003c') + end;
                        }),
                    });
                });

                await navigateWithTlsRetry(page, '/embargo-layout-preview', { waitUntil: 'domcontentloaded' });
                const notice = page.getByRole('status').filter({ hasText: state.notice });
                await expect(notice).toHaveText(state.notice);
                await expect(notice).toBeVisible();
                await expect(page.locator('html')).toHaveCSS('color-scheme', colorScheme);
                await expect(page.getByTestId('files-section')).toHaveCount(0);
                await expect(page.getByTestId('data-request-section')).toHaveCount(0);
                await page.evaluate(() => document.fonts.ready);

                for (const width of [320, 390, 639, 640, 1440]) {
                    await test.step(`${width}px`, async () => {
                        await page.setViewportSize({ width, height: 900 });
                        await expect(notice).toHaveCSS('text-align', 'center');
                        await expect
                            .poll(() =>
                                notice.evaluate((element) => {
                                    const hero = document.querySelector('[data-testid="landing-page-resource-hero"]');
                                    if (!hero) throw new Error('Hero card is missing');
                                    const heroRect = hero.getBoundingClientRect();
                                    const noticeRect = element.getBoundingClientRect();
                                    return Math.max(
                                        Math.abs(heroRect.left - noticeRect.left),
                                        Math.abs(heroRect.right - noticeRect.right),
                                        Math.abs(heroRect.width - noticeRect.width),
                                    );
                                }),
                            )
                            .toBeLessThanOrEqual(1);
                        const textLayout = await notice.evaluate((element) => {
                            const box = element.getBoundingClientRect();
                            const range = document.createRange();
                            range.selectNodeContents(element);
                            const lines = Array.from(range.getClientRects());
                            return {
                                lines: lines.length,
                                centerOffset: Math.max(...lines.map((line) => Math.abs(line.left + line.width / 2 - (box.left + box.width / 2)))),
                                overflows: element.scrollWidth > element.clientWidth,
                            };
                        });
                        expect(textLayout.lines).toBeGreaterThan(0);
                        expect(textLayout.centerOffset).toBeLessThanOrEqual(1);
                        expect(textLayout.overflows).toBe(false);
                    });
                }
            });
        }
    }
}
