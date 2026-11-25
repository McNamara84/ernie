import { expect, type Page } from '@playwright/test';

import { test } from '../fixtures/authenticated';
import { ResourcesPage } from '../helpers/page-objects/ResourcesPage';

const searchPath = '**/api/v1/orcid/search?**';
const people = [
    { orcid: '0000-0002-1825-0097', firstName: 'Holger', lastName: 'Ehrmann', creditName: null, institutions: ['GFZ Potsdam'] },
    { orcid: '0000-0001-5109-3700', firstName: 'Holger', lastName: 'Ehrmann', creditName: null, institutions: ['GFZ', 'Example University'] },
];
const matches = { success: true, data: { results: people, total: people.length } };
const noMatches = { success: true, data: { results: [], total: 0 } };

async function addPerson(page: Page, kind: 'author' | 'contributor') {
    const heading = kind === 'author' ? 'Authors' : 'Contributors';
    const accordion = page.locator('[data-slot="accordion-trigger"]').filter({ hasText: heading }).first();
    await expect(accordion).toBeVisible();
    if ((await accordion.getAttribute('aria-expanded')) !== 'true') await accordion.click();
    await page.getByRole('button', { name: new RegExp(`^Add (first|another) ${kind}$`, 'i') }).click();
    return page.getByTestId(new RegExp(`^${kind}-[0-9]+-orcid-field$`)).last();
}

async function openNewContributor(page: Page) {
    await page.goto('/editor', { waitUntil: 'domcontentloaded' });
    const field = await addPerson(page, 'contributor');
    const trigger = field.getByRole('button', { name: 'Search for ORCID' });
    await trigger.click();
    const dialog = page.getByRole('dialog', { name: 'Search for ORCID' });
    return { field, trigger, dialog, input: dialog.getByRole('searchbox', { name: 'Person name' }) };
}

async function interceptProfiles(page: Page) {
    const fetched: string[] = [];
    await page.route(/\/api\/v1\/orcid\/[\dX-]+$/, async (route) => {
        const id = new URL(route.request().url()).pathname.split('/').pop();
        const person = people.find((candidate) => candidate.orcid === id);
        if (!person) throw new Error('Unexpected ORCID profile request');
        fetched.push(person.orcid);
        await route.fulfill({ json: { success: true, data: { ...person, affiliations: [], emails: [], verifiedAt: '2026-11-25T10:00:00Z' } } });
    });
    return fetched;
}

test('a curator can pause, correct a name and explicitly select an author on an existing resource', async ({ page, context }) => {
    await page.goto('/resources', { waitUntil: 'domcontentloaded' });
    await new ResourcesPage(page).search('10.1234/playwright-published');
    const opened = context.waitForEvent('page');
    await page.getByText('Playwright: Published Resource', { exact: true }).click();
    const editor = await opened;
    await editor.waitForURL((url) => url.pathname === '/editor' && !!url.searchParams.get('resourceId'));
    await expect(editor.getByTestId('resource-info-section')).toBeVisible();
    await expect(editor.getByTestId('editor-loading-modal')).toHaveCount(0);
    const queries: URLSearchParams[] = [];
    const release = Promise.withResolvers<void>();
    await editor.route(searchPath, async (route) => {
        queries.push(new URL(route.request().url()).searchParams);
        await release.promise;
        await route.fulfill({ json: matches });
    });
    const fetched = await interceptProfiles(editor);
    try {
        const field = await addPerson(editor, 'author');
        const trigger = field.getByRole('button', { name: 'Search for ORCID' });
        await trigger.click();
        const dialog = editor.getByRole('dialog', { name: 'Search for ORCID' });
        const input = dialog.getByRole('searchbox');
        await expect(input).toBeFocused();
        // These pauses are part of the typing scenario. Network completion is controlled separately.
        await input.pressSequentially('Ehrmann,', { delay: 90 });
        await editor.waitForTimeout(650);
        await input.pressSequentially(' Holget', { delay: 85 });
        await editor.waitForTimeout(550);
        await input.press('Backspace');
        await input.pressSequentially('r, GFZ', { delay: 105 });
        expect(queries).toHaveLength(0);
        await input.press('Enter');
        await expect(dialog.getByRole('status')).toHaveText(/Searching ORCID/);
        await input.press('Enter');
        release.resolve();
        const first = dialog.getByRole('button', { name: /GFZ Potsdam/ });
        const second = dialog.getByRole('button', { name: /Example University/ });
        await expect(first).toBeVisible();
        expect(queries.map((params) => Object.fromEntries(params))).toEqual([{ q: 'Ehrmann, Holger, GFZ', limit: '20', mode: 'person' }]);
        expect(fetched).toEqual([]);
        await expect(field.getByRole('textbox', { includeHidden: true })).toHaveValue('');
        await input.press('ArrowDown');
        await expect(first).toBeFocused();
        await first.press('ArrowDown');
        await expect(second).toBeFocused();
        await second.press('Enter');
        await expect(dialog).toBeHidden();
        await expect(field.getByRole('textbox')).toHaveValue(people[1].orcid);
        await expect(trigger).toBeFocused();
        expect(fetched).toEqual([people[1].orcid]);
    } finally {
        release.resolve();
        await editor.close();
    }
});

