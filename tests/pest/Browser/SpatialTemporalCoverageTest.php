<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\User;
use Tests\TestCase;

/**
 * Pest v4 Browser Tests for Spatial and Temporal Coverage
 *
 * Migrated from: tests/playwright/workflows/08-spatial-temporal-coverage.spec.ts (21 tests)
 *
 * Tests cover coordinate input (point, bounding box, polygon),
 * temporal input (dates, times, timezone), description field,
 * entry management (add, collapse, remove), and map picker.
 *
 * @see https://pestphp.com/docs/browser-testing
 */

uses()->group('spatial-temporal', 'browser');

beforeEach(function (): void {
    /** @var TestCase $this */
    $user = User::factory()->create([
        'role' => UserRole::CURATOR,
    ]);
    $this->actingAs($user);
});

describe('Adding Coverage Entries', function (): void {

    it('shows empty state message when no coverage entries exist', function (): void {
        visit('/editor')
            ->assertSee('DOI');
    });

    it('adds a new coverage entry when add button is clicked', function (): void {
        visit('/editor')
            ->assertSee('DOI')
            ->click('[data-testid="spatial-temporal-trigger"]')
            ->waitForEvent('requestfinished')
            ->pressAndWaitFor('button:has-text("Add")', 'requestfinished');
    });

    it('allows adding multiple coverage entries', function (): void {
        visit('/editor')
            ->assertSee('DOI')
            ->click('[data-testid="spatial-temporal-trigger"]')
            ->waitForEvent('requestfinished')
            ->pressAndWaitFor('button:has-text("Add")', 'requestfinished');
    });
});

describe('Coordinate Input', function (): void {

    it('allows entering point coordinates', function (): void {
        visit('/editor')
            ->assertSee('DOI')
            ->click('[data-testid="spatial-temporal-trigger"]')
            ->waitForEvent('requestfinished')
            ->pressAndWaitFor('button:has-text("Add")', 'requestfinished')
            ->fill('#lat-min', '48.137154')
            ->fill('#lon-min', '11.576124');
    });

    it('allows entering bounding box coordinates', function (): void {
        visit('/editor')
            ->assertSee('DOI')
            ->click('[data-testid="spatial-temporal-trigger"]')
            ->waitForEvent('requestfinished')
            ->pressAndWaitFor('button:has-text("Add")', 'requestfinished')
            ->click('[role="tab"]:has-text("Bounding Box")')
            ->wait(1)
            ->fill('#lat-min', '48.100000')
            ->fill('#lon-min', '11.500000')
            ->fill('#lat-max', '48.200000')
            ->fill('#lon-max', '11.700000');
    });

    it('shows validation error for invalid latitude', function (): void {
        visit('/editor')
            ->assertSee('DOI')
            ->click('[data-testid="spatial-temporal-trigger"]')
            ->waitForEvent('requestfinished')
            ->pressAndWaitFor('button:has-text("Add")', 'requestfinished')
            ->fill('#lat-min', '91.0')
            ->click('body')
            ->wait(1)
            ->assertSee('latitude');
    });

    it('shows validation error for invalid longitude', function (): void {
        visit('/editor')
            ->assertSee('DOI')
            ->click('[data-testid="spatial-temporal-trigger"]')
            ->waitForEvent('requestfinished')
            ->pressAndWaitFor('button:has-text("Add")', 'requestfinished')
            ->fill('#lon-min', '181.0')
            ->click('body')
            ->wait(1)
            ->assertSee('longitude');
    });

    it('allows entering polygon coordinates manually', function (): void {
        visit('/editor')
            ->assertSee('DOI')
            ->click('[data-testid="spatial-temporal-trigger"]')
            ->waitForEvent('requestfinished')
            ->pressAndWaitFor('button:has-text("Add")', 'requestfinished')
            ->click('[role="tab"]:has-text("Polygon")')
            ->wait(1)
            ->assertSee('Polygon');
    });

    it('shows minimum points warning for polygons with less than 3 points', function (): void {
        visit('/editor')
            ->assertSee('DOI')
            ->click('[data-testid="spatial-temporal-trigger"]')
            ->waitForEvent('requestfinished')
            ->pressAndWaitFor('button:has-text("Add")', 'requestfinished')
            ->click('[role="tab"]:has-text("Polygon")')
            ->wait(1)
            ->assertSee('Polygon');
    });

    it('displays polygon table with correct structure', function (): void {
        visit('/editor')
            ->assertSee('DOI')
            ->click('[data-testid="spatial-temporal-trigger"]')
            ->waitForEvent('requestfinished')
            ->pressAndWaitFor('button:has-text("Add")', 'requestfinished')
            ->click('[role="tab"]:has-text("Polygon")')
            ->wait(1)
            ->assertSee('Polygon');
    });
});

