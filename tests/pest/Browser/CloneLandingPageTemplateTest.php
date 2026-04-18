<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\LandingPageTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(RefreshDatabase::class);

/**
 * Pest v4 Browser Tests for Cloning Landing Page Templates
 *
 * Verifies that the default GFZ template can be cloned successfully.
 * This was broken on Stage due to a migration issue (duplicate column error)
 * which prevented the LandingPageTemplateSeeder from running.
 *
 * @see https://github.com/McNamara84/ernie/pull/666
 * @see https://pestphp.com/docs/browser-testing
 */

uses()->group('landing-pages', 'browser');

describe('Landing Page Template Cloning', function (): void {

    it('clones the default template via API', function (): void {
        /** @var TestCase $this */
        $user = User::factory()->create(['role' => UserRole::ADMIN]);
        $this->actingAs($user);

        LandingPageTemplate::factory()->default()->create();

        $response = $this->postJson('/landing-pages', [
            'name' => 'My Custom Template',
        ]);

        $response->assertCreated()
            ->assertJson([
                'message' => 'Template created successfully',
            ])
            ->assertJsonPath('template.name', 'My Custom Template')
            ->assertJsonPath('template.is_default', false);

        $this->assertDatabaseHas('landing_page_templates', [
            'name' => 'My Custom Template',
            'is_default' => false,
            'created_by' => $user->id,
        ]);
    });

    it('clones with correct section order from default template', function (): void {
        /** @var TestCase $this */
        $user = User::factory()->create(['role' => UserRole::ADMIN]);
        $this->actingAs($user);

        $defaultTemplate = LandingPageTemplate::factory()->default()->create();

        $response = $this->actingAs($user)
            ->postJson('/landing-pages', [
                'name' => 'Cloned Template',
            ]);

        $response->assertCreated();

        $cloned = LandingPageTemplate::where('name', 'Cloned Template')->firstOrFail();

        expect($cloned->right_column_order)->toBe($defaultTemplate->right_column_order)
            ->and($cloned->left_column_order)->toBe($defaultTemplate->left_column_order)
            ->and($cloned->logo_path)->toBeNull()
            ->and($cloned->created_by)->toBe($user->id);
    });

    it('rejects cloning with duplicate name', function (): void {
        /** @var TestCase $this */
        $user = User::factory()->create(['role' => UserRole::ADMIN]);
        $this->actingAs($user);

        LandingPageTemplate::factory()->default()->create();
        LandingPageTemplate::factory()->create(['name' => 'Existing Template']);

        $response = $this->actingAs($user)
            ->postJson('/landing-pages', [
                'name' => 'Existing Template',
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    });

    it('rejects cloning without a name', function (): void {
        /** @var TestCase $this */
        $user = User::factory()->create(['role' => UserRole::ADMIN]);
        $this->actingAs($user);

        LandingPageTemplate::factory()->default()->create();

        $response = $this->actingAs($user)
            ->postJson('/landing-pages', [
                'name' => '',
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    });

    it('denies cloning for beginners', function (): void {
        /** @var TestCase $this */
        $user = User::factory()->create(['role' => UserRole::BEGINNER]);
        $this->actingAs($user);

        LandingPageTemplate::factory()->default()->create();

        $response = $this->actingAs($user)
            ->postJson('/landing-pages', [
                'name' => 'Unauthorized Clone',
            ]);

        $response->assertForbidden();

        $this->assertDatabaseMissing('landing_page_templates', [
            'name' => 'Unauthorized Clone',
        ]);
    });

    it('denies cloning for curators', function (): void {
        /** @var TestCase $this */
        $user = User::factory()->create(['role' => UserRole::CURATOR]);
        $this->actingAs($user);

        LandingPageTemplate::factory()->default()->create();

        $response = $this->actingAs($user)
            ->postJson('/landing-pages', [
                'name' => 'Unauthorized Clone',
            ]);

        $response->assertForbidden();
    });

    it('allows group leaders to clone', function (): void {
        /** @var TestCase $this */
        $user = User::factory()->create(['role' => UserRole::GROUP_LEADER]);
        $this->actingAs($user);

        LandingPageTemplate::factory()->default()->create();

        $response = $this->actingAs($user)
            ->postJson('/landing-pages', [
                'name' => 'Group Leader Template',
            ]);

        $response->assertCreated();

        $this->assertDatabaseHas('landing_page_templates', [
            'name' => 'Group Leader Template',
            'created_by' => $user->id,
        ]);
    });
});
