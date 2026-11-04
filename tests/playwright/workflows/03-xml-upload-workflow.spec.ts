import { readFileSync } from 'node:fs';

import { expect, test } from '@playwright/test';
import path from 'path';
import { fileURLToPath } from 'url';

import { warmUpSession } from '../helpers/session-readiness';
import { loginAsTestUser } from '../helpers/test-helpers';

// XML Upload Tests
// Based on working tests from main branch.
// Tests the dashboard confirmation and explicit editor navigation.

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

function resolveDatasetExample(filename: string): string {
    return path.resolve(__dirname, '..', '..', 'pest', 'dataset-examples', filename);
}

test.describe('XML Upload', () => {
    test.beforeEach(async ({ page }) => {
        await loginAsTestUser(page);
        await warmUpSession(page);
    });

    test('uploads XML file, shows confirmation, and opens editor with populated form', async ({ page }) => {
        await expect(page.getByTestId('unified-dropzone')).toBeVisible();

        const fileInput = page.getByTestId('unified-file-input');
        const xmlFilePath = resolveDatasetExample('datacite-xml-example-full-v4.xml');
        const [uploadResponse] = await Promise.all([
            // The full XML fixture performs real parsing and draft persistence;
            // use the same backend budget as the critical XML smoke test.
            page.waitForResponse(
                (response) => response.request().method() === 'POST' && new URL(response.url()).pathname === '/dashboard/upload-xml',
                { timeout: 30000 },
            ),
            fileInput.setInputFiles(xmlFilePath),
        ]);
        expect(uploadResponse.status()).toBe(200);
        const result = await uploadResponse.json();
        expect(result.success).toBe(true);
        expect(Number.isSafeInteger(result.resourceId)).toBe(true);
        expect(result.resourceId).toBeGreaterThan(0);

        await expect(page.getByTestId('dropzone-success-state')).toBeVisible({ timeout: 10000 });
        await expect(page.getByTestId('dropzone-success-alert')).toContainText('DataCite upload complete');
        await expect(page).toHaveURL(/\/dashboard/);

        await page.getByRole('button', { name: /open in editor/i }).click();
        await page.waitForURL(/\/editor/, { timeout: 10000 });

        const currentUrl = page.url();
        expect(currentUrl).toMatch(/resourceId=\d+/);

        const urlParams = new URLSearchParams(currentUrl.split('?')[1] || '');
        const resourceId = urlParams.get('resourceId');
        expect(resourceId).toBeTruthy();
        expect(resourceId).toMatch(/^\d+$/);
        expect(resourceId).toBe(String(result.resourceId));
        // Verify editor page loaded successfully with form fields
        // Check for DOI input field (id="doi"), which is unique and stable
        await expect(page.locator('#doi')).toBeVisible();

        // Verify form has loaded by checking for Year field (has id="year")
        await expect(page.locator('#year')).toBeVisible();
        await expect(page.locator('#year')).toHaveValue('2009');
        await expect(page.getByTestId('main-title-input')).toHaveValue('Test Dataset Software');
    });

    test('handles invalid XML files gracefully', async ({ page }) => {
        await expect(page.getByTestId('unified-dropzone')).toBeVisible();

        const fileInput = page.getByTestId('unified-file-input');

        // Create temporary invalid XML file
        const invalidXml = '<invalid>Not a proper DataCite XML</invalid>';
        const buffer = Buffer.from(invalidXml, 'utf-8');

        const [uploadResponse] = await Promise.all([
            page.waitForResponse(
                (response) => response.request().method() === 'POST' && new URL(response.url()).pathname === '/dashboard/upload-xml',
            ),
            fileInput.setInputFiles({ name: 'invalid.xml', mimeType: 'application/xml', buffer }),
        ]);
        expect(uploadResponse.status()).toBe(422);
        const result = await uploadResponse.json();
        expect(result.success).toBe(false);
        expect(result.error.code).toBeTruthy();
        await expect(page.getByTestId('dropzone-error-state')).toBeVisible();
        await expect(page.getByTestId('dropzone-error-alert')).toContainText(result.message);
        await expect(page).toHaveURL(/\/dashboard/);
    });

    for (const format of ['xml', 'json'] as const) {
        test('retains instrument names and PIDs after local resolution, save and reload (issue 1498, ' + format + ')', async ({ page }) => {
            // Fixed local-registry responses isolate this browser workflow from
            // the installation's vocabulary. Pest covers real import/resolution.
            const snapshot = JSON.parse(readFileSync(path.resolve(__dirname, '../../pest/Fixtures/pid4inst/issue-1498.json'), 'utf8'));
            const records = snapshot.data as {
                pid: string;
                pidType: string;
                name: string;
                lookupIdentifiers?: { value: string; type: string; source: string }[];
            }[];
            await page.route('**/editor/instruments/resolve', async (route) => {
                const submitted = route.request().postDataJSON().instruments as { pid: string; pidType: string; name: string }[];
                await route.fulfill({
                    json: {
                        instruments: submitted.map((instrument) => {
                            const record = records.find((entry) => entry.pid === instrument.pid)!;
                            return {
                                ...instrument,
                                name: record.name,
                                resolution: record.lookupIdentifiers
                                    ? { status: 'identifier_conflict', identifiers: record.lookupIdentifiers }
                                    : { status: 'matched' },
                            };
                        }),
                    },
                });
            });
            await page.route('**/api/v1/vocabularies/pid-availability', (route) => route.fulfill({ json: { pid4inst: { available: true } } }));
            await page.route('**/vocabularies/pid4inst-instruments', (route) => route.fulfill({ json: snapshot }));
            const file =
                format === 'xml'
                    ? resolveDatasetExample('issue-1498-used-instruments.xml')
                    : {
                          name: 'issue1498.json',
                          mimeType: 'application/json',
                          buffer: Buffer.from(
                              JSON.stringify({
                                  data: {
                                      attributes: {
                                          titles: [{ title: 'Instrument import regression' }],
                                          creators: [{ name: 'Example, Author' }],
                                          publisher: { name: 'Test Publisher' },
                                          publicationYear: 2026,
                                          types: { resourceTypeGeneral: 'Dataset' },
                                          relatedIdentifiers: records.map((record) => ({
                                              relatedIdentifier: record.pid,
                                              relatedIdentifierType: 'Handle',
                                              relationType: 'IsCollectedBy',
                                          })),
                                      },
                                  },
                              }),
                          ),
                      };
            const upload = page.waitForResponse(
                (response) => response.request().method() === 'POST' && new URL(response.url()).pathname === '/dashboard/upload-' + format,
            );
            await page.getByTestId('unified-file-input').setInputFiles(file);
            expect((await upload).status()).toBe(200);
            await page.getByRole('button', { name: /open in editor/i }).click();
            await page.waitForURL(/\/editor\?resourceId=\d+/);
            for (let cycle = 0; cycle < 2; cycle++) {
                const trigger = page.getByTestId('used-instruments-accordion-trigger');
                if ((await trigger.getAttribute('aria-expanded')) !== 'true') await trigger.click();
                const section = page.getByTestId('used-instruments-section');
                if (cycle === 1) {
                    for (const record of records) await expect(section.getByRole('heading', { name: record.name, exact: true })).toBeVisible();
                }
                // The availability notice disappears when loading finishes and moves
                // the resolve button. Wait for the fixture before clicking it.
                await expect(section.getByRole('combobox', { name: 'Add Instrument' })).toBeEnabled();
                const resolution = page.waitForResponse(
                    (response) => response.request().method() === 'POST' && new URL(response.url()).pathname === '/editor/instruments/resolve',
                );
                // Other editor sections can still shift this button during a pointer
                // click. Native keyboard activation keeps this data workflow stable.
                const resolveButton = section.getByRole('button', { name: 'Resolve names again' });
                await expect(resolveButton).toBeEnabled();
                await resolveButton.press('Enter');
                expect((await resolution).status()).toBe(200);
                for (const record of records) {
                    await expect(section.getByRole('heading', { name: record.name, exact: true })).toBeVisible();
                    await expect(section.getByRole('link', { name: 'Handle: ' + record.pid })).toHaveAttribute(
                        'href',
                        'https://hdl.handle.net/' + record.pid,
                    );
                }
                await expect(
                    section.getByText('B2INST lists different identifiers for this instrument. The imported PID has been retained.'),
                ).toBeVisible();
                if (cycle === 0) {
                    const saved = page.waitForResponse(
                        (response) => response.request().method() === 'POST' && new URL(response.url()).pathname === '/editor/resources/draft',
                    );
                    await page.getByTestId('save-draft-button').click();
                    const response = await saved;
                    expect(response.status()).toBe(200);
                    expect(
                        response
                            .request()
                            .postDataJSON()
                            .instruments.map((instrument: { pid: string }) => instrument.pid),
                    ).toEqual(records.map((record) => record.pid));
                    await page.reload();
                }
            }
        });
    }

    for (const format of ['xml', 'json'] as const) {
        test(`keeps standalone contact contributors after ${format} upload, draft save and reload (issue 1496)`, async ({ page }) => {
            const author = { name: 'Author, Anna', nameType: 'Personal', givenName: 'Anna', familyName: 'Author' };
            const contact = { name: 'Contact, Chris', nameType: 'Personal', givenName: 'Chris', familyName: 'Contact' };
            const file =
                format === 'xml'
                    ? resolveDatasetExample('issue-1496-contact-persons.xml')
                    : {
                          name: 'issue-1496.json',
                          mimeType: 'application/json',
                          buffer: Buffer.from(
                              JSON.stringify({
                                  data: {
                                      attributes: {
                                          titles: [{ title: 'Issue 1496 contact persons' }],
                                          creators: [author],
                                          contributors: [
                                              { ...author, contributorType: 'ContactPerson' },
                                              { ...contact, contributorType: 'ContactPerson' },
                                          ],
                                          publicationYear: '2026',
                                          publisher: { name: 'GFZ Data Services' },
                                          types: { resourceTypeGeneral: 'Dataset' },
                                      },
                                  },
                              }),
                          ),
                      };
            const [upload] = await Promise.all([
                page.waitForResponse(
                    (response) => response.request().method() === 'POST' && new URL(response.url()).pathname === `/dashboard/upload-${format}`,
                ),
                page.getByTestId('unified-file-input').setInputFiles(file),
            ]);
            expect(upload.status()).toBe(200);
            await page.getByRole('button', { name: /open in editor/i }).click();
            await page.waitForURL(/\/editor\?resourceId=\d+/);

            for (let cycle = 0; cycle < 2; cycle++) {
                for (const section of ['Authors', 'Contributors']) {
                    const trigger = page.locator('[data-slot="accordion-trigger"]', { hasText: new RegExp(section, 'i') }).first();
                    if ((await trigger.getAttribute('aria-expanded')) !== 'true') {
                        await trigger.click();
                    }
                }
                await expect(page.getByTestId('author-0-fields-grid').getByLabel('First name')).toHaveValue('Anna');
                await expect(page.getByTestId('author-1-fields-grid')).toHaveCount(0);
                await expect(page.getByTestId('author-0-contact-field').getByRole('checkbox')).toBeChecked();
                await expect(page.getByTestId('contributor-0-roles-input')).toHaveValue('Contact Person');
                await expect(page.getByTestId('contributor-1-roles-input')).toHaveCount(0);
                const contributors = page.getByRole('region', { name: /^Contributors/ });
                await expect(contributors.getByLabel('First name', { exact: true })).toHaveValue('Chris');
                await expect(contributors.getByRole('textbox', { name: /^Email/ })).toHaveValue(format === 'xml' ? 'chris@example.org' : '');
                if (format === 'xml') {
                    await expect(contributors.getByLabel('Website', { exact: true })).toHaveValue('https://example.org/chris');
                }
                if (cycle === 0) {
                    const saved = page.waitForResponse(
                        (response) => response.request().method() === 'POST' && new URL(response.url()).pathname === '/editor/resources/draft',
                    );
                    await page.getByTestId('save-draft-button').click();
                    expect((await saved).status()).toBe(200);
                    await page.reload();
                }
            }
        });
    }
});
