<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\LandingPage;
use App\Models\Resource;
use App\Models\User;

/**
 * Policy Tests for Landing Page Management Authorization
 *
 * Tests the LandingPagePolicy which allows ADMIN, GROUP_LEADER, and CURATOR roles
 * to manage landing pages, while BEGINNER users are denied access.
 *
 * @see App\Policies\LandingPagePolicy
 * @see Issue #375 - Enable subsequent modification of the landing page template
 */
uses()->group('landing-pages', 'authorization');

describe('Landing Page Authorization for Curators and Above', function () {
    test('admin can create a landing page', function () {
        $user = User::factory()->create(['role' => UserRole::ADMIN]);
        $resource = Resource::factory()->create();

        $response = $this->actingAs($user)
            ->postJson("/resources/{$resource->id}/landing-page", [
                'template' => 'default_gfz',
                'is_published' => false,
            ]);

        $response->assertCreated();
    });

    test('group leader can create a landing page', function () {
        $user = User::factory()->create(['role' => UserRole::GROUP_LEADER]);
        $resource = Resource::factory()->create();

        $response = $this->actingAs($user)
            ->postJson("/resources/{$resource->id}/landing-page", [
                'template' => 'default_gfz',
                'is_published' => false,
            ]);

        $response->assertCreated();
    });

    test('curator can create a landing page', function () {
        $user = User::factory()->create(['role' => UserRole::CURATOR]);
        $resource = Resource::factory()->create();

        $response = $this->actingAs($user)
            ->postJson("/resources/{$resource->id}/landing-page", [
                'template' => 'default_gfz',
                'is_published' => false,
            ]);

        $response->assertCreated();
    });

    test('curator can update a landing page', function () {
        $user = User::factory()->create(['role' => UserRole::CURATOR]);
        $resource = Resource::factory()->create();
        LandingPage::factory()->create([
            'resource_id' => $resource->id,
            'template' => 'default_gfz',
            'is_published' => false,
        ]);

        $response = $this->actingAs($user)
            ->putJson("/resources/{$resource->id}/landing-page", [
                'template' => 'default_gfz',
                'ftp_url' => 'https://datapub.gfz-potsdam.de/download/updated',
            ]);

        $response->assertOk();
    });

    test('curator can delete a landing page', function () {
        $user = User::factory()->create(['role' => UserRole::CURATOR]);
        $resource = Resource::factory()->create();
        LandingPage::factory()->create([
            'resource_id' => $resource->id,
            'is_published' => false,
        ]);

        $response = $this->actingAs($user)
            ->deleteJson("/resources/{$resource->id}/landing-page");

        $response->assertOk();
    });
});

describe('Landing Page Authorization Denied for Beginners', function () {
    test('beginner cannot create a landing page', function () {
        $user = User::factory()->create(['role' => UserRole::BEGINNER]);
        $resource = Resource::factory()->create();

        $response = $this->actingAs($user)
            ->postJson("/resources/{$resource->id}/landing-page", [
                'template' => 'default_gfz',
                'is_published' => false,
            ]);

        $response->assertForbidden();
    });

    test('beginner cannot update a landing page', function () {
        $user = User::factory()->create(['role' => UserRole::BEGINNER]);
        $resource = Resource::factory()->create();
        LandingPage::factory()->create([
            'resource_id' => $resource->id,
            'template' => 'default_gfz',
            'is_published' => false,
        ]);

        $response = $this->actingAs($user)
            ->putJson("/resources/{$resource->id}/landing-page", [
                'ftp_url' => 'https://datapub.gfz-potsdam.de/download/updated',
            ]);

        $response->assertForbidden();
    });

    test('beginner cannot delete a landing page', function () {
        $user = User::factory()->create(['role' => UserRole::BEGINNER]);
        $resource = Resource::factory()->create();
        LandingPage::factory()->create([
            'resource_id' => $resource->id,
            'is_published' => false,
        ]);

        $response = $this->actingAs($user)
            ->deleteJson("/resources/{$resource->id}/landing-page");

        $response->assertForbidden();
    });
});

describe('Landing Page Gate Check', function () {
    test('manage-landing-pages gate returns true for curators', function () {
        $user = User::factory()->create(['role' => UserRole::CURATOR]);

        $this->actingAs($user);

        expect($user->can('manage-landing-pages'))->toBeTrue();
    });

    test('manage-landing-pages gate returns true for group leaders', function () {
        $user = User::factory()->create(['role' => UserRole::GROUP_LEADER]);

        $this->actingAs($user);

        expect($user->can('manage-landing-pages'))->toBeTrue();
    });

    test('manage-landing-pages gate returns true for admins', function () {
        $user = User::factory()->create(['role' => UserRole::ADMIN]);

        $this->actingAs($user);

        expect($user->can('manage-landing-pages'))->toBeTrue();
    });

    test('manage-landing-pages gate returns false for beginners', function () {
        $user = User::factory()->create(['role' => UserRole::BEGINNER]);

        $this->actingAs($user);

        expect($user->can('manage-landing-pages'))->toBeFalse();
    });
});
