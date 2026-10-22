import { spawnSync } from 'node:child_process';

import { expect, test, type TestInfo } from '@playwright/test';

import { loginAsTestUser } from '../helpers/test-helpers';

type Fixture = {
    tag: string;
    title: string;
    id: number;
    actor: number;
    next: number;
    beginner: number;
    email: string;
    beginnerEmail: string;
    password: string;
};
type RenderTiming = { usableMs: number; finishMs: number; firstFrameMs: number; rowIds: number[] };
function fixture(testInfo: TestInfo, action: 'create' | 'clean', data?: Fixture, count = 1): Fixture {
    const docker = ['playwright.devstack.config.ts', 'playwright.docker.config.ts'].some((config) => testInfo.config.configFile?.endsWith(config));
    const args = ['-d', 'memory_limit=2G', 'tests/playwright/fixtures/curation-state.php', action, String(count)];
    const result = spawnSync(
        docker ? 'docker' : 'php',
        docker ? ['compose', '--env-file', '.env.docker', '-f', 'docker-compose.dev.yml', 'exec', '-T', 'app', 'php', ...args] : args,
        { input: data ? JSON.stringify(data) : undefined, encoding: 'utf8', timeout: 120_000 },
    );
    if (result.error || result.status !== 0) throw result.error ?? new Error(result.stderr || result.stdout);
    return action === 'create' ? (JSON.parse(result.stdout) as Fixture) : data!;
}

test.describe('Curation tracking', () => {
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
                for (const sort of ['created', 'progress', 'activity']) {
                    await page.getByLabel('Sort by').evaluate((select) => {
                        const measuredWindow = window as Window & { curationRenderTime?: Promise<RenderTiming> };
                        measuredWindow.curationRenderTime = new Promise((resolve) => {
                            select.addEventListener(
                                'change',
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
                                { once: true },
                            );
                        });
                    });
                    const response = page.waitForResponse(
                        (result) => result.url().includes('/curation?') && result.request().headers()['x-inertia'] === 'true',
                    );
                    const start = Date.now();
                    await page.getByLabel('Sort by').selectOption(sort);
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
    test('hands over a resource through My Resources and preserves unsaved metadata', async ({ page }, testInfo) => {
        test.setTimeout(180_000);
        const data = fixture(testInfo, 'create');
        try {
            await loginAsTestUser(page, data.email, data.password);
            await page.goto('/curation?mine=1');
            await expect(page.getByRole('checkbox', { name: 'My Resources' })).toBeChecked();
            await page.getByRole('button', { name: 'Show details', exact: true }).click();
            await expect(page.getByRole('heading', { name: 'Open checklist items', exact: true })).toBeVisible();
            await page.getByLabel('Sort by').selectOption('created');
            await expect(page).toHaveURL(/sort=created/);
            await expect(page.getByRole('button', { name: 'Hide details', exact: true })).toBeVisible();
            await expect(page.getByRole('heading', { name: 'Open checklist items', exact: true })).toBeVisible();
            await page.getByRole('link', { name: data.title, exact: true }).click();
            await expect(page).toHaveURL(new RegExp(`resourceId=${data.id}`));
            await page.route('**/editor/resources/draft', (route) => route.abort());
            const titleInput = page.getByTestId('main-title-input');
            await titleInput.fill(`${data.title} unsaved`);
            const trigger = page.getByRole('button', { name: 'Curation', exact: true }).filter({ visible: true });
            await trigger.click();
            const panel = page.getByRole('dialog', { name: 'Curation', exact: true });
            await expect(panel.getByRole('checkbox', { name: 'Data received', exact: true })).toBeVisible();
            const saved = page.waitForResponse(
                (response) => response.url().endsWith('/curation/checklist/data_received') && response.request().method() === 'PATCH',
            );
            await panel.getByRole('checkbox', { name: 'Data received', exact: true }).click();
            expect((await saved).status()).toBe(200);
            await expect(panel.getByRole('checkbox', { name: 'Data received', exact: true })).toBeChecked();
            await panel.getByRole('button', { name: 'Hand over', exact: true }).click();
            await panel.getByLabel('New lead curator').selectOption(String(data.next));
            await panel.getByLabel('Handover note (optional)').fill('Check the time zone before review.');
            await panel.getByRole('button', { name: 'Save responsibility' }).click();
            await expect(panel.getByText('Lead: Browser Handover', { exact: true })).toBeVisible();
            await expect(panel.getByText('Browser Curator', { exact: true }).first()).toBeVisible();
            await panel.getByLabel('New note', { exact: true }).fill('Data is ready for review.');
            await panel.getByRole('button', { name: 'Add note', exact: true }).click();
            await expect(panel.getByText('Data is ready for review.', { exact: true })).toBeVisible();
            await page.keyboard.press('Escape');
            await expect(titleInput).toHaveValue(`${data.title} unsaved`);
            await titleInput.fill(data.title);
            await page.unroute('**/editor/resources/draft');
            await page.goto('/curation?mine=1');
            await expect(page.getByRole('link', { name: data.title, exact: true })).toBeVisible();
            await page.getByRole('button', { name: 'Show details' }).click();
            await expect(page.getByText('Data is ready for review.', { exact: true })).toBeVisible();
        } finally {
            fixture(testInfo, 'clean', data);
        }
    });

    test('offers keyboard-accessible read-only curation to beginners on mobile', async ({ page }, testInfo) => {
        test.setTimeout(180_000);
        const data = fixture(testInfo, 'create');
        try {
            await page.setViewportSize({ width: 375, height: 812 });
            await loginAsTestUser(page, data.beginnerEmail, data.password);
            await page.goto(`/editor?resourceId=${data.id}`);
            const trigger = page.getByRole('button', { name: 'Curation', exact: true }).filter({ visible: true });
            await trigger.focus();
            await page.keyboard.press('Enter');
            const panel = page.getByRole('dialog', { name: 'Curation', exact: true });
            await expect(panel.getByText('Read-only access to curation.')).toBeVisible();
            await expect(panel.getByRole('checkbox', { name: 'Data received', exact: true })).toBeDisabled();
            await expect(panel.getByRole('button', { name: 'Add note', exact: true })).toHaveCount(0);
            await expect(panel).toBeInViewport();
            await page.keyboard.press('Escape');
            await expect(trigger).toBeFocused();
        } finally {
            fixture(testInfo, 'clean', data);
        }
    });
});
