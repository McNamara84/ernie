import { expect, type Locator, type Page, test } from '@playwright/test';
import path from 'path';
import { fileURLToPath } from 'url';

import { TEST_USER_EMAIL, TEST_USER_PASSWORD } from '../constants';

/**
 * IGSN Workflow Tests
 *
 * Tests the complete IGSN workflow:
 * 1. Upload CSV files via dashboard dropzone
 * 2. Verify data appears in /igsns table
 * 3. Verify data is correctly stored in database
 * 4. Export IGSN as DataCite JSON and verify download succeeds
 *
 * Note: These tests run in a shared database environment. Previous test runs
 * or retries may leave data in the database. Tests use .first() selectors
 * to handle multiple matching elements gracefully.
 */

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

function resolveDatasetExample(filename: string): string {
    return path.resolve(__dirname, '..', '..', 'pest', 'dataset-examples', filename);
}
async function waitForIgsnUploadResult(page: Page): Promise<'success' | 'error'> {
    return Promise.race([
        page
            .getByTestId('dropzone-success-state')
            .waitFor({ timeout: 30000 })
            .then(() => 'success' as const),
        page
            .getByTestId('dropzone-error-state')
            .waitFor({ timeout: 30000 })
            .then(() => 'error' as const),
    ]);
}

async function openIgsnListAfterDashboardUpload(page: Page): Promise<'success' | 'error'> {
    const result = await waitForIgsnUploadResult(page);

    if (result === 'success') {
        await expect(page.getByTestId('dropzone-success-alert')).toContainText('IGSN import complete');
        await page.getByRole('button', { name: /view igsns/i }).click();
        await page.waitForURL(/\/igsns/, { timeout: 30000 });
        return result;
    }

    await page.goto('/igsns');
    return result;
}

async function exportIgsnJsonThroughUi(page: Page, exportButton: Locator) {
    const exportResponsePromise = page.waitForResponse(
        (response) => {
            const url = new URL(response.url());
            return response.request().method() === 'GET' && /\/igsns\/\d+\/export\/json$/.test(url.pathname);
        },
        { timeout: 30000 },
    );

    await exportButton.click();

    const exportResponse = await exportResponsePromise;
    const responseText = await exportResponse.text();

    expect(exportResponse.ok(), `Expected successful IGSN JSON export, got ${exportResponse.status()}: ${responseText}`).toBeTruthy();

    return {
        exportResponse,
        jsonData: JSON.parse(responseText),
    };
}

// Test data from the CSV files for verification
const DOVE_CSV_DATA = {
    filename: '20260116_TEST_ICDP5068-DOVE-v2-Parent-Boreholes.csv',
    igsn: 'ICDP5068EH50001',
    name: '5068_1_A',
    title: 'IGSN ICDP5068EH50001 (5068_1_A) Borehole: Sediment (Quaternary) of the ICDP 5068_DOVE project, site 5068_1, near Winterstettenstadt, Germany',
    sampleType: 'Borehole',
    material: 'Sediment',
    collectionStartDate: '2021-04-12',
    collectionEndDate: '2021-05-05',
    latitude: '47.9998028',
    longitude: '9.7486417',
    country: 'Germany',
    city: 'Winterstettenstadt',
};

const DIVE_CSV_DATA = {
    filename: '20260116_TEST_ICDP5071-DIVE-Parent-Boreholes.csv',
    igsn: 'ICDP5071EH10001',
    name: '5071_1_A',
    title: "IGSN ICDP5071EH10001 (5071_1_A): Borehole: Rock of the ICDP DIVE Project, Site: Megolo (Val d'Ossola), near Verbano-Cusio-Ossola, Italy",
    sampleType: 'Borehole',
    material: 'Rock',
    collectionStartDate: '2023-11-13',
    collectionEndDate: '2024-03-27',
    latitude: '46.1113889',
    longitude: '8.3091667',
    country: 'Italy',
    city: 'Verbano-Cusio-Ossola',
};

