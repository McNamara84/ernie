<?php

use App\Models\ResourceType;
use Database\Seeders\ResourceTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('returns active resource types for Ernie', function () {
    $this->seed(ResourceTypeSeeder::class);
    ResourceType::create(['name' => 'Inactive', 'slug' => 'inactive', 'is_active' => false]);

    $response = $this->getJson('/api/v1/resource-types/ernie')->assertOk();

    $response->assertJsonCount(ResourceType::where('is_active', true)->count());
    $response->assertJsonStructure([
        '*' => ['id', 'name'],
    ]);
    $response->assertJsonMissingPath('0.slug');
});
