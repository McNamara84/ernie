<?php

declare(strict_types=1);

use App\Http\Controllers\LandingPageController;
use App\Models\LandingPage;
use App\Models\LandingPageLink;
use App\Models\Resource;
use App\Models\ResourceType;
use App\Models\User;

covers(LandingPageController::class);

uses()->group('landing-pages', 'landing-page-links');

beforeEach(function () {
    $this->user = User::factory()->curator()->create();
    $this->actingAs($this->user);

    $this->resource = Resource::factory()->create([
        'created_by_user_id' => $this->user->id,
    ]);
});

describe('Landing Page Links - Store', function () {
    test('can create landing page with additional links', function () {
        $response = $this->postJson("/resources/{$this->resource->id}/landing-page", [
            'template' => 'default_gfz',
            'status' => 'draft',
            'links' => [
                ['url' => 'https://gitlab.com/example/repo', 'label' => 'GitLab Repository', 'position' => 0],
                ['url' => 'https://example.com/project', 'label' => 'Project Website', 'position' => 1],
            ],
        ]);

        $response->assertStatus(201);

        $landingPage = $this->resource->fresh()->landingPage;
        expect($landingPage->links)->toHaveCount(2);
        expect($landingPage->links[0]->label)->toBe('GitLab Repository');
        expect($landingPage->links[0]->url)->toBe('https://gitlab.com/example/repo');
        expect($landingPage->links[0]->position)->toBe(0);
        expect($landingPage->links[1]->label)->toBe('Project Website');
        expect($landingPage->links[1]->position)->toBe(1);
    });

    test('can create landing page without links', function () {
        $response = $this->postJson("/resources/{$this->resource->id}/landing-page", [
            'template' => 'default_gfz',
            'status' => 'draft',
        ]);

        $response->assertStatus(201);

        $landingPage = $this->resource->fresh()->landingPage;
        expect($landingPage->links)->toHaveCount(0);
    });

    test('limits links to 10 per landing page', function () {
        $links = array_map(
            fn (int $i) => ['url' => "https://example.com/link-{$i}", 'label' => "Link {$i}", 'position' => $i],
            range(0, 10), // 11 links
        );

        $response = $this->postJson("/resources/{$this->resource->id}/landing-page", [
            'template' => 'default_gfz',
            'status' => 'draft',
            'links' => $links,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('links');
    });

    test('validates link URL format', function () {
        $response = $this->postJson("/resources/{$this->resource->id}/landing-page", [
            'template' => 'default_gfz',
            'status' => 'draft',
            'links' => [
                ['url' => 'not-a-valid-url', 'label' => 'Bad Link', 'position' => 0],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('links.0.url');
    });

    test('validates link label is required', function () {
        $response = $this->postJson("/resources/{$this->resource->id}/landing-page", [
            'template' => 'default_gfz',
            'status' => 'draft',
            'links' => [
                ['url' => 'https://example.com', 'label' => '', 'position' => 0],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('links.0.label');
    });

    test('does not accept links for IGSN templates', function () {
        $physicalObjectType = ResourceType::firstOrCreate(
            ['slug' => 'physical-object'],
            ['name' => 'Physical Object', 'slug' => 'physical-object', 'is_active' => true],
        );
        $resource = Resource::factory()->create([
            'resource_type_id' => $physicalObjectType->id,
            'created_by_user_id' => $this->user->id,
        ]);

        $response = $this->postJson("/resources/{$resource->id}/landing-page", [
            'template' => 'default_gfz_igsn',
            'status' => 'draft',
            'links' => [
                ['url' => 'https://example.com', 'label' => 'Test', 'position' => 0],
            ],
        ]);

        $response->assertStatus(201);

        $landingPage = $resource->fresh()->landingPage;
        expect($landingPage->links)->toHaveCount(0);
    });

    test('does not accept links for external templates', function () {
        $domain = \App\Models\LandingPageDomain::factory()->create();

        $response = $this->postJson("/resources/{$this->resource->id}/landing-page", [
            'template' => 'external',
            'status' => 'draft',
            'external_domain_id' => $domain->id,
            'external_path' => '/some/path',
            'links' => [
                ['url' => 'https://example.com', 'label' => 'Test', 'position' => 0],
            ],
        ]);

        $response->assertStatus(201);

        $landingPage = $this->resource->fresh()->landingPage;
        expect($landingPage->links)->toHaveCount(0);
    });
});

describe('Landing Page Links - Update', function () {
    test('can update landing page links using sync strategy', function () {
        $landingPage = LandingPage::factory()->draft()->create([
            'resource_id' => $this->resource->id,
        ]);
        $landingPage->links()->createMany([
            ['url' => 'https://old.example.com', 'label' => 'Old Link', 'position' => 0],
        ]);

        $response = $this->putJson("/resources/{$this->resource->id}/landing-page", [
            'links' => [
                ['url' => 'https://new.example.com', 'label' => 'New Link', 'position' => 0],
                ['url' => 'https://other.example.com', 'label' => 'Other Link', 'position' => 1],
            ],
        ]);

        $response->assertStatus(200);

        $updatedLinks = $landingPage->fresh()->links;
        expect($updatedLinks)->toHaveCount(2);
        expect($updatedLinks[0]->label)->toBe('New Link');
        expect($updatedLinks[1]->label)->toBe('Other Link');
    });

    test('can remove all links by sending empty array', function () {
        $landingPage = LandingPage::factory()->draft()->create([
            'resource_id' => $this->resource->id,
        ]);
        $landingPage->links()->createMany([
            ['url' => 'https://example.com', 'label' => 'Link', 'position' => 0],
        ]);

        $response = $this->putJson("/resources/{$this->resource->id}/landing-page", [
            'links' => [],
        ]);

        $response->assertStatus(200);
        expect($landingPage->fresh()->links)->toHaveCount(0);
    });

    test('clears links when switching to external template', function () {
        $domain = \App\Models\LandingPageDomain::factory()->create();

        $landingPage = LandingPage::factory()->draft()->create([
            'resource_id' => $this->resource->id,
        ]);
        $landingPage->links()->createMany([
            ['url' => 'https://example.com', 'label' => 'Link', 'position' => 0],
        ]);

        $response = $this->putJson("/resources/{$this->resource->id}/landing-page", [
            'template' => 'external',
            'external_domain_id' => $domain->id,
            'external_path' => '/some/path',
        ]);

        $response->assertStatus(200);
        expect($landingPage->fresh()->links)->toHaveCount(0);
    });
});

describe('Landing Page Links - Get', function () {
    test('returns links in get endpoint', function () {
        $landingPage = LandingPage::factory()->draft()->create([
            'resource_id' => $this->resource->id,
        ]);
        $landingPage->links()->createMany([
            ['url' => 'https://gitlab.com/repo', 'label' => 'Repository', 'position' => 0],
            ['url' => 'https://example.com', 'label' => 'Website', 'position' => 1],
        ]);

        $response = $this->getJson("/resources/{$this->resource->id}/landing-page");

        $response->assertStatus(200)
            ->assertJsonCount(2, 'landing_page.links')
            ->assertJsonPath('landing_page.links.0.label', 'Repository')
            ->assertJsonPath('landing_page.links.1.label', 'Website');
    });

    test('links are ordered by position', function () {
        $landingPage = LandingPage::factory()->draft()->create([
            'resource_id' => $this->resource->id,
        ]);
        // Create in reverse order to test ordering
        $landingPage->links()->createMany([
            ['url' => 'https://second.com', 'label' => 'Second', 'position' => 1],
            ['url' => 'https://first.com', 'label' => 'First', 'position' => 0],
        ]);

        $response = $this->getJson("/resources/{$this->resource->id}/landing-page");

        $response->assertStatus(200)
            ->assertJsonPath('landing_page.links.0.label', 'First')
            ->assertJsonPath('landing_page.links.1.label', 'Second');
    });
});

describe('Landing Page Links - Cascade Delete', function () {
    test('cascades link deletion when landing page is deleted', function () {
        $landingPage = LandingPage::factory()->draft()->create([
            'resource_id' => $this->resource->id,
        ]);
        $landingPage->links()->createMany([
            ['url' => 'https://example.com', 'label' => 'Link', 'position' => 0],
        ]);

        expect(LandingPageLink::count())->toBe(1);

        $this->deleteJson("/resources/{$this->resource->id}/landing-page")
            ->assertStatus(200);

        expect(LandingPageLink::count())->toBe(0);
    });
});
