<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('EditorController - JSON session loading', function () {
    test('loads editor with JSON session data', function () {
        $user = User::factory()->create();

        $sessionKey = 'json_upload_test1234567890abcdef';
        $sessionData = [
            'doi' => '10.5880/test-json',
            'year' => '2025',
            'version' => '1.0',
            'language' => 'en',
            'resourceType' => null,
            'titles' => [['title' => 'Test JSON Upload', 'titleType' => 'main-title', 'language' => 'en']],
            'licenses' => ['CC-BY-4.0'],
            'authors' => [['type' => 'person', 'firstName' => 'Jane', 'lastName' => 'Doe', 'orcid' => '', 'affiliations' => []]],
            'contributors' => [],
            'descriptions' => [['type' => 'abstract', 'description' => 'Test description']],
            'dates' => [],
            'gcmdKeywords' => [],
            'freeKeywords' => ['test'],
            'mslKeywords' => [],
            'gemetKeywords' => [],
            'coverages' => [],
            'relatedWorks' => [],
            'instruments' => [],
            'fundingReferences' => [],
            'mslLaboratories' => [],
        ];

        session()->put($sessionKey, $sessionData);

        $response = $this->actingAs($user)->get('/editor?jsonSession=' . $sessionKey);

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('editor')
            ->where('doi', '10.5880/test-json')
            ->where('year', '2025')
            ->where('freeKeywords', ['test'])
            ->has('titles', 1)
            ->has('authors', 1)
        );
    });

    test('rejects JSON session with invalid prefix', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/editor?jsonSession=evil_prefix_abc123');

        $response->assertStatus(400);
    });

    test('redirects to dashboard when JSON session expired', function () {
        $user = User::factory()->create();

        $sessionKey = 'json_upload_expired1234567890abc';
        // Do not put anything in the session → simulates expired

        $response = $this->actingAs($user)->get('/editor?jsonSession=' . $sessionKey);

        $response->assertRedirect(route('dashboard'));
        $response->assertSessionHas('error');
    });
});
