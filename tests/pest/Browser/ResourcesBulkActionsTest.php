<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Resource;
use App\Models\User;
use Tests\TestCase;

/**
 * Pest v4 Browser Tests for Resources page bulk actions.
 *
 * Validates that the multiselect UI introduced for issue #363 is wired up
 * end-to-end and renders correctly for curators and beginners.
 *
 * @see Issue #363
 */

uses()->group('resources', 'bulk-actions', 'browser');

describe('Resources page bulk actions (smoke)', function (): void {
    it('renders the bulk actions toolbar with selection-aware state', function (): void {
        /** @var TestCase $this */
        $user = User::factory()->create([
            'role' => UserRole::CURATOR,
        ]);

        Resource::factory()->count(3)->create();

        $this->actingAs($user);

        $page = visit('/resources')
            ->assertNoSmoke()
            ->assertSee('Select rows to enable bulk actions');

        $page->click('[data-testid="resources-select-all"]')
            ->assertSee('resources selected');
    });

    it('hides the bulk register button for beginners', function (): void {
        /** @var TestCase $this */
        $user = User::factory()->create([
            'role' => UserRole::BEGINNER,
        ]);

        Resource::factory()->count(2)->create();

        $this->actingAs($user);

        visit('/resources')
            ->assertNoSmoke()
            ->assertDontSee('Register Selected');
    });

    it('shows the export dropdown trigger for any authenticated user', function (): void {
        /** @var TestCase $this */
        $user = User::factory()->create([
            'role' => UserRole::BEGINNER,
        ]);

        Resource::factory()->count(2)->create();

        $this->actingAs($user);

        visit('/resources')
            ->assertNoSmoke()
            ->assertVisible('[data-testid="bulk-export-button"]');
    });
});
