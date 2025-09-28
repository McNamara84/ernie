<?php

use App\Models\ResourceType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('returns all resource types', function () {
    $typeA = ResourceType::create([
        'name' => 'Type A',
        'slug' => 'type-a',
        'active' => true,
        'elmo_active' => true,
    ]);

    $typeB = ResourceType::create([
        'name' => 'Type B',
        'slug' => 'type-b',
        'active' => false,
        'elmo_active' => false,
    ]);

    $response = $this->getJson('/api/v1/resource-types')->assertOk();

    $response->assertJsonCount(ResourceType::count());
    $response->assertJsonStructure([
        '*' => ['id', 'name', 'slug'],
    ]);
    $response->assertJsonFragment([
        'id' => $typeB->id,
        'name' => 'Type B',
        'slug' => 'type-b',
    ]);
});

