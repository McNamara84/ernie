import { execFileSync } from 'node:child_process';
import { randomUUID } from 'node:crypto';

import { expect, type TestInfo } from '@playwright/test';

import { test } from '../fixtures/authenticated';
import { LandingPage } from '../helpers/page-objects/LandingPage';

function fixture(info: TestInfo, action: 'create' | 'clean', id: string) {
    const docker = /playwright\.(devstack|docker)\.config\.ts$/.test(info.config.configFile ?? '');
    const args = ['-d', 'memory_limit=2G', 'tests/playwright/fixtures/citation-label-review.php', action, id];
    return execFileSync(
        docker ? 'docker' : 'php',
        docker ? ['compose', '--env-file', '.env.docker', '-f', 'docker-compose.dev.yml', 'exec', '-T', 'app', 'php', ...args] : args,
        { encoding: 'utf8', timeout: 120_000 },
    );
}

test('resumes a rate-limited review and displays and copies the approved APA citation on the public page', async ({ page }, info) => {
    test.setTimeout(240_000);
    const tag = randomUUID();
    try {
        const data: { id: number; row: number; doi: string; path: string; old: string } = JSON.parse(fixture(info, 'create', tag));
        const landing = new LandingPage(page);
        await landing.installCitationClipboardStub();
        await page.goto(data.path);
        const row = page.getByTestId('related-work-entry-' + data.row);
        await expect(row.getByRole('link')).toHaveText(data.old);
        await page.goto('/resources?search=' + encodeURIComponent(tag));
        await page.getByTestId('resources-row-checkbox-' + data.id).click();
        await page.getByTestId('resources-actions-menu-trigger').click();
        await page.route(
            '**/resources/citation-labels/preview',
            (route) => route.fulfill({ status: 429, headers: { 'Retry-After': '1' }, json: { message: 'Too Many Attempts.' } }),
            { times: 1 },
        );
        await page.getByTestId('resources-action-review-citation-labels').click();
        const dialog = page.getByRole('dialog', { name: 'Review citation labels' });
        await expect(dialog.getByText(data.old)).toBeVisible();
        const selectAll = dialog.getByRole('checkbox', { name: 'Select all' });
        await dialog.getByRole('checkbox', { name: /Journal article/ }).press('Space');
        await expect(selectAll).toBeChecked();
        await dialog.getByRole('button', { name: /Apply selected/ }).click();
        await expect(dialog.getByRole('status')).toHaveText('Applied');
        await expect(selectAll).toBeDisabled();
        await dialog.getByRole('button', { name: 'Close', exact: true }).first().click();
        await page.goto(data.path);
        const text = 'Frank, H. S. (1970). The structure of ordinary water. Science, 169(3946), 635–641. https://doi.org/' + data.doi;
        await expect(row.getByRole('link')).toHaveText(text);
        await expect(row.locator('em')).toHaveText(['Science', '169']);
        await row.getByRole('button', { name: 'Copy citation to clipboard' }).click();
        await expect.poll(() => landing.copiedCitationText()).toBe(text);
    } finally {
        fixture(info, 'clean', tag);
    }
});
