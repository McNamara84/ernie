import { expect, test } from '@playwright/test';
import path from 'path';
import { fileURLToPath } from 'url';

import { TEST_USER_EMAIL, TEST_USER_PASSWORD } from '../constants';

/**
 * IGSN CSV Upload and List Tests
 *
 * Tests the complete IGSN workflow:
 * 1. Upload CSV files via dashboard dropzone
 * 2. Verify data appears in /igsns table
 * 3. Verify data is correctly stored in database
 */

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

function resolveDatasetExample(filename: string): string {
    return path.resolve(__dirname, '..', '..', 'pest', 'dataset-examples', filename);
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
    title: 'IGSN ICDP5071EH10001 (5071_1_A): Borehole: Rock of the ICDP DIVE Project, Site: Megolo (Val d\'Ossola), near Verbano-Cusio-Ossola, Italy',
    sampleType: 'Borehole',
    material: 'Rock',
    collectionStartDate: '2023-11-13',
    collectionEndDate: '2024-03-27',
    latitude: '46.1113889',
    longitude: '8.3091667',
    country: 'Italy',
    city: 'Verbano-Cusio-Ossola',
};

test.describe('IGSN CSV Upload and List', () => {
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
        const fileInput = page.locator('input[type="file"]').first();
        const csvFilePath = resolveDatasetExample(DOVE_CSV_DATA.filename);
        await fileInput.setInputFiles(csvFilePath);

        // Wait for redirect to /igsns after successful upload
        await page.waitForURL(/\/igsns/, { timeout: 30000 });

        // Verify the IGSN is displayed in the table
        await expect(page.getByText(DOVE_CSV_DATA.igsn)).toBeVisible({ timeout: 10000 });

        // Verify title is displayed (may be truncated)
        const titleCell = page.locator('td').filter({ hasText: DOVE_CSV_DATA.title.substring(0, 50) });
        await expect(titleCell).toBeVisible();

        // Verify sample type
        await expect(page.getByText(DOVE_CSV_DATA.sampleType)).toBeVisible();

        // Verify material
        await expect(page.getByText(DOVE_CSV_DATA.material)).toBeVisible();

        // Verify collection date (shown as two lines: start date and end date)
        await expect(page.getByText(DOVE_CSV_DATA.collectionStartDate)).toBeVisible();
        await expect(page.getByText(DOVE_CSV_DATA.collectionEndDate)).toBeVisible();

        // Verify status is 'uploaded'
        await expect(page.getByText('uploaded')).toBeVisible();
    });

    test('can upload DIVE CSV file and see data in /igsns table', async ({ page }) => {
        // Navigate to dashboard
        await page.goto('/dashboard');

        // Upload CSV
        const fileInput = page.locator('input[type="file"]').first();
        const csvFilePath = resolveDatasetExample(DIVE_CSV_DATA.filename);
        await fileInput.setInputFiles(csvFilePath);

        // Wait for redirect
        await page.waitForURL(/\/igsns/, { timeout: 30000 });

        // Verify data
        await expect(page.getByText(DIVE_CSV_DATA.igsn)).toBeVisible({ timeout: 10000 });
        await expect(page.getByText(DIVE_CSV_DATA.sampleType)).toBeVisible();
        await expect(page.getByText(DIVE_CSV_DATA.material)).toBeVisible();
        await expect(page.getByText(DIVE_CSV_DATA.collectionStartDate)).toBeVisible();
        await expect(page.getByText(DIVE_CSV_DATA.collectionEndDate)).toBeVisible();
    });

    test('can upload both CSV files and see all data', async ({ page }) => {
        // Upload first file (DOVE)
        await page.goto('/dashboard');
        let fileInput = page.locator('input[type="file"]').first();
        await fileInput.setInputFiles(resolveDatasetExample(DOVE_CSV_DATA.filename));
        await page.waitForURL(/\/igsns/, { timeout: 30000 });

        // Go back to dashboard and upload second file (DIVE)
        await page.goto('/dashboard');
        fileInput = page.locator('input[type="file"]').first();
        await fileInput.setInputFiles(resolveDatasetExample(DIVE_CSV_DATA.filename));
        await page.waitForURL(/\/igsns/, { timeout: 30000 });

        // Verify both IGSNs are displayed
        await expect(page.getByText(DOVE_CSV_DATA.igsn)).toBeVisible({ timeout: 10000 });
        await expect(page.getByText(DIVE_CSV_DATA.igsn)).toBeVisible();

        // Verify both materials are displayed
        await expect(page.getByText('Sediment')).toBeVisible();
        await expect(page.getByText('Rock')).toBeVisible();
    });

    test('shows error for duplicate IGSN upload', async ({ page }) => {
        // Upload first time
        await page.goto('/dashboard');
        let fileInput = page.locator('input[type="file"]').first();
        await fileInput.setInputFiles(resolveDatasetExample(DOVE_CSV_DATA.filename));
        await page.waitForURL(/\/igsns/, { timeout: 30000 });

        // Try to upload again (should fail with duplicate error)
        await page.goto('/dashboard');
        fileInput = page.locator('input[type="file"]').first();
        await fileInput.setInputFiles(resolveDatasetExample(DOVE_CSV_DATA.filename));

        // Should show error message about duplicate
        await expect(page.getByText(/already exists|duplicate/i)).toBeVisible({ timeout: 10000 });
    });

    test('admin can delete IGSN from /igsns page', async ({ page }) => {
        // First upload a CSV
        await page.goto('/dashboard');
        const fileInput = page.locator('input[type="file"]').first();
        await fileInput.setInputFiles(resolveDatasetExample(DOVE_CSV_DATA.filename));
        await page.waitForURL(/\/igsns/, { timeout: 30000 });

        // Verify IGSN is there
        await expect(page.getByText(DOVE_CSV_DATA.igsn)).toBeVisible({ timeout: 10000 });

        // Click delete button (admin-only)
        const deleteButton = page.getByRole('button', { name: /delete/i }).first();
        await deleteButton.click();

        // Confirm deletion in dialog
        const confirmButton = page.getByRole('button', { name: /delete/i }).last();
        await confirmButton.click();

        // Verify IGSN is gone
        await expect(page.getByText(DOVE_CSV_DATA.igsn)).not.toBeVisible({ timeout: 10000 });
    });
});
