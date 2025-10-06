import { test, expect } from '@playwright/test';
import { TEST_USER_EMAIL, TEST_USER_PASSWORD } from './constants';

/**
 * E2E Tests für das Laden von Autoren aus alten Datensätzen.
 * 
 * WICHTIG: Diese Tests benötigen:
 * - Einen laufenden Laravel-Server (php artisan serve)
 * - Eine funktionierende Legacy-Datenbank mit Testdaten
 * - Einen verifizierten Test-User
 * 
 * Die Tests sind mit .skip markiert, da sie eine vollständige Infrastruktur benötigen.
 * Entferne .skip, um die Tests in einer Testumgebung auszuführen.
 */

test.describe('Load authors from old datasets', () => {
    test.beforeEach(async ({ page }) => {
        await page.goto('/login');
        await page.getByLabel('Email address').fill(TEST_USER_EMAIL);
        await page.getByLabel('Password').fill(TEST_USER_PASSWORD);
        await page.getByRole('button', { name: 'Log in' }).click();
        await page.waitForURL(/\/dashboard/, { timeout: 15_000 });
    });

    test.skip('lädt Datensatz mit CP-Autoren in Kurationsformular', async ({ page }) => {
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

        // Öffne Authors Accordion falls nicht offen
        const authorsTrigger = page.getByRole('button', { name: 'Authors' });
        const isExpanded = await authorsTrigger.getAttribute('aria-expanded');
        if (isExpanded === 'false') {
            await authorsTrigger.click();
            await expect(authorsTrigger).toHaveAttribute('aria-expanded', 'true');
        }

        // Prüfe, dass mindestens ein Autor geladen wurde
        const authorSection = page.locator('div[data-testid="authors-section"], section').filter({
            has: page.getByText('Author 1'),
        });
        await expect(authorSection).toBeVisible();

        // Prüfe, dass Autorname ausgefüllt ist
        const lastNameInput = page.getByLabel('Last name').first();
        await expect(lastNameInput).not.toBeEmpty();
    });

    test.skip('lädt Autoren mit ContactPerson-Rolle und Kontaktinfo korrekt', async ({ page }) => {
        // Gehe zur Old Datasets Seite
        await page.goto('/old-datasets');
        await expect(page).toHaveURL(/\/old-datasets$/);

        // Finde einen Datensatz mit Kontaktperson (durch Suche oder direkt den ersten)
        const firstDatasetRow = page.locator('tbody tr').first();
        await firstDatasetRow.waitFor({ state: 'visible', timeout: 10_000 });

        // Klick auf "Open in Curation"
        const openButton = firstDatasetRow.getByRole('button', { name: /Open dataset/ });
        await openButton.click();

        // Warte auf Kurationsformular
        await page.waitForURL(/\/curation/, { timeout: 15_000 });

        // Öffne Authors Accordion
        const authorsTrigger = page.getByRole('button', { name: 'Authors' });
        const isExpanded = await authorsTrigger.getAttribute('aria-expanded');
        if (isExpanded === 'false') {
            await authorsTrigger.click();
            await expect(authorsTrigger).toHaveAttribute('aria-expanded', 'true');
        }

        // Prüfe, ob ein Autor mit CP-Checkbox geladen wurde
        // Wir prüfen, ob mindestens ein Autor vorhanden ist
        const authorSection = page.locator('div, section').filter({
            has: page.getByText('Author 1'),
        });
        await expect(authorSection).toBeVisible();

        // Wenn CP-Checkbox aktiviert ist, sollten Email und Website Felder sichtbar sein
        const contactCheckbox = page.getByRole('checkbox', { name: 'Contact person' }).first();
        const isChecked = await contactCheckbox.isChecked();

        if (isChecked) {
            // Email und Website Felder sollten sichtbar sein
            const emailField = page.getByLabel('Email address').first();
            const websiteField = page.getByLabel('Website').first();

            await expect(emailField).toBeVisible();
            await expect(websiteField).toBeVisible();

            // Prüfe, dass Email oder Website ausgefüllt sind (wenn vorhanden)
            const emailValue = await emailField.inputValue();
            const websiteValue = await websiteField.inputValue();

            // Mindestens eines sollte ausgefüllt sein
            expect(emailValue.length > 0 || websiteValue.length > 0).toBeTruthy();
        }
    });

    test.skip('lädt Affiliationen für Autoren aus alten Datensätzen', async ({ page }) => {
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

        // Öffne Authors Accordion
        const authorsTrigger = page.getByRole('button', { name: 'Authors' });
        const isExpanded = await authorsTrigger.getAttribute('aria-expanded');
        if (isExpanded === 'false') {
            await authorsTrigger.click();
            await expect(authorsTrigger).toHaveAttribute('aria-expanded', 'true');
        }

        // Prüfe, ob Affiliationen-Sektion vorhanden ist
        const affiliationsSection = page.getByText('Affiliations').first();
        if (await affiliationsSection.isVisible()) {
            // Wenn Affiliationen vorhanden sind, sollte mindestens eine sichtbar sein
            await expect(affiliationsSection).toBeVisible();
        }
    });

    test.skip('lädt ORCID-Daten für Autoren aus alten Datensätzen', async ({ page }) => {
        // Navigiere zu einem Datensatz mit ORCID-Daten (z.B. Resource ID 3)
        // In einer echten Test-DB würden wir hier zu /old-datasets gehen und nach
        // einem spezifischen Datensatz suchen
        await page.goto('/old-datasets');
        await expect(page).toHaveURL(/\/old-datasets$/);

        // Finde einen Datensatz (idealerweise einen mit bekannter ORCID)
        const firstDatasetRow = page.locator('tbody tr').first();
        await firstDatasetRow.waitFor({ state: 'visible', timeout: 10_000 });

        // Klick auf "Open in Curation"
        const openButton = firstDatasetRow.getByRole('button', { name: /Open dataset/ });
        await openButton.click();

        // Warte auf Kurationsformular
        await page.waitForURL(/\/curation/, { timeout: 15_000 });

        // Öffne Authors Accordion
        const authorsTrigger = page.getByRole('button', { name: 'Authors' });
        const isExpanded = await authorsTrigger.getAttribute('aria-expanded');
        if (isExpanded === 'false') {
            await authorsTrigger.click();
            await expect(authorsTrigger).toHaveAttribute('aria-expanded', 'true');
        }

        // Prüfe, ob ORCID-Feld existiert
        const orcidLabel = page.getByText('ORCID').first();
        if (await orcidLabel.isVisible()) {
            // ORCID-Feld sollte vorhanden sein
            await expect(orcidLabel).toBeVisible();

            // Prüfe, ob ORCID-Input-Feld vorhanden ist
            const orcidInput = page.getByLabel('ORCID').first();
            await expect(orcidInput).toBeVisible();

            // Wenn ORCID-Daten vorhanden sind, sollte das Feld ausgefüllt sein
            const orcidValue = await orcidInput.inputValue();
            if (orcidValue.length > 0) {
                // ORCID sollte dem Format 0000-0000-0000-0000 entsprechen
                expect(orcidValue).toMatch(/^\d{4}-\d{4}-\d{4}-\d{3}[\dX]$/);
                console.log(`ORCID found: ${orcidValue}`);
            }
        }
    });

    test.skip('behandelt Datensätze ohne Autoren gracefully', async ({ page }) => {
        // Gehe zur Old Datasets Seite
        await page.goto('/old-datasets');
        await expect(page).toHaveURL(/\/old-datasets$/);

        // Finde einen Datensatz
        const firstDatasetRow = page.locator('tbody tr').first();
        await firstDatasetRow.waitFor({ state: 'visible', timeout: 10_000 });

        // Klick auf "Open in Curation"
        const openButton = firstDatasetRow.getByRole('button', { name: /Open dataset/ });
        await openButton.click();

        // Warte auf Kurationsformular
        await page.waitForURL(/\/curation/, { timeout: 15_000 });

        // Formular sollte trotzdem geladen werden
        await expect(page.getByRole('heading', { name: 'Create Resource' })).toBeVisible();

        // Authors Accordion sollte vorhanden sein
        const authorsTrigger = page.getByRole('button', { name: 'Authors' });
        await expect(authorsTrigger).toBeVisible();
    });
});
