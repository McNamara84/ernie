import { expect, test } from '@playwright/test';

import { TEST_USER_EMAIL, TEST_USER_PASSWORD } from './constants';

/**
 * E2E Tests für das Laden von Descriptions aus alten Datensätzen.
 * 
 * WICHTIG: Diese Tests benötigen:
 * - Einen laufenden Laravel-Server (php artisan serve)
 * - Eine funktionierende Legacy-Datenbank mit Testdaten
 * - Einen verifizierten Test-User
 * 
 * Die Tests sind mit .skip markiert, da sie eine vollständige Infrastruktur benötigen.
 * Entferne .skip, um die Tests in einer Testumgebung auszuführen.
 */

test.describe('Load descriptions from old datasets', () => {
    test.beforeEach(async ({ page }) => {
        await page.goto('/login');
        await page.getByLabel('Email address').fill(TEST_USER_EMAIL);
        await page.getByLabel('Password').fill(TEST_USER_PASSWORD);
        await page.getByRole('button', { name: 'Log in' }).click();
        await page.waitForURL(/\/dashboard/, { timeout: 15_000 });
    });

    test.skip('lädt Abstract aus alten Datensätzen in Kurationsformular', async ({ page }) => {
        // Gehe zur Old Datasets Seite
        await page.goto('/old-datasets');
        await expect(page).toHaveURL(/\/old-datasets$/);
        await expect(page.getByRole('heading', { name: 'Old Datasets' })).toBeVisible();

        // Finde den ersten Datensatz mit "Open in Curation" Button
        const firstDatasetRow = page.locator('tbody tr').first();
        await firstDatasetRow.waitFor({ state: 'visible', timeout: 10_000 });

        // Klick auf "Open in Curation"
        const openButton = firstDatasetRow.getByRole('button', { name: /Open dataset/ });
        await openButton.click();

        // Warte auf Weiterleitung zum Kurationsformular
        await page.waitForURL(/\/curation/, { timeout: 15_000 });
        await expect(page).toHaveURL(/\/curation/);

        // Prüfe, dass das Formular geladen wurde
        await expect(page.getByRole('heading', { name: 'Create Resource' })).toBeVisible();

        // Öffne Descriptions Accordion falls nicht offen
        const descriptionsTrigger = page.getByRole('button', { name: 'Descriptions' });
        const isExpanded = await descriptionsTrigger.getAttribute('aria-expanded');
        if (isExpanded === 'false') {
            await descriptionsTrigger.click();
            await expect(descriptionsTrigger).toHaveAttribute('aria-expanded', 'true');
        }

        // Prüfe, dass der Abstract Tab vorhanden ist
        const abstractTab = page.getByRole('tab', { name: /Abstract/i });
        await expect(abstractTab).toBeVisible();

        // Klick auf Abstract Tab (falls nicht aktiv)
        await abstractTab.click();

        // Prüfe, dass das Abstract Textarea geladen wurde und Inhalt hat
        const abstractTextarea = page.getByRole('textbox', { name: /Abstract/i });
        await expect(abstractTextarea).toBeVisible();
        
        // Prüfe, dass der Abstract nicht leer ist (da alte Datensätze meist Abstracts haben)
        const abstractValue = await abstractTextarea.inputValue();
        expect(abstractValue.length).toBeGreaterThan(0);
    });

    test.skip('lädt alle Description-Typen aus alten Datensätzen korrekt', async ({ page }) => {
        // Gehe zur Old Datasets Seite
        await page.goto('/old-datasets');
        await expect(page).toHaveURL(/\/old-datasets$/);

        // Finde den ersten Datensatz
        const firstDatasetRow = page.locator('tbody tr').first();
        await firstDatasetRow.waitFor({ state: 'visible', timeout: 10_000 });

        // Klick auf "Open in Curation"
        const openButton = firstDatasetRow.getByRole('button', { name: /Open dataset/ });
        await openButton.click();

        // Warte auf Kurationsformular
        await page.waitForURL(/\/curation/, { timeout: 15_000 });

        // Öffne Descriptions Accordion
        const descriptionsTrigger = page.getByRole('button', { name: 'Descriptions' });
        const isExpanded = await descriptionsTrigger.getAttribute('aria-expanded');
        if (isExpanded === 'false') {
            await descriptionsTrigger.click();
            await expect(descriptionsTrigger).toHaveAttribute('aria-expanded', 'true');
        }

        // Liste der Description-Typen aus der alten DB
        const descriptionTypes = ['Abstract', 'Methods', 'TechnicalInfo', 'TableOfContents', 'Other'];

        // Prüfe jeden Tab
        for (const descType of descriptionTypes) {
            const tab = page.getByRole('tab', { name: new RegExp(descType, 'i') });
            
            if (await tab.isVisible()) {
                // Klick auf den Tab
                await tab.click();

                // Prüfe, ob der Tab einen Badge hat (zeigt an, dass Inhalt vorhanden ist)
                const hasBadge = await tab.locator('.badge, [class*="badge"]').count() > 0;

                if (hasBadge) {
                    // Wenn Badge vorhanden, sollte das Textarea Inhalt haben
                    const textarea = page.getByRole('textbox', { name: new RegExp(descType, 'i') });
                    await expect(textarea).toBeVisible();
                    
                    const content = await textarea.inputValue();
                    expect(content.length).toBeGreaterThan(0);
                }
            }
        }
    });

    test.skip('zeigt Character Count für geladene Descriptions an', async ({ page }) => {
        // Gehe zur Old Datasets Seite
        await page.goto('/old-datasets');
        await expect(page).toHaveURL(/\/old-datasets$/);

        // Finde den ersten Datensatz
        const firstDatasetRow = page.locator('tbody tr').first();
        await firstDatasetRow.waitFor({ state: 'visible', timeout: 10_000 });

        // Klick auf "Open in Curation"
        const openButton = firstDatasetRow.getByRole('button', { name: /Open dataset/ });
        await openButton.click();

        // Warte auf Kurationsformular
        await page.waitForURL(/\/curation/, { timeout: 15_000 });

        // Öffne Descriptions Accordion
        const descriptionsTrigger = page.getByRole('button', { name: 'Descriptions' });
        const isExpanded = await descriptionsTrigger.getAttribute('aria-expanded');
        if (isExpanded === 'false') {
            await descriptionsTrigger.click();
            await expect(descriptionsTrigger).toHaveAttribute('aria-expanded', 'true');
        }

        // Klick auf Abstract Tab
        const abstractTab = page.getByRole('tab', { name: /Abstract/i });
        await abstractTab.click();

        // Prüfe, dass Character Count angezeigt wird
        const abstractTextarea = page.getByRole('textbox', { name: /Abstract/i });
        const abstractValue = await abstractTextarea.inputValue();

        if (abstractValue.length > 0) {
            // Character Count sollte sichtbar sein und die korrekte Anzahl zeigen
            const characterCountText = page.getByText(new RegExp(`${abstractValue.length} characters`));
            await expect(characterCountText).toBeVisible();
        }
    });

    test.skip('behält Description-Daten beim Wechseln zwischen Tabs bei', async ({ page }) => {
        // Gehe zur Old Datasets Seite
        await page.goto('/old-datasets');
        await expect(page).toHaveURL(/\/old-datasets$/);

        // Finde den ersten Datensatz
        const firstDatasetRow = page.locator('tbody tr').first();
        await firstDatasetRow.waitFor({ state: 'visible', timeout: 10_000 });

        // Klick auf "Open in Curation"
        const openButton = firstDatasetRow.getByRole('button', { name: /Open dataset/ });
        await openButton.click();

        // Warte auf Kurationsformular
        await page.waitForURL(/\/curation/, { timeout: 15_000 });

        // Öffne Descriptions Accordion
        const descriptionsTrigger = page.getByRole('button', { name: 'Descriptions' });
        const isExpanded = await descriptionsTrigger.getAttribute('aria-expanded');
        if (isExpanded === 'false') {
            await descriptionsTrigger.click();
            await expect(descriptionsTrigger).toHaveAttribute('aria-expanded', 'true');
        }

        // Speichere Abstract-Wert
        const abstractTab = page.getByRole('tab', { name: /Abstract/i });
        await abstractTab.click();
        const abstractTextarea = page.getByRole('textbox', { name: /Abstract/i });
        const originalAbstractValue = await abstractTextarea.inputValue();

        // Wechsle zu einem anderen Tab
        const methodsTab = page.getByRole('tab', { name: /Methods/i });
        if (await methodsTab.isVisible()) {
            await methodsTab.click();
        }

        // Wechsle zurück zu Abstract
        await abstractTab.click();

        // Prüfe, dass der ursprüngliche Wert noch vorhanden ist
        const currentAbstractValue = await abstractTextarea.inputValue();
        expect(currentAbstractValue).toBe(originalAbstractValue);
    });

    test.skip('lädt Datensätze ohne Descriptions gracefully', async ({ page }) => {
        // Dieser Test prüft, dass das Formular auch funktioniert, 
        // wenn ein alter Datensatz keine Descriptions hat

        // Gehe zur Old Datasets Seite
        await page.goto('/old-datasets');
        await expect(page).toHaveURL(/\/old-datasets$/);

        // Finde einen Datensatz (könnte ohne Descriptions sein)
        const firstDatasetRow = page.locator('tbody tr').first();
        await firstDatasetRow.waitFor({ state: 'visible', timeout: 10_000 });

        // Klick auf "Open in Curation"
        const openButton = firstDatasetRow.getByRole('button', { name: /Open dataset/ });
        await openButton.click();

        // Warte auf Kurationsformular
        await page.waitForURL(/\/curation/, { timeout: 15_000 });

        // Öffne Descriptions Accordion
        const descriptionsTrigger = page.getByRole('button', { name: 'Descriptions' });
        const isExpanded = await descriptionsTrigger.getAttribute('aria-expanded');
        if (isExpanded === 'false') {
            await descriptionsTrigger.click();
            await expect(descriptionsTrigger).toHaveAttribute('aria-expanded', 'true');
        }

        // Prüfe, dass alle Tabs vorhanden sind (auch wenn leer)
        const abstractTab = page.getByRole('tab', { name: /Abstract/i });
        await expect(abstractTab).toBeVisible();

        // Das Formular sollte verwendbar sein, auch wenn keine Descriptions geladen wurden
        await abstractTab.click();
        const abstractTextarea = page.getByRole('textbox', { name: /Abstract/i });
        await expect(abstractTextarea).toBeVisible();
        await expect(abstractTextarea).toBeEnabled();
    });
});
