<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(RefreshDatabase::class);

/**
 * Pest Browser Tests for Authentication & Route Access
 *
 * Migrated from:
 * - tests/playwright/workflows/02-old-datasets-workflow.spec.ts (1 test)
 * - tests/playwright/workflows/04-curation-workflow.spec.ts (2 tests)
 * - tests/playwright/workflows/04-editor-workflow.spec.ts (duplicate, skipped)
 * - tests/playwright/workflows/05-resources-management.spec.ts (2 tests)
 *
 * Total: 5 unique tests migrated
 */

describe('Protected Routes Require Authentication', function (): void {

    it('redirects /old-datasets to login when unauthenticated', function (): void {
        visit('/old-datasets')
            ->assertPathIs('/login');
    });

    it('redirects /editor to login when unauthenticated', function (): void {
        visit('/editor')
            ->assertPathIs('/login');
    });

    it('redirects /resources to login when unauthenticated', function (): void {
        visit('/resources')
            ->assertPathIs('/login');
    });
});

describe('Protected Routes Are Accessible After Login', function (): void {

    it('editor page is accessible for authenticated user', function (): void {
        /** @var TestCase $this */
        $user = User::factory()->create([
            'role' => UserRole::CURATOR,
        ]);
        $this->actingAs($user);

        visit('/editor')
            ->assertNoSmoke();
    });

    it('resources page is accessible for authenticated user', function (): void {
        /** @var TestCase $this */
        $user = User::factory()->create([
            'role' => UserRole::CURATOR,
        ]);
        $this->actingAs($user);

        visit('/resources')
            ->assertNoSmoke();
    });
});
