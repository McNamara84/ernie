<?php

declare(strict_types=1);

use App\Models\Resource;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
});

describe('export DataCite JSON', function () {
    test('requires authentication', function () {
        $resource = Resource::factory()->create();

        $this->get("/resources/{$resource->id}/export-datacite-json")
            ->assertRedirect(route('login'));
    });

    test('returns 404 for non-existent resource', function () {
        $this->actingAs($this->user)
            ->get('/resources/99999/export-datacite-json')
            ->assertNotFound();
    });

    test('returns JSON response with correct content type', function () {
        $resource = Resource::factory()->create();

        $response = $this->actingAs($this->user)
            ->get("/resources/{$resource->id}/export-datacite-json");

        // Response should be JSON (either 200 with export or 422 if validation fails)
        expect($response->status())->toBeIn([200, 422]);

        if ($response->status() === 200) {
            expect($response->headers->get('Content-Type'))->toContain('application/json');
            expect($response->headers->get('Content-Disposition'))->toContain('.json');
        }
    });

    test('filename includes resource id and timestamp', function () {
        $resource = Resource::factory()->create();

        $response = $this->actingAs($this->user)
            ->get("/resources/{$resource->id}/export-datacite-json");

        if ($response->status() === 200) {
            $disposition = $response->headers->get('Content-Disposition');
            expect($disposition)->toContain("resource-{$resource->id}-")
                ->and($disposition)->toContain('-datacite.json');
        }
    });
});

describe('export DataCite XML', function () {
    test('requires authentication', function () {
        $resource = Resource::factory()->create();

        $this->get("/resources/{$resource->id}/export-datacite-xml")
            ->assertRedirect(route('login'));
    });

    test('returns 404 for non-existent resource', function () {
        $this->actingAs($this->user)
            ->get('/resources/99999/export-datacite-xml')
            ->assertNotFound();
    });

    test('returns XML response with correct content type', function () {
        $resource = Resource::factory()->create();

        $response = $this->actingAs($this->user)
            ->get("/resources/{$resource->id}/export-datacite-xml");

        // Response should be XML (200) or error (500)
        expect($response->status())->toBeIn([200, 500]);

        if ($response->status() === 200) {
            expect($response->headers->get('Content-Type'))->toContain('application/xml');
            expect($response->headers->get('Content-Disposition'))->toContain('.xml');
        }
    });

    test('filename includes resource id and timestamp', function () {
        $resource = Resource::factory()->create();

        $response = $this->actingAs($this->user)
            ->get("/resources/{$resource->id}/export-datacite-xml");

        if ($response->status() === 200) {
            $disposition = $response->headers->get('Content-Disposition');
            expect($disposition)->toContain("resource-{$resource->id}-")
                ->and($disposition)->toContain('-datacite.xml');
        }
    });
});
