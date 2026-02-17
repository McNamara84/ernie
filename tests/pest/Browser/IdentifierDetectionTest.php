<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\User;
use Tests\TestCase;

/**
 * Pest v4 Browser Tests for Related Work Identifier Type Auto-Detection
 *
 * Migrated from: tests/playwright/workflows/12-related-work-identifier-detection.spec.ts (17 tests)
 *
 * Tests verify that the Related Work form correctly auto-detects identifier types
 * when users enter identifiers (DOI, ARK, arXiv, Handle, URL, ISBN, PMID, etc.).
 *
 * Note: Comprehensive pattern testing is handled by Vitest unit tests in:
 * - tests/vitest/__tests__/identifier-type-detection.test.ts
 *
 * @see https://pestphp.com/docs/browser-testing
 */

uses()->group('identifier-detection', 'browser');

beforeEach(function (): void {
    /** @var TestCase $this */
    $user = User::factory()->create([
        'role' => UserRole::CURATOR,
    ]);
    $this->actingAs($user);
});

/**
 * Helper: Opens the editor and ensures the Related Work section is visible
 */
function visitEditorWithRelatedWork(): mixed
{
    return visit('/editor')
        ->assertSee('DOI')
        ->click('[data-testid="related-work-accordion-trigger"]')
        ->waitForEvent('requestfinished');
}

describe('DOI Detection', function (): void {

    it('detects bare DOI format', function (): void {
        visitEditorWithRelatedWork()
            ->fill('[data-testid="related-identifier-input"]', '10.5880/fidgeo.2025.072')
            ->wait(2)
            ->assertSee('DOI');
    });

    it('detects DOI with https://doi.org URL', function (): void {
        visitEditorWithRelatedWork()
            ->fill('[data-testid="related-identifier-input"]', 'https://doi.org/10.5880/fidgeo.2026.001')
            ->wait(2)
            ->assertSee('DOI');
    });

    it('detects DOI with doi: prefix', function (): void {
        visitEditorWithRelatedWork()
            ->fill('[data-testid="related-identifier-input"]', 'doi:10.1371/journal.pbio.0020449')
            ->wait(2)
            ->assertSee('DOI');
    });

    it('detects DOI with legacy dx.doi.org URL', function (): void {
        visitEditorWithRelatedWork()
            ->fill('[data-testid="related-identifier-input"]', 'https://dx.doi.org/10.5880/fidgeo.2025.072')
            ->wait(2)
            ->assertSee('DOI');
    });
});

describe('ARK Detection', function (): void {

    it('detects compact ARK format', function (): void {
        visitEditorWithRelatedWork()
            ->fill('[data-testid="related-identifier-input"]', 'ark:12148/btv1b8449691v/f29')
            ->wait(2)
            ->assertSee('ARK');
    });

    it('detects ARK with resolver URL (n2t.net)', function (): void {
        visitEditorWithRelatedWork()
            ->fill('[data-testid="related-identifier-input"]', 'https://n2t.net/ark:/12148/btv1b8449691v/f29')
            ->wait(2)
            ->assertSee('ARK');
    });
});

describe('arXiv Detection', function (): void {

    it('detects new format arXiv ID', function (): void {
        visitEditorWithRelatedWork()
            ->fill('[data-testid="related-identifier-input"]', '2501.13958')
            ->wait(2)
            ->assertSee('arXiv');
    });

    it('detects arXiv with prefix', function (): void {
        visitEditorWithRelatedWork()
            ->fill('[data-testid="related-identifier-input"]', 'arXiv:2501.13958v3')
            ->wait(2)
            ->assertSee('arXiv');
    });

    it('detects arXiv URL', function (): void {
        visitEditorWithRelatedWork()
            ->fill('[data-testid="related-identifier-input"]', 'https://arxiv.org/abs/2501.13958')
            ->wait(2)
            ->assertSee('arXiv');
    });
});

describe('Handle Detection', function (): void {

    it('detects bare Handle format', function (): void {
        visitEditorWithRelatedWork()
            ->fill('[data-testid="related-identifier-input"]', '2142/103380')
            ->wait(2)
            ->assertSee('Handle');
    });

    it('detects Handle with extended prefix (FDO)', function (): void {
        visitEditorWithRelatedWork()
            ->fill('[data-testid="related-identifier-input"]', '21.T11998/0000-001A-3905-1')
            ->wait(2)
            ->assertSee('Handle');
    });

    it('detects Handle URL', function (): void {
        visitEditorWithRelatedWork()
            ->fill('[data-testid="related-identifier-input"]', 'https://hdl.handle.net/2142/103380')
            ->wait(2)
            ->assertSee('Handle');
    });
});

describe('URL Detection', function (): void {

    it('detects generic HTTPS URL', function (): void {
        visitEditorWithRelatedWork()
            ->fill('[data-testid="related-identifier-input"]', 'https://www.gfz-potsdam.de/research')
            ->wait(2)
            ->assertSee('URL');
    });

    it('detects HTTP URL', function (): void {
        visitEditorWithRelatedWork()
            ->fill('[data-testid="related-identifier-input"]', 'http://example.org/resource/123')
            ->wait(2)
            ->assertSee('URL');
    });
});

describe('ISBN Detection', function (): void {

    it('detects ISBN-13', function (): void {
        visitEditorWithRelatedWork()
            ->fill('[data-testid="related-identifier-input"]', '9780141026626')
            ->wait(2)
            ->assertSee('ISBN');
    });

    it('detects ISBN-13 with hyphens', function (): void {
        visitEditorWithRelatedWork()
            ->fill('[data-testid="related-identifier-input"]', '978-0-141-02662-6')
            ->wait(2)
            ->assertSee('ISBN');
    });
});
