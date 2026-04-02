<?php

declare(strict_types=1);

use App\Http\Controllers\DatacenterController;
use App\Models\Datacenter;
use App\Models\Resource;
use App\Models\User;

covers(DatacenterController::class);

uses()->group('datacenters');

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->groupLeader = User::factory()->groupLeader()->create();
    $this->curator = User::factory()->curator()->create();
    $this->beginner = User::factory()->beginner()->create();
});

describe('Datacenter Listing', function () {
    test('authenticated users can list datacenters', function () {
        Datacenter::factory()->withName('GFZ Potsdam')->create();
        Datacenter::factory()->withName('AWI Bremerhaven')->create();

        $response = $this->actingAs($this->beginner)
            ->getJson('/api/datacenters');

        $response->assertOk()
            ->assertJsonCount(2)
            ->assertJsonStructure([['id', 'name']]);
    });

    test('datacenters are ordered alphabetically', function () {
        Datacenter::factory()->withName('ZZZ Last')->create();
        Datacenter::factory()->withName('AAA First')->create();

        $response = $this->actingAs($this->curator)
            ->getJson('/api/datacenters');

        $response->assertOk();
        $datacenters = $response->json();
        expect($datacenters[0]['name'])->toBe('AAA First');
        expect($datacenters[1]['name'])->toBe('ZZZ Last');
    });

    test('unauthenticated users cannot list datacenters', function () {
        $response = $this->getJson('/api/datacenters');

        $response->assertUnauthorized();
    });

    test('empty list returns empty array', function () {
        $response = $this->actingAs($this->beginner)
            ->getJson('/api/datacenters');

        $response->assertOk()
            ->assertJsonCount(0);
    });
});

describe('Datacenter Creation', function () {
    test('admin can create a datacenter', function () {
        $response = $this->actingAs($this->admin)
            ->postJson('/api/datacenters', [
                'name' => 'GFZ Potsdam',
            ]);

        $response->assertCreated()
            ->assertJson([
                'datacenter' => [
                    'name' => 'GFZ Potsdam',
                    'resources_count' => 0,
                ],
                'message' => 'Datacenter created successfully.',
            ]);

        expect(Datacenter::where('name', 'GFZ Potsdam')->exists())->toBeTrue();
    });

    test('group leader can create a datacenter', function () {
        $response = $this->actingAs($this->groupLeader)
            ->postJson('/api/datacenters', [
                'name' => 'AWI Bremerhaven',
            ]);

        $response->assertCreated();
        expect(Datacenter::where('name', 'AWI Bremerhaven')->exists())->toBeTrue();
    });

    test('curator cannot create a datacenter', function () {
        $response = $this->actingAs($this->curator)
            ->postJson('/api/datacenters', [
                'name' => 'Test Datacenter',
            ]);

        $response->assertForbidden();
        expect(Datacenter::count())->toBe(0);
    });

    test('beginner cannot create a datacenter', function () {
        $response = $this->actingAs($this->beginner)
            ->postJson('/api/datacenters', [
                'name' => 'Test Datacenter',
            ]);

        $response->assertForbidden();
        expect(Datacenter::count())->toBe(0);
    });

    test('name is trimmed before validation', function () {
        $response = $this->actingAs($this->admin)
            ->postJson('/api/datacenters', [
                'name' => '  GFZ Potsdam  ',
            ]);

        $response->assertCreated();
        expect(Datacenter::first()->name)->toBe('GFZ Potsdam');
    });

    test('duplicate name returns 422', function () {
        Datacenter::factory()->withName('GFZ Potsdam')->create();

        $response = $this->actingAs($this->admin)
            ->postJson('/api/datacenters', [
                'name' => 'GFZ Potsdam',
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    });

    test('trimmed duplicate name returns 422', function () {
        Datacenter::factory()->withName('GFZ Potsdam')->create();

        $response = $this->actingAs($this->admin)
            ->postJson('/api/datacenters', [
                'name' => '  GFZ Potsdam  ',
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    });

    test('empty name is rejected', function () {
        $response = $this->actingAs($this->admin)
            ->postJson('/api/datacenters', [
                'name' => '',
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    });

    test('missing name is rejected', function () {
        $response = $this->actingAs($this->admin)
            ->postJson('/api/datacenters', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    });

    test('name exceeding 255 characters is rejected', function () {
        $response = $this->actingAs($this->admin)
            ->postJson('/api/datacenters', [
                'name' => str_repeat('x', 256),
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    });
});

describe('Datacenter Deletion', function () {
    test('admin can delete an unused datacenter', function () {
        $datacenter = Datacenter::factory()->withName('Unused DC')->create();

        $response = $this->actingAs($this->admin)
            ->deleteJson("/api/datacenters/{$datacenter->id}");

        $response->assertOk()
            ->assertJson(['message' => 'Datacenter deleted successfully.']);

        expect(Datacenter::find($datacenter->id))->toBeNull();
    });

    test('group leader can delete an unused datacenter', function () {
        $datacenter = Datacenter::factory()->create();

        $response = $this->actingAs($this->groupLeader)
            ->deleteJson("/api/datacenters/{$datacenter->id}");

        $response->assertOk();
        expect(Datacenter::find($datacenter->id))->toBeNull();
    });

    test('cannot delete a datacenter with assigned resources', function () {
        $datacenter = Datacenter::factory()->withName('Busy DC')->create();
        $resource = Resource::factory()->create();
        $resource->datacenters()->attach($datacenter->id);

        $response = $this->actingAs($this->admin)
            ->deleteJson("/api/datacenters/{$datacenter->id}");

        $response->assertUnprocessable()
            ->assertJson(['message' => 'Cannot delete datacenter with assigned resources.']);

        expect(Datacenter::find($datacenter->id))->not->toBeNull();
    });

    test('curator cannot delete a datacenter', function () {
        $datacenter = Datacenter::factory()->create();

        $response = $this->actingAs($this->curator)
            ->deleteJson("/api/datacenters/{$datacenter->id}");

        $response->assertForbidden();
        expect(Datacenter::find($datacenter->id))->not->toBeNull();
    });

    test('beginner cannot delete a datacenter', function () {
        $datacenter = Datacenter::factory()->create();

        $response = $this->actingAs($this->beginner)
            ->deleteJson("/api/datacenters/{$datacenter->id}");

        $response->assertForbidden();
        expect(Datacenter::find($datacenter->id))->not->toBeNull();
    });

    test('returns 404 for non-existent datacenter', function () {
        $response = $this->actingAs($this->admin)
            ->deleteJson('/api/datacenters/99999');

        $response->assertNotFound();
    });
});
