import { spawnSync } from 'node:child_process';

import { expect, test, type TestInfo } from '@playwright/test';

import { navigateWithTlsRetry } from '../helpers/navigation';

function fixture(info: TestInfo, action: 'create' | 'cleanup', snapshot?: string): string {
    const phpArgs = ['-d', 'memory_limit=2G', 'tests/playwright/fixtures/data-embargo-state.php', action];
    const docker = ['playwright.devstack.config.ts', 'playwright.docker.config.ts'].some((name) => info.config.configFile?.endsWith(name));
    const result = spawnSync(
        docker ? 'docker' : 'php',
        docker ? ['compose', '--env-file', '.env.docker', '-f', 'docker-compose.dev.yml', 'exec', '-T', 'app', 'php', ...phpArgs] : phpArgs,
        { input: snapshot, encoding: 'utf8', timeout: 90_000 },
    );
    if (result.error || result.status !== 0) throw result.error ?? new Error(result.stderr || 'Embargo fixture failed');
    return result.stdout;
}

test('an anonymous visitor can read real embargo metadata while the primary download stays blocked', async ({ page }, info) => {
    test.setTimeout(240_000);
    const snapshot = fixture(info, 'create');
    const resource = JSON.parse(snapshot) as { id: number; title: string; path: string; available: string; downloadPath: string };
    try {
        const html = await page.request.get(resource.path);
        expect(html.status()).toBe(200);
        expect(await html.text()).not.toContain('embargo-browser-private.zip');
        const metadata = await page.request.get(`${resource.path}/metadata/datacite.json`);
        expect(metadata.status()).toBe(200);
        expect(await metadata.text()).toContain('Embargoed access');
        expect((await page.request.get(resource.downloadPath)).status()).toBe(404);
        await navigateWithTlsRetry(page, resource.path, { waitUntil: 'domcontentloaded' });
        await expect(page.getByRole('heading', { name: resource.title })).toBeVisible();
        await expect(page.getByRole('status').filter({ hasText: `The data is under embargo until ${resource.available}.` })).toBeVisible();
        await expect(page.getByTestId('files-section')).toHaveCount(0);
        await expect(page.getByTestId('data-request-section')).toHaveCount(0);
    } finally {
        fixture(info, 'cleanup', snapshot);
    }
});