test('opening a contributor profile and accepting a result are independent actions', async ({ page, context }) => {
    await page.route(searchPath, (route) => route.fulfill({ json: matches }));
    await context.route('https://orcid.org/**', (route) => route.fulfill({ contentType: 'text/html', body: '<title>ORCID profile</title>' }));
    const fetched = await interceptProfiles(page);
    const { field, dialog, input } = await openNewContributor(page);
    await input.pressSequentially('Ehrmann, Holger', { delay: 70 });
    await dialog.getByRole('button', { name: 'Search', exact: true }).click();
    const first = dialog.getByRole('button', { name: /GFZ Potsdam/ });
    const profile = dialog.getByRole('link', { name: `View ${people[0].orcid} on ORCID.org` });
    await expect(first).toBeVisible();
    for (const action of ['keyboard', 'click']) {
        if (action === 'keyboard') {
            await input.press('ArrowDown');
            await expect(first).toBeFocused();
            await page.keyboard.press('Tab');
            await expect(profile).toBeFocused();
        }
        const [tab] = await Promise.all([context.waitForEvent('page'), action === 'keyboard' ? profile.press('Enter') : profile.click()]);
        await expect(tab).toHaveURL(`https://orcid.org/${people[0].orcid}`);
        await tab.close();
        await expect(dialog).toBeVisible();
        await expect(field.getByRole('textbox', { includeHidden: true })).toHaveValue('');
        expect(fetched).toEqual([]);
    }
    await first.click();
    await expect(field.getByRole('textbox')).toHaveValue(people[0].orcid);
    expect(fetched).toEqual([people[0].orcid]);
});

test('edits and closing the dialog invalidate pending searches', async ({ page }) => {
    const releases = [Promise.withResolvers<void>(), Promise.withResolvers<void>()];
    const handled = [Promise.withResolvers<void>(), Promise.withResolvers<void>()];
    const queries: string[] = [];
    await page.route(searchPath, async (route) => {
        const term = new URL(route.request().url()).searchParams.get('q')!;
        queries.push(term);
        if (term === 'Ehrmann') {
            await route.fulfill({ json: noMatches });
            return;
        }
        const index = term === 'Taylor' ? 0 : 1;
        await releases[index].promise;
        try {
            await route.fulfill(index === 0 ? { json: matches } : { status: 503, json: { message: 'Unavailable' } });
        } finally {
            handled[index].resolve();
        }
    });
    const { dialog, trigger, input } = await openNewContributor(page);
    try {
        await input.fill('Taylor');
        await input.press('Enter');
        await expect.poll(() => queries).toContain('Taylor');
        await input.fill('Ehrmann');
        await input.press('Enter');
        await expect(dialog.getByRole('status')).toHaveText(/No ORCID records found/);
        releases[0].resolve();
        await handled[0].promise;
        await expect(dialog.getByRole('status')).toHaveText(/No ORCID records found/);
        await expect(dialog.getByRole('list')).toHaveCount(0);
        await input.fill('Curie');
        await input.press('Enter');
        await expect.poll(() => queries).toContain('Curie');
        await input.press('Escape');
        await expect(trigger).toBeFocused();
        await trigger.click();
        releases[1].resolve();
        await handled[1].promise;
        await expect(input).toHaveValue('');
        await expect(dialog.getByRole('status')).toHaveText(/at least two characters/);
        await expect(dialog.getByRole('button', { name: 'Try again' })).toHaveCount(0);
    } finally {
        for (const release of releases) release.resolve();
    }
});

test('distinguishes empty results from rate limiting and retries the same query', async ({ page }) => {
    const terms: string[] = [];
    await page.route(searchPath, (route) => {
        terms.push(new URL(route.request().url()).searchParams.get('q')!);
        if (terms.length === 2) return route.fulfill({ status: 429, json: { message: 'Too many searches. Please try again shortly.' } });
        return route.fulfill({ json: terms.length === 1 ? noMatches : matches });
    });
    const { dialog, input } = await openNewContributor(page);
    await input.fill('Li');
    await input.press('Enter');
    await expect(dialog.getByRole('status')).toHaveText(/No ORCID records found/);
    await input.fill('Ehrmann, Holger');
    await input.press('Enter');
    await expect(dialog.getByRole('status')).toHaveText(/Too many searches/);
    await dialog.getByRole('button', { name: 'Try again' }).click();
    await expect(dialog.getByRole('button', { name: /GFZ Potsdam/ })).toBeVisible();
    await expect(input).toHaveValue('Ehrmann, Holger');
    expect(terms).toEqual(['Li', 'Ehrmann, Holger', 'Ehrmann, Holger']);
});
