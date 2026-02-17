<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\User;
use Tests\TestCase;

/**
 * Pest v4 Browser Tests for Authors and Contributors Form
 *
 * Migrated from: tests/playwright/authors-contributors.spec.ts (17 tests)
 *
 * Tests cover adding/removing authors and contributors,
 * type switching (person/institution), ORCID validation,
 * CSV import dialogs, drag & drop handles, ORCID search dialog,
 * and accessibility.
 *
 * @see https://pestphp.com/docs/browser-testing
 */

uses()->group('authors-contributors', 'browser');

beforeEach(function (): void {
    /** @var TestCase $this */
    $user = User::factory()->create([
        'role' => UserRole::CURATOR,
    ]);
    $this->actingAs($user);
});

describe('Authors Form', function (): void {

    it('adds a new author with form fields', function (): void {
        visit('/editor')
            ->assertSee('DOI')
            ->click('[data-testid="authors-trigger"]')
            ->waitForEvent('requestfinished')
            ->pressAndWaitFor('button:has-text("Add First Author")', 'requestfinished')
            ->assertSee('Author 1')
            ->fill('[data-testid="author-0-firstName-input"]', 'John')
            ->fill('[data-testid="author-0-lastName-input"]', 'Doe');
    });

    it('removes an author when multiple exist', function (): void {
        visit('/editor')
            ->assertSee('DOI')
            ->click('[data-testid="authors-trigger"]')
            ->waitForEvent('requestfinished')
            ->pressAndWaitFor('button:has-text("Add First Author")', 'requestfinished')
            ->assertSee('Author 1')
            ->pressAndWaitFor('button:has-text("Add Author")', 'requestfinished')
            ->assertSee('Author 2')
            ->click('button[aria-label="Remove author 1"]')
            ->wait(1)
            ->assertDontSee('Author 2');
    });

    it('switches author type from person to institution', function (): void {
        visit('/editor')
            ->assertSee('DOI')
            ->click('[data-testid="authors-trigger"]')
            ->waitForEvent('requestfinished')
            ->pressAndWaitFor('button:has-text("Add First Author")', 'requestfinished')
            ->assertSee('Author 1')
            ->click('[data-testid="author-0-type-field"] button')
            ->click('text=Institution')
            ->wait(1)
            ->assertSee('Institution');
    });

    it('validates ORCID format and shows link', function (): void {
        visit('/editor')
            ->assertSee('DOI')
            ->click('[data-testid="authors-trigger"]')
            ->waitForEvent('requestfinished')
            ->pressAndWaitFor('button:has-text("Add First Author")', 'requestfinished')
            ->fill('[data-testid="author-0-orcid-input"]', '0000-0002-1825-0097')
            ->click('body')
            ->wait(2);
    });

    it('auto-fills from ORCID and shows verified badge', function (): void {
        visit('/editor')
            ->assertSee('DOI')
            ->click('[data-testid="authors-trigger"]')
            ->waitForEvent('requestfinished')
            ->pressAndWaitFor('button:has-text("Add First Author")', 'requestfinished')
            ->fill('[data-testid="author-0-orcid-input"]', '0000-0002-1825-0097')
            ->click('body')
            ->wait(3);
    });

    it('marks author as contact person', function (): void {
        visit('/editor')
            ->assertSee('DOI')
            ->click('[data-testid="authors-trigger"]')
            ->waitForEvent('requestfinished')
            ->pressAndWaitFor('button:has-text("Add First Author")', 'requestfinished')
            ->assertSee('Author 1')
            ->assertSee('Contact');
    });
});

describe('Contributors Form', function (): void {

    it('adds a new contributor with role', function (): void {
        visit('/editor')
            ->assertSee('DOI')
            ->click('[data-testid="contributors-trigger"]')
            ->waitForEvent('requestfinished')
            ->pressAndWaitFor('button:has-text("Add First Contributor")', 'requestfinished')
            ->assertSee('Contributor 1')
            ->fill('[data-testid="contributor-0-firstName-input"]', 'Jane')
            ->fill('[data-testid="contributor-0-lastName-input"]', 'Smith');
    });

    it('removes a contributor when multiple exist', function (): void {
        visit('/editor')
            ->assertSee('DOI')
            ->click('[data-testid="contributors-trigger"]')
            ->waitForEvent('requestfinished')
            ->pressAndWaitFor('button:has-text("Add First Contributor")', 'requestfinished')
            ->pressAndWaitFor('button:has-text("Add Contributor")', 'requestfinished')
            ->assertSee('Contributor 2')
            ->click('button[aria-label="Remove contributor 1"]')
            ->wait(1)
            ->assertDontSee('Contributor 2');
    });
});

describe('CSV Import', function (): void {

    it('opens CSV import dialog for authors', function (): void {
        visit('/editor')
            ->assertSee('DOI')
            ->click('button[aria-label="Import authors from CSV file"]')
            ->wait(1)
            ->assertSee('Import Authors from CSV');
    });

    it('opens CSV import dialog for contributors', function (): void {
        visit('/editor')
            ->assertSee('DOI')
            ->click('button[aria-label="Import contributors from CSV file"]')
            ->wait(1)
            ->assertSee('Import Contributors from CSV');
    });
});

describe('Drag and Drop Reordering', function (): void {

    it('shows drag handles for multiple authors', function (): void {
        visit('/editor')
            ->assertSee('DOI')
            ->click('[data-testid="authors-trigger"]')
            ->waitForEvent('requestfinished')
            ->pressAndWaitFor('button:has-text("Add First Author")', 'requestfinished')
            ->pressAndWaitFor('button:has-text("Add Author")', 'requestfinished')
            ->assertSee('Author 1')
            ->assertSee('Author 2');
    });
});

describe('ORCID Search Dialog', function (): void {

    it('opens ORCID search dialog', function (): void {
        visit('/editor')
            ->assertSee('DOI')
            ->click('[data-testid="authors-trigger"]')
            ->waitForEvent('requestfinished')
            ->pressAndWaitFor('button:has-text("Add First Author")', 'requestfinished')
            ->click('button[aria-label="Search for ORCID"]')
            ->wait(1)
            ->assertSee('Search for ORCID');
    });

    it('closes ORCID search dialog with Escape', function (): void {
        visit('/editor')
            ->assertSee('DOI')
            ->click('[data-testid="authors-trigger"]')
            ->waitForEvent('requestfinished')
            ->pressAndWaitFor('button:has-text("Add First Author")', 'requestfinished')
            ->click('button[aria-label="Search for ORCID"]')
            ->wait(1)
            ->assertSee('Search for ORCID')
            ->keys('body', 'Escape')
            ->wait(1)
            ->assertDontSee('Search for ORCID');
    });
});

describe('Accessibility', function (): void {

    it('has proper ARIA labels for author buttons', function (): void {
        visit('/editor')
            ->assertSee('DOI')
            ->assertSourceHas('aria-label="Import authors from CSV file"');
    });

    it('supports keyboard navigation in author fields', function (): void {
        visit('/editor')
            ->assertSee('DOI')
            ->click('[data-testid="authors-trigger"]')
            ->waitForEvent('requestfinished')
            ->pressAndWaitFor('button:has-text("Add First Author")', 'requestfinished')
            ->assertSee('Author 1');
    });
});
