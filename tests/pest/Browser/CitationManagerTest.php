<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Resource;
use App\Models\User;
use Tests\TestCase;

/**
 * Pest v4 Browser Tests for the Citation Manager (DataCite 4.7 relatedItem).
 *
 * Validates that the Quote icon on /resources opens the CitationManagerModal
 * wired against the vocabularies endpoint end-to-end.
 */

uses()->group('citations', 'browser');

describe('Citation Manager modal', function (): void {
    it('opens the Citation Manager modal from the Quote icon on /resources', function (): void {
        /** @var TestCase $this */
        $user = User::factory()->create([
            'role' => UserRole::CURATOR,
        ]);

        Resource::factory()->count(1)->create();

        $this->actingAs($user);

        visit('/resources')
            ->assertNoSmoke()
            ->assertVisible('[data-testid="citation-manager-button"]')
            ->click('[data-testid="citation-manager-button"]')
            ->assertSee('Citation Manager');
    });
});
