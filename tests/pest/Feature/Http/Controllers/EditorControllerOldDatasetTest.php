<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->user = User::factory()->create(['role' => 'curator']);
    $this->actingAs($this->user);

    // Skip if metaworks connection is not configured
    try {
        DB::connection('metaworks')->getPdo();
    } catch (Exception $e) {
        $this->markTestSkipped('Metaworks database connection not available');
    }
});

it('can load editor with old dataset id parameter', function () {
    $response = $this->get('/editor?oldDatasetId=2413');

    $response->assertStatus(200)
        ->assertInertia(fn (Assert $page) => $page
            ->component('editor')
            ->has('initialTitles')
            ->has('initialAuthors')
            ->has('initialDescriptions')
            ->has('initialLicenses')
        );
});

it('loads old dataset with correct title types', function () {
    $response = $this->get('/editor?oldDatasetId=2413');

    $response->assertStatus(200)
        ->assertInertia(fn (Assert $page) => $page
            ->component('editor')
            ->where('initialTitles.0.titleType', 'main-title')
        );
});

it('loads old dataset with mapped licenses', function () {
    $response = $this->get('/editor?oldDatasetId=2413');

    $response->assertStatus(200)
        ->assertInertia(fn (Assert $page) => $page
            ->component('editor')
            ->has('initialLicenses')
            ->where('initialLicenses.0', fn ($license) => is_string($license) && strlen($license) > 0
            )
        );
});

it('returns 404 for non-existent old dataset', function () {
    $response = $this->get('/editor?oldDatasetId=999999');

    $response->assertStatus(404);
});

it('validates oldDatasetId is numeric', function () {
    $response = $this->get('/editor?oldDatasetId=invalid');

    $response->assertStatus(302); // Redirect with validation error
});

it('handles missing oldDatasetId parameter gracefully', function () {
    $response = $this->get('/editor');

    $response->assertStatus(200)
        ->assertInertia(fn (Assert $page) => $page
            ->component('editor')
            ->where('initialTitles', [])
            ->where('initialAuthors', [])
            ->where('initialDescriptions', [])
            ->where('initialLicenses', [])
        );
});

it('loads old dataset without uri parameter overflow', function () {
    // This test verifies that large datasets don't cause 414 URI Too Long errors
    // by using the new service-based approach instead of URL parameters

    $response = $this->get('/editor?oldDatasetId=2413');

    // Should succeed without 414 error
    $response->assertStatus(200);

    // Verify URL doesn't contain large data parameters
    expect($response->getContent())
        ->not->toContain('initialTitles[')
        ->and($response->getContent())
        ->not->toContain('initialAuthors[');
});
