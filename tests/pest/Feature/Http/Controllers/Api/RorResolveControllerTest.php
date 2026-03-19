<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\RorLookupService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
});

describe('ROR Resolve Controller', function () {
    test('resolves organization names to ROR IDs', function () {
        $mockService = Mockery::mock(RorLookupService::class);
        $mockService->shouldReceive('findByName')
            ->with('GFZ German Research Centre for Geosciences')
            ->andReturn([
                'value' => 'GFZ German Research Centre for Geosciences',
                'rorId' => 'https://ror.org/04z8jg394',
            ]);
        $mockService->shouldReceive('findByName')
            ->with('Unknown Institution')
            ->andReturn(null);

        $this->app->instance(RorLookupService::class, $mockService);

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/ror-resolve', [
                'names' => [
                    'GFZ German Research Centre for Geosciences',
                    'Unknown Institution',
                ],
            ]);

        $response->assertOk()
            ->assertJsonCount(1, 'results')
            ->assertJsonPath('results.0.name', 'GFZ German Research Centre for Geosciences')
            ->assertJsonPath('results.0.rorId', 'https://ror.org/04z8jg394')
            ->assertJsonPath('results.0.matchedName', 'GFZ German Research Centre for Geosciences');
    });

    test('validates names is required', function () {
        $this->actingAs($this->user)
            ->postJson('/api/v1/ror-resolve', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['names']);
    });

    test('validates names must be an array', function () {
        $this->actingAs($this->user)
            ->postJson('/api/v1/ror-resolve', ['names' => 'not-an-array'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['names']);
    });

    test('validates names array must not be empty', function () {
        $this->actingAs($this->user)
            ->postJson('/api/v1/ror-resolve', ['names' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['names']);
    });

    test('validates names array must not exceed 20 items', function () {
        $names = array_fill(0, 21, 'Institution');

        $this->actingAs($this->user)
            ->postJson('/api/v1/ror-resolve', ['names' => $names])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['names']);
    });

    test('is accessible without authentication', function () {
        $this->postJson('/api/v1/ror-resolve', ['names' => ['Test']])
            ->assertOk();
    });
});
