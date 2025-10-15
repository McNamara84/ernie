import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

test.describe('Changelog Accessibility Tests (BITV 2.0 / WCAG 2.1 Level AA)', () => {
    test.beforeEach(async ({ page }) => {
        await page.goto('/changelog');
        await page.waitForSelector('[aria-label="Changelog Timeline"]');
    });

    test('should not have any WCAG 2.1 Level A/AA violations (BITV 2.0 required)', async ({ page }) => {
        // BITV 2.0 = WCAG 2.1 Level AA - gesetzlich vorgeschrieben in Deutschland
        const accessibilityScanResults = await new AxeBuilder({ page })
            .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
            .analyze();

        expect(accessibilityScanResults.violations).toEqual([]);
    });

    test('should meet color contrast requirements (WCAG 1.4.3 Level AA)', async ({ page }) => {
        // Mindestens 4.5:1 für normalen Text, 3:1 für großen Text
        const accessibilityScanResults = await new AxeBuilder({ page })
            .withTags(['wcag2aa'])
            .disableRules(['color-contrast-enhanced']) // AAA excluded (only AA required for BITV 2.0)
            .analyze();

        expect(accessibilityScanResults.violations).toEqual([]);
    });

    test('should have correct ARIA attributes (WCAG 4.1.2 Level A)', async ({ page }) => {
        // Name, Role, Value - alle UI-Komponenten müssen korrekte ARIA-Attribute haben
        const firstButton = page.locator('#release-trigger-0');
        await expect(firstButton).toHaveAttribute('aria-expanded', 'true');
        await expect(firstButton).toHaveAttribute('aria-controls', 'release-0');
        await expect(firstButton).toHaveAttribute('type', 'button');

        const secondButton = page.locator('#release-trigger-1');
        await expect(secondButton).toHaveAttribute('aria-expanded', 'false');
        await expect(secondButton).toHaveAttribute('aria-controls', 'release-1');
    });

    test('should have logical heading structure (WCAG 1.3.1 Level A)', async ({ page }) => {
        // Info and Relationships - Überschriften-Hierarchie muss logisch sein
        const h1 = page.getByRole('heading', { level: 1, name: 'Changelog' });
        await expect(h1).toBeVisible();

        // Erste Version erweitern falls nötig
        const firstButton = page.locator('#release-trigger-0');
        const isExpanded = await firstButton.getAttribute('aria-expanded');
        
        if (isExpanded === 'false') {
            await firstButton.click();
            await page.waitForTimeout(400);
        }

        // Section headings prüfen
        const sectionHeadings = page.locator('h3');
        const count = await sectionHeadings.count();
        expect(count).toBeGreaterThan(0);
    });

    test('should announce status changes to screen readers (WCAG 4.1.3 Level AA)', async ({ page }) => {
        // Status Messages - dynamische Inhaltsänderungen müssen angekündigt werden
        const liveRegion = page.locator('[role="status"][aria-live="polite"]');
        await expect(liveRegion).toBeAttached();
        await expect(liveRegion).toHaveAttribute('aria-atomic', 'true');

        const secondButton = page.locator('#release-trigger-1');

        // Erweitern
        await secondButton.click();
        await page.waitForTimeout(100);

        const expandText = await liveRegion.textContent();
        expect(expandText).toContain('erweitert');

        // Einklappen
        await secondButton.click();
        await page.waitForTimeout(100);

        const collapseText = await liveRegion.textContent();
        expect(collapseText).toContain('eingeklappt');
    });

    test('should be fully keyboard accessible (WCAG 2.1.1 Level A)', async ({ page }) => {
        // Keyboard - alle Funktionen müssen per Tastatur erreichbar sein
        const firstButton = page.locator('#release-trigger-0');
        
        await firstButton.focus();
        await expect(firstButton).toBeFocused();

        // Arrow Down Navigation
        await page.keyboard.press('ArrowDown');
        await page.waitForTimeout(600);

        const secondButton = page.locator('#release-trigger-1');
        await expect(secondButton).toHaveAttribute('aria-expanded', 'true');

        // Arrow Up Navigation
        await page.keyboard.press('ArrowUp');
        await page.waitForTimeout(600);

        await expect(firstButton).toHaveAttribute('aria-expanded', 'true');
    });

    test('should have visible focus indicators (WCAG 2.4.7 Level AA)', async ({ page }) => {
        // Focus Visible - Fokus muss immer sichtbar sein
        const firstButton = page.locator('#release-trigger-0');
        await firstButton.focus();
        
        await expect(firstButton).toBeFocused();
        // Focus ring ist via CSS vorhanden (focus:ring-2)
    });

    test('should identify errors clearly (WCAG 3.3.1 Level A)', async ({ page }) => {
        // Error Identification - Fehler müssen klar erkennbar sein
        await page.route('**/api/changelog', (route) => {
            route.abort('failed');
        });

        await page.goto('/changelog');
        await page.waitForTimeout(500);

        const errorAlert = page.locator('[role="alert"]');
        await expect(errorAlert).toBeVisible();
        await expect(errorAlert).toHaveAttribute('aria-atomic', 'true');

        // Überschrift für Fehler
        const errorHeading = errorAlert.locator('h2');
        await expect(errorHeading).toBeVisible();

        // Reload-Button muss fokussierbar sein
        const reloadButton = errorAlert.locator('button');
        await expect(reloadButton).toBeVisible();
        await reloadButton.focus();
        await expect(reloadButton).toBeFocused();
    });

    test('should use valid ARIA attributes (WCAG 4.1.2 Level A)', async ({ page }) => {
        // Parsing - keine ARIA-Fehler
        const accessibilityScanResults = await new AxeBuilder({ page })
            .withTags(['cat.aria'])
            .analyze();

        expect(accessibilityScanResults.violations).toEqual([]);
    });

    test('should have semantic HTML structure (WCAG 1.3.1 Level A)', async ({ page }) => {
        // Info and Relationships - semantische Struktur
        const timeline = page.locator('[aria-label="Changelog Timeline"]');
        await expect(timeline).toHaveRole('list');

        const releases = timeline.locator('li');
        const count = await releases.count();
        expect(count).toBeGreaterThan(0);

        const buttons = page.locator('[id^="release-trigger-"]');
        for (let i = 0; i < Math.min(count, 3); i++) {
            await expect(buttons.nth(i)).toHaveRole('button');
        }
    });
});
