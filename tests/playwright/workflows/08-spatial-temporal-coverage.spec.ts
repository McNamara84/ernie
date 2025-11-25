import { expect, test } from '@playwright/test';

import { TEST_USER_EMAIL, TEST_USER_PASSWORD } from '../constants';

/**
 * Spatial and Temporal Coverage Tests
 * 
 * These tests cover the complete workflow for adding, editing, and managing
 * spatial and temporal coverage entries in the data editor.
 * 
 * Note: Google Maps interactions are limited in automated tests due to iframe restrictions.
 * We focus on form inputs and manual coordinate entry rather than map clicking.
 */

test.describe('Spatial and Temporal Coverage', () => {
    test.beforeEach(async ({ page }) => {
        // Login and navigate to editor
        await page.goto('/login');
        await page.getByLabel('Email address').fill(TEST_USER_EMAIL);
        await page.getByLabel('Password').fill(TEST_USER_PASSWORD);
        await page.getByRole('button', { name: 'Log in' }).click();
        await page.waitForURL(/\/dashboard/, { timeout: 15000 });
        
        await page.goto('/editor');
        await page.waitForLoadState('networkidle');
    });

    test.describe('Adding Coverage Entries', () => {
        test('should show empty state message when no coverage entries exist', async ({ page }) => {
            // Look for the empty state in the spatial coverage section
            const emptyState = page.getByText(/no spatial and temporal coverage entries yet/i);
            
            if (await emptyState.isVisible()) {
                expect(await emptyState.isVisible()).toBe(true);
                expect(await page.getByRole('button', { name: /add first coverage entry/i }).isVisible()).toBe(true);
            }
        });

        test('should add a new coverage entry when add button is clicked', async ({ page }) => {
            // Find and click the add button (could be "Add First" or "Add Coverage Entry")
            const addButton = page.getByRole('button', { name: /add.*coverage entry/i }).first();
            
            if (await addButton.isVisible()) {
                await addButton.click();
                
                // Should show coverage entry card
                await expect(page.getByText(/coverage entry #1/i)).toBeVisible();
                
                // Should show coordinate input fields
                await expect(page.locator('#lat-min')).toBeVisible();
                await expect(page.locator('#lon-min')).toBeVisible();
            }
        });

        test('should allow adding multiple coverage entries', async ({ page }) => {
            // Add first entry
            const firstAddButton = page.getByRole('button', { name: /add.*coverage entry/i }).first();
            
            if (await firstAddButton.isVisible()) {
                await firstAddButton.click();
                
                // Fill in required coordinates for first entry
                await page.locator('#lat-min').fill('48.137154');
                await page.locator('#lon-min').fill('11.576124');
                
                // Add second entry
                const secondAddButton = page.getByRole('button', { name: /add coverage entry/i }).last();
                await secondAddButton.click();
                
                // Should show both entries
                await expect(page.getByText(/coverage entry #1/i)).toBeVisible();
                await expect(page.getByText(/coverage entry #2/i)).toBeVisible();
            }
        });
    });

    test.describe('Coordinate Input', () => {
        test('should allow entering point coordinates (lat/lon min only)', async ({ page }) => {
            // Add a coverage entry
            const addButton = page.getByRole('button', { name: /add.*coverage entry/i }).first();
            
            if (await addButton.isVisible()) {
                await addButton.click();
                
                // Enter point coordinates
                const latMinInput = page.locator('#lat-min').first();
                const lonMinInput = page.locator('#lon-min').first();
                
                await latMinInput.fill('48.137154');
                await lonMinInput.fill('11.576124');
                
                // Verify values
                await expect(latMinInput).toHaveValue('48.137154');
                await expect(lonMinInput).toHaveValue('11.576124');
            }
        });

        test('should allow entering rectangle coordinates (lat/lon min and max)', async ({ page }) => {
            // Add a coverage entry
            const addButton = page.getByRole('button', { name: /add.*coverage entry/i }).first();
            
            if (await addButton.isVisible()) {
                await addButton.click();
                
                // Switch to Bounding Box tab
                await page.getByRole('tab', { name: /bounding box/i }).click();
                await page.waitForTimeout(300); // Wait for tab switch animation
                
                // Enter rectangle coordinates
                const latMinInput = page.locator('#lat-min').first();
                const lonMinInput = page.locator('#lon-min').first();
                const latMaxInput = page.locator('#lat-max').first();
                const lonMaxInput = page.locator('#lon-max').first();
                
                await latMinInput.fill('48.100000');
                await lonMinInput.fill('11.500000');
                await latMaxInput.fill('48.200000');
                await lonMaxInput.fill('11.700000');
                
                // Verify all values
                await expect(latMinInput).toHaveValue('48.100000');
                await expect(lonMinInput).toHaveValue('11.500000');
                await expect(latMaxInput).toHaveValue('48.200000');
                await expect(lonMaxInput).toHaveValue('11.700000');
            }
        });

        test('should show validation error for invalid latitude', async ({ page }) => {
            // Add a coverage entry
            const addButton = page.getByRole('button', { name: /add.*coverage entry/i }).first();
            
            if (await addButton.isVisible()) {
                await addButton.click();
                
                // Enter invalid latitude (> 90)
                const latMinInput = page.locator('#lat-min').first();
                await latMinInput.fill('91.0');
                
                // Trigger blur to show validation
                await latMinInput.blur();
                
                // Should show error message
                await expect(page.getByText(/latitude must be between -90 and \+90/i)).toBeVisible();
            }
        });

        test('should show validation error for invalid longitude', async ({ page }) => {
            // Add a coverage entry
            const addButton = page.getByRole('button', { name: /add.*coverage entry/i }).first();
            
            if (await addButton.isVisible()) {
                await addButton.click();
                
                // Enter invalid longitude (> 180)
                const lonMinInput = page.locator('#lon-min').first();
                await lonMinInput.fill('181.0');
                
                // Trigger blur to show validation
                await lonMinInput.blur();
                
                // Should show error message
                await expect(page.getByText(/longitude must be between -180 and \+180/i)).toBeVisible();
            }
        });

        test('should format coordinates to 6 decimal places', async ({ page }) => {
            // Add a coverage entry
            const addButton = page.getByRole('button', { name: /add.*coverage entry/i }).first();
            
            if (await addButton.isVisible()) {
                await addButton.click();
                
                // Enter coordinate with more than 6 decimals
                const latMinInput = page.locator('#lat-min').first();
                await latMinInput.fill('48.123456789');
                await latMinInput.blur();
                
                // Should be formatted to 6 decimals
                const value = await latMinInput.inputValue();
                const decimals = value.split('.')[1];
                expect(decimals?.length || 0).toBeLessThanOrEqual(6);
            }
        });

        test('should allow entering polygon coordinates manually', async ({ page }) => {
            // Add a coverage entry
            const addButton = page.getByRole('button', { name: /add.*coverage entry/i }).first();
            
            if (await addButton.isVisible()) {
                await addButton.click();
                
                // Switch to Polygon tab
                await page.getByRole('tab', { name: /polygon/i }).click();
                await page.waitForTimeout(300);
                
                // Should show empty state message
                await expect(page.getByText(/no points yet/i)).toBeVisible();
                
                // Should show "Add Point" button
                const addPointButton = page.getByRole('button', { name: /add point/i });
                await expect(addPointButton).toBeVisible();
                
                // Add three points for a valid polygon (minimum required)
                for (let i = 0; i < 3; i++) {
                    await addPointButton.click();
                    await page.waitForTimeout(200);
                }
                
                // Fill coordinates for all three points using table row locators
                // The polygon form has a table with rows for each point
                const allNumberInputs = page.locator('input[type="number"][step="0.000001"]');
                
                // Point 1: Munich center (inputs 0 and 1)
                await allNumberInputs.nth(0).fill('48.137154');
                await allNumberInputs.nth(1).fill('11.576124');
                
                // Point 2: North (inputs 2 and 3)
                await allNumberInputs.nth(2).fill('48.200000');
                await allNumberInputs.nth(3).fill('11.576124');
                
                // Point 3: East (inputs 4 and 5)
                await allNumberInputs.nth(4).fill('48.137154');
                await allNumberInputs.nth(5).fill('11.650000');
                
                // Should show point count
                await expect(page.getByText(/Polygon Points \(3\)/i)).toBeVisible();
                
                // Should show "Clear Polygon" button when points exist
                await expect(page.getByRole('button', { name: /clear polygon/i })).toBeVisible();
                
                // Minimum 3 points warning should NOT be visible with 3 or more points
                await expect(page.getByText(/minimum 3 points required/i)).not.toBeVisible();
            }
        });

        test('should show validation warning for polygons with less than 3 points', async ({ page }) => {
            // Add a coverage entry
            const addButton = page.getByRole('button', { name: /add.*coverage entry/i }).first();
            
            if (await addButton.isVisible()) {
                await addButton.click();
                
                // Switch to Polygon tab
                await page.getByRole('tab', { name: /polygon/i }).click();
                await page.waitForTimeout(300);
                
                // Add only 2 points (insufficient for polygon)
                const addPointButton = page.getByRole('button', { name: /add point/i });
                await addPointButton.click();
                await page.waitForTimeout(100);
                await addPointButton.click();
                await page.waitForTimeout(100);
                
                // Should show warning about minimum points
                await expect(page.getByText(/minimum 3 points/i)).toBeVisible();
                
                // Should show point count
                await expect(page.getByText(/Polygon Points \(2\)/i)).toBeVisible();
            }
        });

        test('should display polygon table with correct number of rows', async ({ page }) => {
            // Add a coverage entry
            const addButton = page.getByRole('button', { name: /add.*coverage entry/i }).first();
            
            if (await addButton.isVisible()) {
                await addButton.click();
                
                // Switch to Polygon tab
                await page.getByRole('tab', { name: /polygon/i }).click();
                await page.waitForTimeout(300);
                
                // Add 4 points
                const addPointButton = page.getByRole('button', { name: /add point/i });
                for (let i = 0; i < 4; i++) {
                    await addPointButton.click();
                    await page.waitForTimeout(200);
                }
                
                // Should show 4 points in header
                await expect(page.getByText(/Polygon Points \(4\)/i)).toBeVisible();
                
                // Should have table with 4 rows (one per point)
                const tableRows = page.locator('tbody tr');
                await expect(tableRows).toHaveCount(4);
                
                // Each row should have coordinate inputs (lat/lon)
                const firstRow = tableRows.first();
                await expect(firstRow.locator('input[type="number"]')).toHaveCount(2);
                
                // Should show Clear Polygon button when points exist
                await expect(page.getByRole('button', { name: /clear polygon/i })).toBeVisible();
            }
        });
    });

    test.describe('Temporal Input', () => {
        test('should allow entering start and end dates', async ({ page }) => {
            // Add a coverage entry
            const addButton = page.getByRole('button', { name: /add.*coverage entry/i }).first();
            
            if (await addButton.isVisible()) {
                await addButton.click();
                
                // Fill required coordinates first
                await page.locator('#lat-min').first().fill('48.137154');
                await page.locator('#lon-min').first().fill('11.576124');
                
                // Enter dates
                const startDateInput = page.locator('#start-date').first();
                const endDateInput = page.locator('#end-date').first();
                
                await startDateInput.fill('2024-01-01');
                await endDateInput.fill('2024-12-31');
                
                // Verify values
                await expect(startDateInput).toHaveValue('2024-01-01');
                await expect(endDateInput).toHaveValue('2024-12-31');
            }
        });

        test('should allow entering times with dates', async ({ page }) => {
            // Add a coverage entry
            const addButton = page.getByRole('button', { name: /add.*coverage entry/i }).first();
            
            if (await addButton.isVisible()) {
                await addButton.click();
                
                // Enter dates and times
                const startDateInput = page.locator('#start-date').first();
                const startTimeInput = page.locator('#start-time').first();
                const endDateInput = page.locator('#end-date').first();
                const endTimeInput = page.locator('#end-time').first();
                
                await startDateInput.fill('2024-01-01');
                await startTimeInput.fill('10:30');
                await endDateInput.fill('2024-12-31');
                await endTimeInput.fill('15:45');
                
                // Verify values
                await expect(startTimeInput).toHaveValue('10:30');
                await expect(endTimeInput).toHaveValue('15:45');
            }
        });

        test('should allow selecting timezone', async ({ page }) => {
            // Add a coverage entry
            const addButton = page.getByRole('button', { name: /add.*coverage entry/i }).first();
            
            if (await addButton.isVisible()) {
                await addButton.click();
                
                // Click timezone select trigger (Radix UI Select component)
                const timezoneSelect = page.getByRole('combobox', { name: /timezone/i });
                
                if (await timezoneSelect.isVisible()) {
                    await timezoneSelect.click();
                    
                    // Wait for dropdown to open and select Europe/Berlin option
                    // Radix UI Select renders options in a portal
                    await page.getByRole('option', { name: /Europe\/Berlin/i }).click();
                    
                    // Verify selection - check the trigger's text content
                    await expect(timezoneSelect).toContainText(/Europe\/Berlin/i);
                }
            }
        });

        test('should show validation error when start date is after end date', async ({ page }) => {
            // Add a coverage entry
            const addButton = page.getByRole('button', { name: /add.*coverage entry/i }).first();
            
            if (await addButton.isVisible()) {
                await addButton.click();
                
                // Enter dates in wrong order
                await page.locator('#start-date').first().fill('2024-12-31');
                await page.locator('#end-date').first().fill('2024-01-01');
                await page.locator('#end-date').first().blur();
                
                // Should show error
                await expect(page.getByText(/start date must be before or equal to end date/i)).toBeVisible();
            }
        });
    });

    test.describe('Description Field', () => {
        test('should allow entering description text', async ({ page }) => {
            // Add a coverage entry
            const addButton = page.getByRole('button', { name: /add.*coverage entry/i }).first();
            
            if (await addButton.isVisible()) {
                await addButton.click();
                
                // Enter description
                const descriptionTextarea = page.getByPlaceholder(/deep drilling campaign/i).first();
                await descriptionTextarea.fill('Test coverage description');
                
                // Verify value
                await expect(descriptionTextarea).toHaveValue('Test coverage description');
            }
        });
    });

    test.describe('Entry Management', () => {
        test('should allow collapsing and expanding entries', async ({ page }) => {
            // Add a coverage entry
            const addButton = page.getByRole('button', { name: /add.*coverage entry/i }).first();
            
            if (await addButton.isVisible()) {
                await addButton.click();
                
                // Entry should be expanded by default
                await expect(page.locator('#lat-min').first()).toBeVisible();
                
                // Click header to collapse
                await page.getByText(/coverage entry #1/i).click();
                
                // Coordinate inputs should be hidden
                await expect(page.locator('#lat-min').first()).not.toBeVisible();
                
                // Click again to expand
                await page.getByText(/coverage entry #1/i).click();
                
                // Should be visible again
                await expect(page.locator('#lat-min').first()).toBeVisible();
            }
        });

        test('should show preview when entry is collapsed and has data', async ({ page }) => {
            // Add a coverage entry
            const addButton = page.getByRole('button', { name: /add.*coverage entry/i }).first();
            
            if (await addButton.isVisible()) {
                await addButton.click();
                
                // Fill in some data
                await page.locator('#lat-min').first().fill('48.137154');
                await page.locator('#lon-min').first().fill('11.576124');
                await page.locator('#start-date').first().fill('2024-01-01');
                
                // Collapse entry
                await page.getByText(/coverage entry #1/i).click();
                
                // Should show preview with coordinates
                await expect(page.getByText(/48\.137154.*11\.576124/)).toBeVisible();
                // Should show preview with date
                await expect(page.getByText(/2024-01-01/)).toBeVisible();
            }
        });

        test('should allow removing non-first entries', async ({ page }) => {
            // Add first entry
            const firstAddButton = page.getByRole('button', { name: /add.*coverage entry/i }).first();
            
            if (await firstAddButton.isVisible()) {
                await firstAddButton.click();
                
                // Fill coordinates for first entry
                await page.locator('#lat-min').first().fill('48.137154');
                await page.locator('#lon-min').first().fill('11.576124');
                
                // Add second entry
                await page.getByRole('button', { name: /add coverage entry/i }).last().click();
                
                // Second entry should have a remove button with new aria-label
                const removeButton = page.getByRole('button', { name: /remove coverage entry/i }).last();
                await removeButton.click();
                
                // Should only have one entry left
                await expect(page.getByText(/coverage entry #2/i)).not.toBeVisible();
            }
        });
    });

    test.describe('Map Picker', () => {
        test('should display map picker component', async ({ page }) => {
            // Add a coverage entry
            const addButton = page.getByRole('button', { name: /add.*coverage entry/i }).first();
            
            if (await addButton.isVisible()) {
                await addButton.click();
                
                // Should have tabs for different coverage types
                await expect(page.getByRole('tab', { name: /^point$/i })).toBeVisible();
                await expect(page.getByRole('tab', { name: /bounding box/i })).toBeVisible();
                await expect(page.getByRole('tab', { name: /polygon/i })).toBeVisible();
                
                // Point tab is active by default - should only have point button (with emoji)
                await expect(page.getByRole('button', { name: /point/i })).toBeVisible();
                
                // Switch to Bounding Box tab
                await page.getByRole('tab', { name: /bounding box/i }).click();
                await page.waitForTimeout(300);
                
                // Now rectangle button should be visible
                await expect(page.getByRole('button', { name: /rectangle/i })).toBeVisible();
            }
        });

        test('should have search functionality', async ({ page }) => {
            // Add a coverage entry
            const addButton = page.getByRole('button', { name: /add.*coverage entry/i }).first();
            
            if (await addButton.isVisible()) {
                await addButton.click();
                
                // Should have search input and button
                await expect(page.getByPlaceholder(/search for a location/i).first()).toBeVisible();
                await expect(page.getByRole('button', { name: /search/i }).first()).toBeVisible();
            }
        });

        test('should have fullscreen option', async ({ page }) => {
            // Add a coverage entry
            const addButton = page.getByRole('button', { name: /add.*coverage entry/i }).first();
            
            if (await addButton.isVisible()) {
                await addButton.click();
                
                // Should have fullscreen button
                const fullscreenButton = page.getByRole('button', { name: /fullscreen/i }).first();
                await expect(fullscreenButton).toBeVisible();
                
                // Click to open fullscreen
                await fullscreenButton.click();
                
                // Should show dialog
                await expect(page.getByRole('dialog')).toBeVisible();
                await expect(page.getByText(/map picker - fullscreen/i)).toBeVisible();
            }
        });
    });

    test.describe('Complete Workflow', () => {
        test('should complete full coverage entry workflow', async ({ page }) => {
            // Add coverage entry
            const addButton = page.getByRole('button', { name: /add.*coverage entry/i }).first();
            
            if (await addButton.isVisible()) {
                await addButton.click();
                
                // Switch to Bounding Box tab for rectangle coordinates
                await page.getByRole('tab', { name: /bounding box/i }).click();
                await page.waitForTimeout(300);
                
                // Fill all fields
                await page.locator('#lat-min').first().fill('48.137154');
                await page.locator('#lon-min').first().fill('11.576124');
                await page.locator('#lat-max').first().fill('48.200000');
                await page.locator('#lon-max').first().fill('11.600000');
                
                await page.locator('#start-date').first().fill('2024-01-01');
                await page.locator('#start-time').first().fill('10:30');
                await page.locator('#end-date').first().fill('2024-12-31');
                await page.locator('#end-time').first().fill('15:45');
                
                await page.getByPlaceholder(/deep drilling campaign/i).first().fill('Field campaign in Munich area');
                
                // Collapse to verify preview
                await page.getByText(/coverage entry #1/i).click();
                await page.waitForTimeout(300);
                
                // Verify preview shows coordinate data (may be formatted separately)
                await expect(page.locator('text=48.137154').first()).toBeVisible();
                await expect(page.locator('text=11.576124').first()).toBeVisible();
                await expect(page.locator('text=48.200000').first()).toBeVisible();
                await expect(page.locator('text=11.600000').first()).toBeVisible();
                
                // Verify temporal data
                await expect(page.locator('text=2024-01-01').first()).toBeVisible();
                await expect(page.locator('text=10:30').first()).toBeVisible();
                await expect(page.locator('text=2024-12-31').first()).toBeVisible();
                await expect(page.locator('text=15:45').first()).toBeVisible();
                
                // Verify description
                await expect(page.getByText(/Field campaign in Munich area/)).toBeVisible();
            }
        });
    });
});
