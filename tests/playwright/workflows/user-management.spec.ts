import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

test.describe('User Management', () => {
    test.beforeEach(async ({ page }) => {
        // Reset database and seed with test data
        await page.goto('/');
        
        // Login as admin user (User ID 1)
        await page.goto('/login');
        await page.fill('input[name="email"]', 'admin@example.com');
        await page.fill('input[name="password"]', 'password');
        await page.click('button[type="submit"]');
        
        // Wait for redirect to dashboard
        await page.waitForURL('/dashboard');
    });

    test.describe('Critical - User List Access', () => {
        test('should allow admin to access users page', async ({ page }) => {
            // Navigate to Users page via direct navigation (more reliable than clicking link)
            await page.goto('/users');
            await page.waitForURL('/users');

            // Verify page loads - look for CardTitle text content
            await expect(page.locator('text=User Management')).toBeVisible();
            
            // Verify users table exists
            await expect(page.locator('table')).toBeVisible();
        });

        test('should display all users in table', async ({ page }) => {
            await page.goto('/users');

            // Wait for table to load
            await page.waitForSelector('table');

            // Verify table has headers
            await expect(page.locator('th:has-text("Name")')).toBeVisible();
            await expect(page.locator('th:has-text("Email")')).toBeVisible();
            await expect(page.locator('th:has-text("Role")')).toBeVisible();
            await expect(page.locator('th:has-text("Status")')).toBeVisible();

            // Verify at least one user row exists
            const rows = page.locator('tbody tr');
            await expect(rows).not.toHaveCount(0);
        });

        test.skip('should deny access for curator users', async ({ page }) => {
            // TODO: Fix logout mechanism - current implementation uses dropdown menu
            // Logout as admin
            await page.click('button:has-text("Logout")');
            
            // Login as curator
            await page.goto('/login');
            await page.fill('input[name="email"]', 'curator@example.com');
            await page.fill('input[name="password"]', 'password');
            await page.click('button[type="submit"]');
            await page.waitForURL('/dashboard');

            // Try to access users page
            await page.goto('/users');
            
            // Should be forbidden (403) or redirected
            await expect(page).not.toHaveURL('/users');
        });
    });

    test.describe('Critical - Role Management', () => {
        test('should allow admin to change user role', async ({ page }) => {
            await page.goto('/users');

            // Find a beginner user row (not User ID 1)
            const userRow = page.locator('tbody tr').filter({ 
                has: page.locator('text=Beginner') 
            }).first();

            await expect(userRow).toBeVisible();

            // Click role dropdown/select
            const roleSelect = userRow.locator('select, [role="combobox"]').first();
            await roleSelect.click();

            // Select Curator role
            await page.click('text=Curator');

            // Wait for success message
            await expect(page.locator('text=/Role.*updated|User role updated/i')).toBeVisible();

            // Verify role badge changed
            await expect(userRow.locator('text=Curator')).toBeVisible();
        });

        test('should prevent changing own role', async ({ page }) => {
            await page.goto('/users');

            // Find the admin's own row (should be User ID 1 or current user)
            const adminRow = page.locator('tbody tr').filter({ 
                has: page.locator('text=admin@example.com') 
            }).first();

            // Role select should be disabled or not present
            const roleSelect = adminRow.locator('select, [role="combobox"]');
            
            if (await roleSelect.count() > 0) {
                await expect(roleSelect).toBeDisabled();
            }
        });

        test('should prevent modifying User ID 1', async ({ page }) => {
            await page.goto('/users');

            // Create a second admin to test with
            // (This assumes User ID 1 is visible in the table)
            
            const firstRow = page.locator('tbody tr').first();
            
            // User ID 1 should have disabled controls
            const roleSelect = firstRow.locator('select, [role="combobox"]');
            
            if (await roleSelect.count() > 0) {
                // Should be disabled or show protected status
                const isDisabled = await roleSelect.isDisabled();
                expect(isDisabled).toBeTruthy();
            }
        });
    });

    test.describe('Workflows - User Deactivation', () => {
        test('should deactivate and reactivate a user', async ({ page }) => {
            await page.goto('/users');

            // Find an active beginner user
            const userRow = page.locator('tbody tr').filter({ 
                has: page.locator('text=Beginner') 
            }).filter({
                has: page.locator('text=Active')
            }).first();

            await expect(userRow).toBeVisible();

            // Click deactivate button
            await userRow.locator('button:has-text("Deactivate")').click();

            // Confirm dialog if present
            const confirmButton = page.locator('button:has-text("Confirm"), button:has-text("Deactivate")');
            if (await confirmButton.isVisible()) {
                await confirmButton.click();
            }

            // Wait for success message
            await expect(page.locator('text=/deactivated|Deactivated/i')).toBeVisible();

            // Verify status changed to Inactive
            await expect(userRow.locator('text=Inactive')).toBeVisible();

            // Now reactivate
            await userRow.locator('button:has-text("Reactivate")').click();

            // Confirm if needed
            const reactivateConfirm = page.locator('button:has-text("Confirm"), button:has-text("Reactivate")');
            if (await reactivateConfirm.isVisible()) {
                await reactivateConfirm.click();
            }

            // Wait for success
            await expect(page.locator('text=/reactivated|Reactivated/i')).toBeVisible();

            // Verify status back to Active
            await expect(userRow.locator('text=Active')).toBeVisible();
        });

        test('should prevent deactivating own account', async ({ page }) => {
            await page.goto('/users');

            // Find admin's own row
            const adminRow = page.locator('tbody tr').filter({ 
                has: page.locator('text=admin@example.com') 
            }).first();

            // Deactivate button should be disabled or not present
            const deactivateButton = adminRow.locator('button:has-text("Deactivate")');
            
            if (await deactivateButton.count() > 0) {
                await expect(deactivateButton).toBeDisabled();
            }
        });
    });

    test.describe('Workflows - Password Reset', () => {
        test('should send password reset link to user', async ({ page }) => {
            await page.goto('/users');

            // Find a user row (not admin)
            const userRow = page.locator('tbody tr').filter({ 
                has: page.locator('text=Beginner') 
            }).first();

            await expect(userRow).toBeVisible();

            // Click reset password button
            await userRow.locator('button:has-text("Reset Password"), button[title*="Reset"]').click();

            // Confirm dialog
            const confirmButton = page.locator('button:has-text("Send Reset Link"), button:has-text("Confirm")');
            if (await confirmButton.isVisible()) {
                await confirmButton.click();
            }

            // Wait for success message
            await expect(page.locator('text=/reset.*sent|Password reset/i')).toBeVisible();
        });
    });

    test.describe('Accessibility - User Management Page', () => {
        test('should not have critical accessibility violations', async ({ page }) => {
            await page.goto('/users');
            await page.waitForSelector('table');

            const accessibilityScanResults = await new AxeBuilder({ page })
                .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
                .analyze();

            expect(accessibilityScanResults.violations).toEqual([]);
        });

        test('should have proper ARIA labels for interactive elements', async ({ page }) => {
            await page.goto('/users');

            // Check role selects have labels
            const roleSelects = page.locator('select[name*="role"], [role="combobox"]');
            const count = await roleSelects.count();

            for (let i = 0; i < count; i++) {
                const select = roleSelects.nth(i);
                const ariaLabel = await select.getAttribute('aria-label');
                const ariaLabelledBy = await select.getAttribute('aria-labelledby');
                
                // Should have either aria-label or aria-labelledby
                expect(ariaLabel || ariaLabelledBy).toBeTruthy();
            }
        });

        test('should support keyboard navigation', async ({ page }) => {
            await page.goto('/users');

            // Tab through interactive elements
            await page.keyboard.press('Tab'); // First interactive element
            
            // Verify focus is visible
            const focusedElement = await page.locator(':focus');
            await expect(focusedElement).toBeVisible();

            // Continue tabbing to verify tab order
            for (let i = 0; i < 5; i++) {
                await page.keyboard.press('Tab');
                const currentFocus = await page.locator(':focus');
                await expect(currentFocus).toBeVisible();
            }
        });

        test('should announce status changes to screen readers', async ({ page }) => {
            await page.goto('/users');

            // Success messages should have appropriate ARIA attributes
            const userRow = page.locator('tbody tr').filter({ 
                has: page.locator('text=Beginner') 
            }).first();

            // Trigger an action (role change)
            const roleSelect = userRow.locator('select, [role="combobox"]').first();
            if (await roleSelect.isVisible()) {
                await roleSelect.click();
                await page.click('text=Curator');

                // Success message should be in a live region
                const successMessage = page.locator('[role="status"], [role="alert"], [aria-live]');
                await expect(successMessage).toBeVisible();
            }
        });

        test('should meet color contrast requirements', async ({ page }) => {
            await page.goto('/users');
            await page.waitForSelector('table');

            const accessibilityScanResults = await new AxeBuilder({ page })
                .withTags(['wcag2aa'])
                .disableRules(['document-title']) // May not be critical for this test
                .analyze();

            // Filter for color contrast violations only
            const contrastViolations = accessibilityScanResults.violations.filter(
                v => v.id === 'color-contrast'
            );

            expect(contrastViolations).toEqual([]);
        });

        test('should have descriptive button labels', async ({ page }) => {
            await page.goto('/users');

            // All buttons should have accessible names
            const buttons = page.locator('button');
            const count = await buttons.count();

            for (let i = 0; i < Math.min(count, 10); i++) { // Check first 10 buttons
                const button = buttons.nth(i);
                const text = await button.textContent();
                const ariaLabel = await button.getAttribute('aria-label');
                const title = await button.getAttribute('title');

                // Button should have text, aria-label, or title
                expect(text || ariaLabel || title).toBeTruthy();
            }
        });
    });

    test.describe('Workflows - Role Badge Display', () => {
        test('should display correct badge colors for each role', async ({ page }) => {
            await page.goto('/users');

            // Admin badge should have destructive styling (red)
            const adminBadge = page.locator('text=Admin').first();
            if (await adminBadge.isVisible()) {
                await expect(adminBadge).toHaveClass(/destructive|bg-destructive/);
            }

            // Group Leader badge should have primary styling
            const glBadge = page.locator('text=Group Leader').first();
            if (await glBadge.isVisible()) {
                await expect(glBadge).toHaveClass(/primary|bg-primary/);
            }

            // Curator badge should have secondary styling
            const curatorBadge = page.locator('text=Curator').first();
            if (await curatorBadge.isVisible()) {
                await expect(curatorBadge).toHaveClass(/secondary|bg-secondary/);
            }

            // Beginner badge should have outline styling
            const beginnerBadge = page.locator('text=Beginner').first();
            if (await beginnerBadge.isVisible()) {
                await expect(beginnerBadge).toHaveClass(/outline|text-foreground/);
            }
        });
    });

    test.describe('Workflows - Group Leader Permissions', () => {
        test('should allow group leader to manage curators and beginners', async ({ page }) => {
            // Logout as admin
            await page.click('button:has-text("Logout")');
            
            // Login as group leader
            await page.goto('/login');
            await page.fill('input[name="email"]', 'groupleader@example.com');
            await page.fill('input[name="password"]', 'password');
            await page.click('button[type="submit"]');
            await page.waitForURL('/dashboard');

            // Navigate to users page
            await page.goto('/users');
            await expect(page.locator('text=User Management')).toBeVisible();

            // Find a beginner user
            const beginnerRow = page.locator('tbody tr').filter({ 
                has: page.locator('text=Beginner') 
            }).first();

            // Should be able to change role to curator
            const roleSelect = beginnerRow.locator('select, [role="combobox"]').first();
            if (await roleSelect.isVisible()) {
                await roleSelect.click();
                
                // Should see Curator option
                await expect(page.locator('text=Curator')).toBeVisible();
                
                // Should NOT see Group Leader or Admin options
                const glOption = page.locator('text=Group Leader');
                const adminOption = page.locator('text=Admin');
                
                // These should not be selectable options
                const glCount = await glOption.count();
                const adminCount = await adminOption.count();
                
                // If they exist, they should be disabled or hidden in the dropdown
                expect(glCount === 0 || adminCount === 0).toBeTruthy();
            }
        });
    });
});
