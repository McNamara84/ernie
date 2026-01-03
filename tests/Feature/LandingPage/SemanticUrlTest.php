<?php

declare(strict_types=1);

use App\Models\LandingPage;
use App\Models\Resource;
use App\Models\Title;
use App\Models\TitleType;

uses()->group('landing-pages', 'semantic-urls');

beforeEach(function () {
    $this->resource = Resource::factory()->create([
        'doi' => '10.5880/test.dataset.001',
    ]);

    // Create a main title for the resource
    $mainTitleType = TitleType::factory()->create(['slug' => 'main-title']);
    Title::factory()->create([
        'resource_id' => $this->resource->id,
        'title_type_id' => $mainTitleType->id,
        'value' => 'Superconducting Gravimeter Data from Buchenbach',
    ]);
});

describe('DOI-based Landing Page URLs', function () {
    test('can access published landing page via DOI URL', function () {
        $landingPage = LandingPage::factory()
            ->published()
            ->create([
                'resource_id' => $this->resource->id,
                'doi_prefix' => '10.5880/test.dataset.001',
                'slug' => 'superconducting-gravimeter-data-from-buchenbach',
                'template' => 'default_gfz',
            ]);

        $response = $this->get('/10.5880/test.dataset.001/superconducting-gravimeter-data-from-buchenbach');

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('LandingPages/default_gfz')
                ->has('resource')
                ->has('landingPage')
                ->where('isPreview', false)
            );
    });

    test('returns 404 for non-existent DOI URL', function () {
        $response = $this->get('/10.5880/nonexistent/some-slug');

        $response->assertStatus(404);
    });

    test('returns 404 for wrong slug with correct DOI', function () {
        LandingPage::factory()
            ->published()
            ->create([
                'resource_id' => $this->resource->id,
                'doi_prefix' => '10.5880/test.dataset.001',
                'slug' => 'correct-slug',
            ]);

        $response = $this->get('/10.5880/test.dataset.001/wrong-slug');

        $response->assertStatus(404);
    });

    test('can access draft landing page with preview token via DOI URL', function () {
        $landingPage = LandingPage::factory()
            ->draft()
            ->create([
                'resource_id' => $this->resource->id,
                'doi_prefix' => '10.5880/test.dataset.001',
                'slug' => 'my-draft-dataset',
                'template' => 'default_gfz',
            ]);

        $response = $this->get("/10.5880/test.dataset.001/my-draft-dataset?preview={$landingPage->preview_token}");

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->where('isPreview', true)
            );
    });

    test('cannot access draft landing page without preview token via DOI URL', function () {
        LandingPage::factory()
            ->draft()
            ->create([
                'resource_id' => $this->resource->id,
                'doi_prefix' => '10.5880/test.dataset.001',
                'slug' => 'my-draft-dataset',
            ]);

        $response = $this->get('/10.5880/test.dataset.001/my-draft-dataset');

        $response->assertStatus(404);
    });
});

describe('Draft-based Landing Page URLs', function () {
    beforeEach(function () {
        // Create a resource without DOI
        $this->draftResource = Resource::factory()->create([
            'doi' => null,
        ]);
    });

    test('can access draft landing page via draft URL with preview token', function () {
        $landingPage = LandingPage::factory()
            ->draft()
            ->create([
                'resource_id' => $this->draftResource->id,
                'doi_prefix' => null,
                'slug' => 'my-draft-dataset',
                'template' => 'default_gfz',
            ]);

        $response = $this->get("/draft-{$this->draftResource->id}/my-draft-dataset?preview={$landingPage->preview_token}");

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('LandingPages/default_gfz')
                ->where('isPreview', true)
            );
    });

    test('cannot access draft landing page via draft URL without preview token', function () {
        LandingPage::factory()
            ->draft()
            ->create([
                'resource_id' => $this->draftResource->id,
                'doi_prefix' => null,
                'slug' => 'my-draft-dataset',
            ]);

        $response = $this->get("/draft-{$this->draftResource->id}/my-draft-dataset");

        $response->assertStatus(404);
    });

    test('returns 404 for non-existent draft URL', function () {
        $response = $this->get('/draft-99999/some-slug');

        $response->assertStatus(404);
    });

    test('returns 404 for draft URL when landing page has DOI', function () {
        LandingPage::factory()
            ->published()
            ->create([
                'resource_id' => $this->resource->id,
                'doi_prefix' => '10.5880/test.dataset.001',
                'slug' => 'my-dataset',
            ]);

        // Try to access via draft URL - should fail because landing page has DOI
        $response = $this->get("/draft-{$this->resource->id}/my-dataset");

        $response->assertStatus(404);
    });
});

describe('Legacy URL Redirect', function () {
    test('legacy URL redirects to new DOI-based URL', function () {
        $landingPage = LandingPage::factory()
            ->published()
            ->create([
                'resource_id' => $this->resource->id,
                'doi_prefix' => '10.5880/test.dataset.001',
                'slug' => 'my-dataset-title',
            ]);

        $response = $this->get("/datasets/{$this->resource->id}");

        $response->assertStatus(301)
            ->assertRedirect($landingPage->public_url);
    });

    test('legacy URL redirects to draft URL when no DOI', function () {
        $resourceWithoutDoi = Resource::factory()->create(['doi' => null]);
        $landingPage = LandingPage::factory()
            ->draft()
            ->create([
                'resource_id' => $resourceWithoutDoi->id,
                'doi_prefix' => null,
                'slug' => 'draft-dataset',
            ]);

        $response = $this->get("/datasets/{$resourceWithoutDoi->id}");

        $response->assertStatus(301)
            ->assertRedirect($landingPage->public_url);
    });

    test('legacy URL returns 404 when landing page does not exist', function () {
        $resourceWithoutLandingPage = Resource::factory()->create();

        $response = $this->get("/datasets/{$resourceWithoutLandingPage->id}");

        $response->assertStatus(404);
    });
});

describe('URL Generation', function () {
    test('public_url attribute returns DOI-based URL when DOI exists', function () {
        $landingPage = LandingPage::factory()
            ->published()
            ->create([
                'resource_id' => $this->resource->id,
                'doi_prefix' => '10.5880/test.dataset.001',
                'slug' => 'my-dataset',
            ]);

        expect($landingPage->public_url)->toContain('/10.5880/test.dataset.001/my-dataset');
    });

    test('public_url attribute returns draft URL when no DOI', function () {
        $resourceWithoutDoi = Resource::factory()->create(['doi' => null]);
        $landingPage = LandingPage::factory()
            ->draft()
            ->create([
                'resource_id' => $resourceWithoutDoi->id,
                'doi_prefix' => null,
                'slug' => 'draft-dataset',
            ]);

        expect($landingPage->public_url)->toContain("/draft-{$resourceWithoutDoi->id}/draft-dataset");
    });

    test('preview_url attribute includes preview token', function () {
        $landingPage = LandingPage::factory()
            ->draft()
            ->create([
                'resource_id' => $this->resource->id,
                'doi_prefix' => '10.5880/test.dataset.001',
                'slug' => 'my-dataset',
            ]);

        expect($landingPage->preview_url)
            ->toContain('/10.5880/test.dataset.001/my-dataset')
            ->toContain('preview=')
            ->toContain($landingPage->preview_token);
    });
});
