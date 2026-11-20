import { expect, type Locator, type Page } from '@playwright/test';

import { test } from '../fixtures/authenticated';

type StoredWork = { identifier: string; citation_label: string | null };

async function expectIdentifiers(inputs: Locator, expected: string[]) {
    await expect(inputs).toHaveCount(expected.length);
    await expect.poll(() => inputs.evaluateAll((elements) => elements.map((element) => (element as HTMLInputElement).value))).toEqual(expected);
}

async function openSection(page: Page) {
    const section = page.getByTestId('related-work-section');
    const trigger = section.getByTestId('related-work-accordion-trigger');
    if ((await trigger.getAttribute('aria-expanded')) !== 'true') await trigger.click();
    return section;
}

async function openSaved(page: Page, resourceId: number): Promise<StoredWork[]> {
    const loaded = page.waitForResponse(
        (response) => new URL(response.url()).pathname === '/editor' && Boolean(response.request().headers()['x-editor-load-token']),
    );
    await page.goto(`/editor?resourceId=${resourceId}`);
    const payload = await (await loaded).json();
    await expect(page.getByTestId('main-title-input')).toBeVisible({ timeout: 60_000 });
    return payload.props.relatedWorks;
}

async function saveDraft(page: Page) {
    const saved = page.waitForResponse(
        (response) =>
            new URL(response.url()).pathname === '/editor/resources/draft' &&
            response.request().method() === 'POST' &&
            response.request().postDataJSON().intent === 'save-draft',
    );
    await page.getByTestId('save-draft-button').click();
    const response = await saved;
    expect(response.ok()).toBeTruthy();
    await expect(page.getByTestId('save-draft-button')).toBeEnabled();
    return { data: await response.json(), submitted: response.request().postDataJSON() };
}

test('preserves insertion order through saves, displays stored labels on reopen, and edits the correct rows', async ({ page }) => {
    test.setTimeout(240_000);
    await page.goto('/editor');
    await expect(page.getByTestId('main-title-input')).toBeVisible({ timeout: 60_000 });
    await page.getByTestId('main-title-input').fill(`Related work ordering ${Date.now()}`);
    const section = await openSection(page);
    const initial = [
        { identifier: 'ark:/12345/z', label: 'Zulu' },
        { identifier: 'ark:/12345/ten', label: 'Work 10' },
        { identifier: 'ark:/12345/empty', label: '' },
        { identifier: 'ark:/12345/a', label: 'Alpha' },
        { identifier: 'ark:/12345/two', label: 'Work 2' },
    ];
    const inputs = section.getByTestId(/related-work-\d+-identifier-input/);
    for (const [index, entry] of initial.entries()) {
        await section.getByRole('button', { name: 'Add Related Work', exact: true }).click();
        await inputs.nth(index).fill(entry.identifier);
        if (entry.label) await section.getByLabel('Citation label', { exact: true }).nth(index).fill(entry.label);
    }
    const originalOrder = initial.map(({ identifier }) => identifier);
    const firstSave = await saveDraft(page);
    expect(firstSave.submitted.relatedIdentifiers.map((work: { identifier: string }) => work.identifier)).toEqual(originalOrder);
    await expectIdentifiers(inputs, originalOrder);

    const resourceId = firstSave.data.resource.id;
    expect((await openSaved(page, resourceId)).map(({ identifier }) => identifier)).toEqual(originalOrder);
    await openSection(page);
    await expectIdentifiers(inputs, [originalOrder[2], originalOrder[3], originalOrder[4], originalOrder[1], originalOrder[0]]);
    await expect(section.getByRole('button', { name: /Reorder related work/ })).toHaveCount(0);
    await section.getByLabel('Citation label', { exact: true }).nth(1).fill('ZZZ edited');
    await section.getByRole('listitem').nth(3).getByRole('button', { name: 'Remove related work', exact: true }).click();

    await section.getByRole('button', { name: 'Import from CSV', exact: true }).click();
    await section.locator('#csv-upload').setInputFiles({
        name: 'related-works.csv',
        mimeType: 'text/csv',
        buffer: Buffer.from('identifier,identifier_type,relation_type\nark:/12345/new-z,ARK,References\nark:/12345/new-a,ARK,Cites'),
    });
    await section.getByRole('button', { name: /Import 2/ }).click();
    const sessionOrder = [originalOrder[2], originalOrder[3], originalOrder[4], originalOrder[0], 'ark:/12345/new-z', 'ark:/12345/new-a'];
    await expectIdentifiers(inputs, sessionOrder);
    const trigger = section.getByTestId('related-work-accordion-trigger');
    await trigger.click();
    await trigger.click();
    await expectIdentifiers(inputs, sessionOrder);

    const secondSave = await saveDraft(page);
    const expectedStoredOrder = [originalOrder[0], originalOrder[2], originalOrder[3], originalOrder[4], 'ark:/12345/new-z', 'ark:/12345/new-a'];
    expect(secondSave.submitted.relatedIdentifiers.map((work: { identifier: string }) => work.identifier)).toEqual(expectedStoredOrder);
    const stored = await openSaved(page, resourceId);
    expect(stored.map(({ identifier }) => identifier)).toEqual(expectedStoredOrder);
    expect(stored.find(({ identifier }) => identifier === originalOrder[3])?.citation_label).toBe('ZZZ edited');
    await openSection(page);
    await expectIdentifiers(inputs, [originalOrder[2], 'ark:/12345/new-z', 'ark:/12345/new-a', originalOrder[4], originalOrder[0], originalOrder[3]]);
});