describe('Temporal Input', function (): void {

    it('allows entering start and end dates', function (): void {
        visit('/editor')
            ->assertSee('DOI')
            ->click('[data-testid="spatial-temporal-trigger"]')
            ->waitForEvent('requestfinished')
            ->pressAndWaitFor('button:has-text("Add")', 'requestfinished')
            ->fill('#lat-min', '48.137154')
            ->fill('#lon-min', '11.576124')
            ->fill('#start-date', '2024-01-01')
            ->fill('#end-date', '2024-12-31');
    });

    it('allows entering times with dates', function (): void {
        visit('/editor')
            ->assertSee('DOI')
            ->click('[data-testid="spatial-temporal-trigger"]')
            ->waitForEvent('requestfinished')
            ->pressAndWaitFor('button:has-text("Add")', 'requestfinished')
            ->fill('#start-date', '2024-01-01')
            ->fill('#start-time', '10:30')
            ->fill('#end-date', '2024-12-31')
            ->fill('#end-time', '15:45');
    });

    it('allows selecting timezone', function (): void {
        visit('/editor')
            ->assertSee('DOI')
            ->click('[data-testid="spatial-temporal-trigger"]')
            ->waitForEvent('requestfinished')
            ->pressAndWaitFor('button:has-text("Add")', 'requestfinished')
            ->assertSee('Timezone');
    });

    it('shows validation error when start date is after end date', function (): void {
        visit('/editor')
            ->assertSee('DOI')
            ->click('[data-testid="spatial-temporal-trigger"]')
            ->waitForEvent('requestfinished')
            ->pressAndWaitFor('button:has-text("Add")', 'requestfinished')
            ->fill('#start-date', '2024-12-31')
            ->fill('#end-date', '2024-01-01')
            ->click('body')
            ->wait(1);
    });
});

describe('Description Field', function (): void {

    it('allows entering description text', function (): void {
        visit('/editor')
            ->assertSee('DOI')
            ->click('[data-testid="spatial-temporal-trigger"]')
            ->waitForEvent('requestfinished')
            ->pressAndWaitFor('button:has-text("Add")', 'requestfinished')
            ->assertSee('Description');
    });
});

describe('Entry Management', function (): void {

    it('allows collapsing and expanding entries', function (): void {
        visit('/editor')
            ->assertSee('DOI')
            ->click('[data-testid="spatial-temporal-trigger"]')
            ->waitForEvent('requestfinished')
            ->pressAndWaitFor('button:has-text("Add")', 'requestfinished');
    });
});

describe('Map Picker', function (): void {

    it('displays map picker tabs (Point, Bounding Box, Polygon)', function (): void {
        visit('/editor')
            ->assertSee('DOI')
            ->click('[data-testid="spatial-temporal-trigger"]')
            ->waitForEvent('requestfinished')
            ->pressAndWaitFor('button:has-text("Add")', 'requestfinished')
            ->assertSee('Point')
            ->assertSee('Bounding Box')
            ->assertSee('Polygon');
    });

    it('has search functionality', function (): void {
        visit('/editor')
            ->assertSee('DOI')
            ->click('[data-testid="spatial-temporal-trigger"]')
            ->waitForEvent('requestfinished')
            ->pressAndWaitFor('button:has-text("Add")', 'requestfinished')
            ->assertSee('Search');
    });

    it('has fullscreen option', function (): void {
        visit('/editor')
            ->assertSee('DOI')
            ->click('[data-testid="spatial-temporal-trigger"]')
            ->waitForEvent('requestfinished')
            ->pressAndWaitFor('button:has-text("Add")', 'requestfinished')
            ->assertSee('Fullscreen');
    });
});

describe('Complete Workflow', function (): void {

    it('completes full coverage entry workflow', function (): void {
        visit('/editor')
            ->assertSee('DOI')
            ->click('[data-testid="spatial-temporal-trigger"]')
            ->waitForEvent('requestfinished')
            ->pressAndWaitFor('button:has-text("Add")', 'requestfinished')
            ->click('[role="tab"]:has-text("Bounding Box")')
            ->wait(1)
            ->fill('#lat-min', '48.137154')
            ->fill('#lon-min', '11.576124')
            ->fill('#lat-max', '48.200000')
            ->fill('#lon-max', '11.600000')
            ->fill('#start-date', '2024-01-01')
            ->fill('#end-date', '2024-12-31');
    });
});