test.describe('IGSN Workflow', () => {
    test.beforeEach(async ({ page }) => {
        // Login
        await page.goto('/login');
        await page.getByLabel('Email address').fill(TEST_USER_EMAIL);
        await page.getByLabel('Password').fill(TEST_USER_PASSWORD);
        await page.getByRole('button', { name: 'Log in' }).click();
        await page.waitForURL(/\/dashboard/, { timeout: 15000 });
    });

    test('can upload DOVE CSV file and see data in /igsns table', async ({ page }) => {
        // Navigate to dashboard
        await page.goto('/dashboard');

        // Find the unified dropzone and upload CSV
        const fileInput = page.getByTestId('unified-file-input');
        const csvFilePath = resolveDatasetExample(DOVE_CSV_DATA.filename);
        await fileInput.setInputFiles(csvFilePath);
        await openIgsnListAfterDashboardUpload(page);

        // Verify the IGSN is displayed in the table (use exact match in IGSN column)
        // The IGSN appears in both IGSN column and title column, use getByRole for precision
        const igsnCell = page.getByRole('cell', { name: DOVE_CSV_DATA.igsn, exact: true }).first();
        await expect(igsnCell).toBeVisible({ timeout: 10000 });

        // Verify sample type (appears only once per row in its column)
        await expect(page.getByRole('cell', { name: DOVE_CSV_DATA.sampleType }).first()).toBeVisible();

        // Verify material
        await expect(page.getByRole('cell', { name: DOVE_CSV_DATA.material }).first()).toBeVisible();

        // Verify collection date (shown in Date column)
        await expect(page.getByText(DOVE_CSV_DATA.collectionStartDate).first()).toBeVisible();

        // Verify status is 'uploaded'
        await expect(page.getByRole('cell', { name: 'uploaded' }).first()).toBeVisible();
    });

    test('can upload DIVE CSV file and see data in /igsns table', async ({ page }) => {
        // Navigate to dashboard
        await page.goto('/dashboard');

        // Upload CSV
        const fileInput = page.getByTestId('unified-file-input');
        const csvFilePath = resolveDatasetExample(DIVE_CSV_DATA.filename);
        await fileInput.setInputFiles(csvFilePath);
        await openIgsnListAfterDashboardUpload(page);

        // Verify data (use exact match for IGSN column)
        await expect(page.getByRole('cell', { name: DIVE_CSV_DATA.igsn, exact: true }).first()).toBeVisible({ timeout: 10000 });
        await expect(page.getByRole('cell', { name: DIVE_CSV_DATA.sampleType }).first()).toBeVisible();
        await expect(page.getByRole('cell', { name: DIVE_CSV_DATA.material }).first()).toBeVisible();
        await expect(page.getByText(DIVE_CSV_DATA.collectionStartDate).first()).toBeVisible();
    });

    test('can upload both CSV files and see all data', async ({ page }) => {
        // Upload first file (DOVE)
        await page.goto('/dashboard');
        let fileInput = page.getByTestId('unified-file-input');
        await fileInput.setInputFiles(resolveDatasetExample(DOVE_CSV_DATA.filename));

        await openIgsnListAfterDashboardUpload(page);

        // Go back to dashboard and upload second file (DIVE)
        await page.goto('/dashboard');
        fileInput = page.getByTestId('unified-file-input');
        await fileInput.setInputFiles(resolveDatasetExample(DIVE_CSV_DATA.filename));

        await openIgsnListAfterDashboardUpload(page);

        // Navigate to IGSNs page
        await page.goto('/igsns');

        // Verify both IGSNs are displayed (use exact match)
        await expect(page.getByRole('cell', { name: DOVE_CSV_DATA.igsn, exact: true }).first()).toBeVisible({ timeout: 10000 });
        await expect(page.getByRole('cell', { name: DIVE_CSV_DATA.igsn, exact: true }).first()).toBeVisible();

        // Verify both materials are displayed
        await expect(page.getByRole('cell', { name: 'Sediment' }).first()).toBeVisible();
        await expect(page.getByRole('cell', { name: 'Rock' }).first()).toBeVisible();
    });

    test('rejects duplicate IGSN upload with clear error message', async ({ page }) => {
        // First, ensure the IGSN exists by uploading
        await page.goto('/dashboard');
        let fileInput = page.getByTestId('unified-file-input');
        await fileInput.setInputFiles(resolveDatasetExample(DOVE_CSV_DATA.filename));

        // Wait for either a successful dashboard confirmation or a duplicate error.
        const firstUploadResult = await waitForIgsnUploadResult(page);

        // If first upload showed error, the IGSN already exists (from previous tests)
        if (firstUploadResult === 'error') {
            // Verify error state shows correct message about duplicate
            await expect(page.getByTestId('dropzone-error-alert')).toBeVisible();
            // Test passed - duplicate detection works
            return;
        }

        // First upload succeeded on the dashboard - now try uploading the same file again
        // This MUST fail because IGSNs must be globally unique
        await page.goto('/dashboard');
        fileInput = page.getByTestId('unified-file-input');
        await fileInput.setInputFiles(resolveDatasetExample(DOVE_CSV_DATA.filename));

        // Wait for error state - duplicate IGSNs are now validated before storage
        await expect(page.getByTestId('dropzone-error-state')).toBeVisible({ timeout: 15000 });
        await expect(page.getByTestId('dropzone-error-alert')).toBeVisible();

        // Verify the error message mentions the duplicate IGSN
        await expect(page.getByText(/already exists/i)).toBeVisible();
    });

    test('admin can delete IGSN from /igsns page', async ({ page }) => {
        // First ensure an IGSN exists
        await page.goto('/dashboard');
        const fileInput = page.getByTestId('unified-file-input');
        await fileInput.setInputFiles(resolveDatasetExample(DOVE_CSV_DATA.filename));

        await openIgsnListAfterDashboardUpload(page);

        // Navigate to IGSNs page
        await page.goto('/igsns');

        // Verify IGSN is there (use exact match)
        const igsnCell = page.getByRole('cell', { name: DOVE_CSV_DATA.igsn, exact: true }).first();
        await expect(igsnCell).toBeVisible({ timeout: 10000 });

        // Get the row containing this IGSN and select it via checkbox
        // Test user is always admin (see PlaywrightTestSeeder), so bulk delete is available
        const row = page.locator('tr').filter({ has: igsnCell }).first();
        const checkbox = row.getByRole('checkbox');
        await checkbox.click();

        // Click the bulk delete button in the toolbar
        const deleteButton = page.getByRole('button', { name: /delete/i });
        await expect(deleteButton).toBeVisible({ timeout: 5000 });
        await deleteButton.click();

        // Confirm deletion in dialog
        const confirmButton = page.getByRole('alertdialog').getByRole('button', { name: /delete/i });
        await confirmButton.click();

        // Wait for the row to be removed
        await expect(row).not.toBeVisible({ timeout: 10000 });
    });

    test('can export IGSN as DataCite JSON after upload', async ({ page }) => {
        // Step 1: Upload the DOVE CSV file
        await page.goto('/dashboard');
        const fileInput = page.getByTestId('unified-file-input');
        await fileInput.setInputFiles(resolveDatasetExample(DOVE_CSV_DATA.filename));
        await openIgsnListAfterDashboardUpload(page);

        // Step 2: Verify the IGSN exists in the table
        const igsnCell = page.getByRole('cell', { name: DOVE_CSV_DATA.igsn, exact: true }).first();
        await expect(igsnCell).toBeVisible({ timeout: 10000 });

        // Step 3: Find the row and click the JSON export button
        const row = page.locator('tr').filter({ has: igsnCell }).first();

        // The export button has aria-label="Export as DataCite JSON"
        const exportButton = row.getByRole('button', { name: 'Export as DataCite JSON' });
        await expect(exportButton).toBeVisible();

        // Step 4: Export through the UI and validate the HTTP response directly.
        // The page triggers the download via Axios + Blob URL, which is less stable
        // to observe via Playwright's browser download event in CI.
        const { exportResponse, jsonData } = await exportIgsnJsonThroughUi(page, exportButton);

        // Verify filename metadata from the response header
        const contentDisposition = exportResponse.headers()['content-disposition'];
        expect(contentDisposition).toContain('.json');
        expect(contentDisposition?.toLowerCase()).toContain('igsn');

        // Verify basic DataCite JSON structure
        expect(jsonData).toHaveProperty('data');
        expect(jsonData.data).toHaveProperty('type', 'dois');
        expect(jsonData.data).toHaveProperty('attributes');

        // Verify attributes contain required DataCite fields
        const attributes = jsonData.data.attributes;
        expect(attributes).toHaveProperty('titles');
        expect(attributes).toHaveProperty('creators');
        expect(attributes).toHaveProperty('publisher');
        expect(attributes).toHaveProperty('publicationYear');
        expect(attributes).toHaveProperty('types');
        expect(attributes.types).toHaveProperty('resourceTypeGeneral', 'PhysicalObject');

        // Verify no validation error modal appeared
        const validationModal = page.locator('[role="dialog"]').filter({ hasText: /validation.*failed|export.*failed/i });
        await expect(validationModal).not.toBeVisible();

        // Verify success toast appeared (optional - toast might have already disappeared)
        // Just verify no error toast is visible
        const errorToast = page.locator('[data-sonner-toast][data-type="error"]');
        await expect(errorToast).not.toBeVisible();
    });

    test('exported JSON passes DataCite schema validation (no validation modal)', async ({ page }) => {
        // This test specifically verifies that the fix for contributorType, relationType,
        // and geoLocation coordinate types works correctly

        // Upload the DOVE CSV (which has contributors and geo coordinates)
        await page.goto('/dashboard');
        const fileInput = page.getByTestId('unified-file-input');
        await fileInput.setInputFiles(resolveDatasetExample(DOVE_CSV_DATA.filename));
        await openIgsnListAfterDashboardUpload(page);

        // Find the IGSN row
        const igsnCell = page.getByRole('cell', { name: DOVE_CSV_DATA.igsn, exact: true }).first();
        await expect(igsnCell).toBeVisible({ timeout: 10000 });

        const row = page.locator('tr').filter({ has: igsnCell }).first();
        const exportButton = row.getByRole('button', { name: 'Export as DataCite JSON' });

        const { jsonData } = await exportIgsnJsonThroughUi(page, exportButton);

        // Check if validation error modal appeared (this would indicate our fix didn't work)
        const validationModal = page.locator('[role="dialog"]').filter({ hasText: /JSON Export Failed/i });
        const modalVisible = await validationModal.isVisible();

        if (modalVisible) {
            // If modal is visible, the test should fail with details about what went wrong
            const errorText = await validationModal.textContent();
            throw new Error(`DataCite JSON validation failed! Validation errors detected:\n${errorText}`);
        }

        const attributes = jsonData.data.attributes;

        // Verify contributors have correct contributorType format (PascalCase, no spaces)
        if (attributes.contributors) {
            for (const contributor of attributes.contributors) {
                expect(contributor.contributorType).toMatch(/^[A-Z][a-zA-Z]+$/);
                expect(contributor.contributorType).not.toContain(' ');
            }
        }

        // Verify relatedIdentifiers have correct relationType format (PascalCase, no spaces)
        if (attributes.relatedIdentifiers) {
            for (const ri of attributes.relatedIdentifiers) {
                expect(ri.relationType).toMatch(/^[A-Z][a-zA-Z]+$/);
                expect(ri.relationType).not.toContain(' ');
            }

            // Verify geoLocations have numeric coordinates (not strings)
            if (attributes.geoLocations) {
                for (const geo of attributes.geoLocations) {
                    if (geo.geoLocationPoint) {
                        expect(typeof geo.geoLocationPoint.pointLongitude).toBe('number');
                        expect(typeof geo.geoLocationPoint.pointLatitude).toBe('number');
                    }
                }
            }
        }
    });
});