for (const format of ['xml', 'json'] as const) {
    test(`sorts a saved ${format.toUpperCase()} upload on its first editor opening`, async ({ page }) => {
        test.setTimeout(240_000);
        const title = `Uploaded related works ${format} ${Date.now()}`;
        const records = [
            { relatedIdentifier: 'ark:/12345/z', relatedIdentifierType: 'ARK', relationType: 'Cites' },
            { relatedIdentifier: 'ark:/12345/a', relatedIdentifierType: 'ARK', relationType: 'References' },
            { relatedIdentifier: 'ark:/12345/empty', relatedIdentifierType: 'ARK', relationType: 'Documents' },
        ];
        await page.goto('/dashboard');
        const uploaded = page.waitForResponse(
            (response) => new URL(response.url()).pathname === `/dashboard/upload-${format}` && response.request().method() === 'POST',
        );
        await page.getByTestId('unified-file-input').setInputFiles({
            name: `works.${format}`,
            mimeType: format === 'xml' ? 'application/xml' : 'application/json',
            buffer: Buffer.from(
                format === 'xml'
                    ? `<?xml version="1.0"?><resource xmlns="http://datacite.org/schema/kernel-4"><titles><title>${title}</title></titles><creators><creator><creatorName>Example, Author</creatorName></creator></creators><publicationYear>2026</publicationYear><relatedIdentifiers>${records.map((record) => `<relatedIdentifier relatedIdentifierType="${record.relatedIdentifierType}" relationType="${record.relationType}">${record.relatedIdentifier}</relatedIdentifier>`).join('')}</relatedIdentifiers></resource>`
                    : JSON.stringify({
                          data: {
                              attributes: {
                                  titles: [{ title }],
                                  creators: [{ name: 'Example, Author', nameType: 'Personal' }],
                                  publisher: { name: 'Playwright Publisher' },
                                  publicationYear: '2026',
                                  types: { resourceTypeGeneral: 'Dataset', resourceType: 'Dataset' },
                                  relatedIdentifiers: records,
                              },
                          },
                      }),
            ),
        });
        const response = await uploaded;
        expect(response.ok()).toBeTruthy();
        const { resourceId } = await response.json();
        // Arrange persisted labels through the real draft endpoint, avoiding external citation lookups.
        const token = await page.locator('meta[name="csrf-token"]').getAttribute('content');
        const labeled = await page.request.post('/editor/resources/draft', {
            headers: { 'X-CSRF-TOKEN': token!, Accept: 'application/json' },
            data: {
                resourceId,
                intent: 'save-draft',
                titles: [{ title, titleType: 'main-title' }],
                relatedIdentifiers: records.map((record, index) => ({
                    identifier: record.relatedIdentifier,
                    identifierType: record.relatedIdentifierType,
                    relationType: record.relationType,
                    ...(index < 2 ? { citationLabel: index === 0 ? 'Zulu' : 'Alpha' } : {}),
                })),
            },
        });
        expect(labeled.ok()).toBeTruthy();
        await page.getByRole('button', { name: /Open in Editor/i }).click();
        await expect(page.getByTestId('main-title-input')).toHaveValue(title, { timeout: 60_000 });
        const section = await openSection(page);
        await expectIdentifiers(section.getByTestId(/related-work-\d+-identifier-input/), [
            records[2].relatedIdentifier,
            records[1].relatedIdentifier,
            records[0].relatedIdentifier,
        ]);
        const saved = await saveDraft(page);
        expect(saved.submitted.relatedIdentifiers.map((work: { identifier: string }) => work.identifier)).toEqual(
            records.map(({ relatedIdentifier }) => relatedIdentifier),
        );
    });
}
