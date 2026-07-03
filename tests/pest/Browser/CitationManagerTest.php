<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Resource;
use App\Models\User;
use App\Services\ResourceCacheService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pest v4 Browser Tests for the Related Item Manager (DataCite 4.7 relatedItem).
 *
 * Validates that the Quote icon on /resources opens the CitationManagerModal
 * wired against the vocabularies endpoint end-to-end.
 */

uses(RefreshDatabase::class)->group('citations', 'browser');

describe('Related Item Manager modal', function (): void {
    it('opens the Related Item Manager modal from the Quote icon on /resources', function (): void {
        /** @var TestCase $this */
        $user = User::factory()->create([
            'role' => UserRole::CURATOR,
        ]);

        $resource = Resource::factory()->create();

        $this->actingAs($user);

        // Browser tests can reuse cache state across runs; ensure fresh listing payload.
        app(ResourceCacheService::class)->invalidateAllResourceCaches();

        $this->actingAs($user)
            ->get('/resources')
            ->assertInertia(fn ($page) => $page->has('resources', 1));

        visit('/resources')
            ->assertNoSmoke()
            ->assertVisible(sprintf('[data-testid="resources-row-checkbox-%d"]', $resource->id))
            ->click(sprintf('[data-testid="resources-row-checkbox-%d"]', $resource->id))
            ->assertVisible('[data-testid="resources-actions-menu-trigger"]')
            ->click('[data-testid="resources-actions-menu-trigger"]')
            ->assertVisible('[data-testid="resources-action-manage-related-items"]')
            ->click('[data-testid="resources-action-manage-related-items"]')
            ->assertSee('Related Item Manager');
    });
});