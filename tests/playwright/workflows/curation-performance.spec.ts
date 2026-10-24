import { expect, test } from '@playwright/test';

import { curationFixture as fixture } from '../helpers/curation-fixture';
import { loginAsTestUser } from '../helpers/test-helpers';

type RenderTiming = { usableMs: number; finishMs: number; firstFrameMs: number; rowIds: number[] };

// Use the same pixel workload in every browser (Desktop Safari defaults to 2x).
// Keep capture overhead outside the benchmark and retain the normal device and
// recording settings in the separate functional scenarios.
test.use({ viewport: { width: 1280, height: 720 }, deviceScaleFactor: 1, video: 'off', trace: 'off' });

test.describe('Curation rendering performance', () => {
    test('loads the first fifty of three hundred curations within one second after warmup', async ({ page }, testInfo) => {
        test.skip(testInfo.config.workers !== 1, 'Use --workers=1 for an isolated performance measurement.');
        test.setTimeout(180_000);
        const data = fixture(testInfo, 'create', undefined, 300);
        try {
            await loginAsTestUser(page, data.email, data.password);
            const coldStart = Date.now();
            await page.goto('/curation?mine=1');
            await expect(page.locator('tbody > tr')).toHaveCount(50);
            const coldMs = Date.now() - coldStart;
            const samples: (RenderTiming & { sort: string; phase: string; responseMs: number; serverMs?: number; automationMs: number })[] = [];
            // Warm every query/sort path once. Measure inside the browser so
            // Playwright's IPC and assertion polling are reported separately.
            for (const phase of ['warmup', 'measured']) {
                for (const [sort, label] of [
                    ['created', 'First saved'],
                    ['progress', 'Progress'],
                    ['activity', 'Last activity'],
                ]) {
                    await page.getByRole('combobox', { name: 'Sort by' }).click();
                    const option = page.getByRole('option', { name: label, exact: true });
                    await option.evaluate((select) => {
                        const measuredWindow = window as Window & { curationRenderTime?: Promise<RenderTiming> };
                        measuredWindow.curationRenderTime = new Promise((resolve) => {
                            select.addEventListener(
                                'pointerup',
                                () => {
                                    const start = performance.now();
                                    document.addEventListener(
                                        'inertia:finish',
                                        () => {
                                            const finishMs = performance.now() - start;
                                            requestAnimationFrame(() => {
                                                const firstFrameMs = performance.now() - start;
                                                requestAnimationFrame(() =>
                                                    resolve({
                                                        usableMs: performance.now() - start,
                                                        finishMs,
                                                        firstFrameMs,
                                                        rowIds: Array.from(
                                                            document.querySelectorAll<HTMLAnchorElement>('tbody a[href*="resourceId="]'),
                                                        ).map((link) => Number(new URL(link.href).searchParams.get('resourceId'))),
                                                    }),
                                                );
                                            });
                                        },
                                        { once: true },
                                    );
                                },
                                { once: true, capture: true },
                            );
                        });
                    });
                    const response = page.waitForResponse(
                        (result) => result.url().includes('/curation?') && result.request().headers()['x-inertia'] === 'true',
                    );
                    const start = Date.now();
                    await option.click();
                    const received = await response;
                    expect(received.ok()).toBe(true);
                    const renderTiming = await page.evaluate(
                        () => (window as Window & { curationRenderTime: Promise<RenderTiming> }).curationRenderTime,
                    );
                    await received.finished();
                    const timing = received.request().timing();
                    const backendTime = received.headers()['x-ernie-backend-time'];
                    if (backendTime) expect(backendTime).toMatch(/^\d+(\.\d+)?$/);
                    const payload = await received.json();
                    expect(renderTiming.rowIds).toEqual(payload.props.resources.data.map((row: { id: number }) => row.id));
                    await expect(page).toHaveURL(new RegExp(`sort=${sort}`));
                    await expect(page.locator('tbody > tr')).toHaveCount(50);
                    samples.push({
                        sort,
                        phase,
                        ...renderTiming,
                        responseMs: timing.responseEnd - timing.requestStart,
                        serverMs: backendTime ? Number(backendTime) * 1000 : undefined,
                        automationMs: Date.now() - start,
                    });
                }
            }
            await testInfo.attach('curation-load-times.json', {
                body: JSON.stringify({ resources: 300, pageSize: 50, coldMs, samples }),
                contentType: 'application/json',
            });
            expect(Math.max(...samples.filter((sample) => sample.phase === 'measured').map((sample) => sample.usableMs))).toBeLessThan(1000);
        } finally {
            fixture(testInfo, 'clean', data);
        }
    });
});
