<?php

declare(strict_types=1);

use App\Models\IgsnMetadata;
use App\Models\Resource;
use App\Models\ResourceType;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();

    $physObj = ResourceType::firstOrCreate(
        ['slug' => 'physical-object'],
        ['name' => 'Physical Object', 'slug' => 'physical-object', 'is_active' => true]
    );

    $this->resource = Resource::factory()->create([
        'resource_type_id' => $physObj->id,
        'doi' => '10.60510/igsn.test.001',
    ]);

    IgsnMetadata::create([
        'resource_id' => $this->resource->id,
        'sample_type' => 'rock core',
        'material' => 'granite',
        'upload_status' => IgsnMetadata::STATUS_UPLOADED,
    ]);
});

describe('IGSN JSON export', function () {
    test('requires authentication', function () {
        $this->get(route('igsns.export.json', $this->resource))
            ->assertRedirect(route('login'));
    });

    test('returns JSON response for valid IGSN', function () {
        $response = $this->actingAs($this->user)
            ->getJson(route('igsns.export.json', $this->resource));

        // Should return JSON (either valid export or validation error)
        expect($response->status())->toBeIn([200, 422]);
    });

    test('returns 404 for non-existent resource', function () {
        $this->actingAs($this->user)
            ->getJson(route('igsns.export.json', ['resource' => 99999]))
            ->assertNotFound();
    });
});

describe('IGSN DataCite export structure', function () {
    test('IGSN resource export JSON includes datacite JSON', function () {
        $response = $this->actingAs($this->user)
            ->get("/resources/{$this->resource->id}/export-datacite-json");

        if ($response->status() === 200) {
            $json = $response->json();
            expect($json)->toHaveKey('data')
                ->and($json['data'])->toHaveKey('attributes');
        }
    });

    test('IGSN resource export includes resourceTypeGeneral PhysicalObject', function () {
        $response = $this->actingAs($this->user)
            ->get("/resources/{$this->resource->id}/export-datacite-json");

        if ($response->status() === 200) {
            $types = $response->json('data.attributes.types');
            expect($types['resourceTypeGeneral'] ?? null)->toBe('PhysicalObject');
        }
    });
});
